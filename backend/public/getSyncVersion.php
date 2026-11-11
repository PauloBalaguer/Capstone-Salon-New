<?php

/**
 * Live sync: a change "version" for one branch (or every branch).
 *
 * Every panel (Admin, Cashier, Staff, Customer, guest site) watches this and
 * reloads its own data the moment the version changes, so a Shift In, break,
 * Shift Out, booking, cancellation, reschedule, staff assignment or payment
 * made on one device appears on every other device without a manual refresh.
 *
 * It is not a second status system: the version is only a hash of the rows
 * the panels already read (attendance, attendance_breaks, appointments,
 * appointment_services, home service jobs, schedules, payments), so it moves
 * exactly when that data moves. Each panel still loads the real data from
 * its own endpoint, with its own login and role checks; this file returns
 * nothing but the hash and the server time, so it needs no login and starts
 * no session (a long-lived request must not hold the PHP session lock).
 *
 * GET ?branch=<branch_key>|all
 *   default  -> JSON { success, version, serverNow }   (polling fallback)
 *   stream=1 -> Server-Sent Events: a "version" event now and whenever it
 *               changes, checked every 2 seconds for ~25 seconds; the
 *               browser's EventSource then reconnects by itself.
 *
 * The current minute is part of the hash, so statuses that change with the
 * clock alone (a booking's time window starting/ending, branch closing time)
 * also reach every panel within a minute.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';

sendCorsHeaders();

$branchKey = trim((string) ($_GET['branch'] ?? 'all'));
if ($branchKey === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $branchKey)) {
    $branchKey = 'all';
}
$stream = ($_GET['stream'] ?? '') === '1';

/** Hash of every row that drives staff status, availability and bookings in scope. */
function syncVersion(PDO $pdo, ?int $branchId): array
{
    // ? = branch id, or NULL for all branches.
    $emp = '(? IS NULL OR e.branch_id = ?)';
    $apt = '(? IS NULL OR ap.branch_id = ?)';
    $hs = '(? IS NULL OR h.branch_id = ?)';
    $sql = "SELECT NOW() AS serverNow, CONCAT_WS(':',
        DATE_FORMAT(NOW(), '%Y%m%d%H%i'),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', a.id, a.clock_in_time, a.clock_out_time))), 0))
            FROM attendance a JOIN employees e ON e.id = a.employee_id
            WHERE $emp AND (a.clock_out_time IS NULL OR a.clock_in_time >= CURDATE() - INTERVAL 2 DAY)),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', b.id, b.break_start, b.break_end))), 0))
            FROM attendance_breaks b JOIN attendance a ON a.id = b.attendance_id JOIN employees e ON e.id = a.employee_id
            WHERE $emp AND b.break_start >= CURDATE() - INTERVAL 2 DAY),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', e.id, e.branch_id, e.is_active, e.updated_at))), 0))
            FROM employees e WHERE $emp),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', ss.employee_id, ss.day_of_week, ss.start_time, ss.end_time, ss.is_rest_day))), 0))
            FROM staff_schedules ss JOIN employees e ON e.id = ss.employee_id WHERE $emp),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', sv.employee_id, sv.service_id))), 0))
            FROM staff_services sv JOIN employees e ON e.id = sv.employee_id WHERE $emp),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', ap.id, ap.status, ap.payment_status, ap.employee_id, ap.appointment_datetime, ap.updated_at))), 0))
            FROM appointments ap WHERE $apt
              AND (ap.appointment_datetime >= CURDATE() - INTERVAL 1 DAY OR ap.updated_at >= NOW() - INTERVAL 1 DAY)),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', x.id, x.appointment_id, x.service_id, x.employee_id))), 0))
            FROM appointment_services x JOIN appointments ap ON ap.id = x.appointment_id
            WHERE $apt AND ap.appointment_datetime >= CURDATE() - INTERVAL 1 DAY),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', p.id, p.status, p.amount, p.tip_amount))), 0))
            FROM payments p JOIN appointments ap ON ap.id = p.appointment_id
            WHERE $apt AND ap.appointment_datetime >= CURDATE() - INTERVAL 1 DAY),
        (SELECT CONCAT(COUNT(*), '.', COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', h.id, h.status, h.payment_status, h.employee_id, h.preferred_date, h.preferred_time,
                    (SELECT GROUP_CONCAT(t.employee_id ORDER BY t.employee_id) FROM home_service_staff t WHERE t.home_service_request_id = h.id)))), 0))
            FROM home_service_requests h WHERE $hs AND (h.preferred_date >= CURDATE() - INTERVAL 1 DAY OR h.submitted_at >= NOW() - INTERVAL 1 DAY)),
        (SELECT COALESCE(MAX(n.id), 0) FROM notifications n)
    ) AS raw";
    $params = [];
    foreach ([$emp, $emp, $emp, $emp, $emp, $apt, $apt, $apt, $hs] as $_) {
        $params[] = $branchId;
        $params[] = $branchId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return [
        'version' => substr(sha1((string) $row['raw']), 0, 20),
        'serverNow' => str_replace(' ', 'T', $row['serverNow']) . '+08:00',
    ];
}

try {
    $pdo = Database::getInstance();
    $branchId = null;
    if ($branchKey !== 'all') {
        $stmt = $pdo->prepare('SELECT id FROM branches WHERE branch_key = ? LIMIT 1');
        $stmt->execute([$branchKey]);
        $found = $stmt->fetchColumn();
        $branchId = $found !== false ? (int) $found : null;
    }

    if (!$stream) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true] + syncVersion($pdo, $branchId));
        exit();
    }

    // --- Server-Sent Events ---
    @set_time_limit(40);
    ignore_user_abort(false);
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level() > 0) ob_end_flush();
    header('Content-Type: text/event-stream');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    $send = function (string $text) {
        echo $text;
        @flush();
    };
    $send("retry: 3000\n\n");

    $last = null;
    $started = time();
    $lastBeat = time();
    while (time() - $started < 25) {
        $current = syncVersion($pdo, $branchId);
        if ($current['version'] !== $last) {
            $last = $current['version'];
            $send('event: version' . "\n" . 'data: ' . json_encode($current) . "\n\n");
            $lastBeat = time();
        } elseif (time() - $lastBeat >= 10) {
            $send(": keep-alive\n\n"); // Also lets PHP notice a closed tab.
            $lastBeat = time();
        }
        if (connection_aborted()) break;
        sleep(2);
    }
} catch (PDOException $e) {
    error_log('getSyncVersion error: ' . $e->getMessage());
    if (!$stream) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Live sync is unavailable right now.']);
    }
}
