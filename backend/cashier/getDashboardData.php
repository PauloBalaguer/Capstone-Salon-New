<?php

/**
 * Cashier Hub Data API Endpoint
 *
 * Every Cashier account is locked to exactly one branch (users.branch_id,
 * stored in the session at login by backend/auth/login.php -- see
 * database/migrations/007_cashier_branch_lock.sql). This
 * endpoint enforces that lock server-side: a Cashier only ever receives
 * their own branch's bookings/staff/inventory/services/EOD snapshot, never
 * the other branches', regardless of what the old client-side branch
 * switcher used to let them view. Admin accounts have no branch_id
 * and default to viewing the first active branch (Daraga) -- they have
 * their own cross-branch view in pages/admin/*, so this page doesn't need
 * to support switching for them either.
 *
 * scripts/cashier/cashier-common.js calls this once on load and again after
 * every mutation.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/Scheduling.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier to view this page.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->query('SELECT id, branch_key AS branchKey, branch_name AS name, location, branch_type AS type FROM branches WHERE is_active = 1 ORDER BY id');
    $branches = $stmt->fetchAll();
    if (!$branches) {
        throw new RuntimeException('No active branches configured.');
    }

    $myBranchId = $_SESSION['branch_id'] ?? null;
    $myBranch = null;
    foreach ($branches as $branch) {
        if ((int) $branch['id'] === (int) $myBranchId) {
            $myBranch = $branch;
            break;
        }
    }
    if (!$myBranch) {
        $myBranch = $branches[0]; // Admin (no branch_id): default to the first branch.
    }
    $myBranchKey = $myBranch['branchKey'];

    // Settle forgotten sign-outs so the staff list never shows someone on
    // shift after closing time (see Scheduling::autoCloseBranchAttendance).
    Scheduling::autoCloseBranchAttendance($pdo, (int) $myBranch['id']);

    // --- Bookings (this branch's appointments only; the frontend slices this
    // into the live terminal queue vs. the appointments pipeline tab) ---
    $stmt = $pdo->prepare("
        SELECT
            a.reference_code AS id,
            br.branch_key AS branchId,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            c.phone_number AS clientPhone,
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS serviceName,
            a.employee_id AS staffId,
            COALESCE((SELECT GROUP_CONCAT(DISTINCT CONCAT(se.first_name, ' ', se.last_name) ORDER BY sx.id SEPARATOR ', ') FROM appointment_services sx JOIN employees se ON se.id = COALESCE(sx.employee_id, a.employee_id) WHERE sx.appointment_id = a.id), CONCAT(e.first_name, ' ', e.last_name)) AS staffName,
            DATE_FORMAT(a.appointment_datetime, '%Y-%m-%d') AS date,
            DATE_FORMAT(a.appointment_datetime, '%h:%i %p') AS time,
            a.appointment_datetime AS startDateTime,
            COALESCE(SUM(s.duration_minutes), 0) AS durationMinutes,
            a.status AS status,
            a.payment_status AS paymentStatus,
            a.total_price AS price,
            a.preferred_payment_method AS paymentMethod,
            a.reservation_requirement AS paymentRequirement,
            a.reservation_amount_due AS requiredAmount,
            DATE_FORMAT(a.created_at, '%Y-%m-%d %h:%i %p') AS submittedAt,
            a.deposit_amount AS depositAmount,
            a.deposit_method AS depositMethod,
            a.deposit_reference AS depositReference,
            a.deposit_paid AS depositPaid,
            a.deposit_recorded_by IS NOT NULL AS depositVerified,
            EXISTS (
                SELECT 1 FROM appointments a2
                WHERE a2.branch_id = a.branch_id AND a2.employee_id = a.employee_id
                  AND a2.appointment_datetime = a.appointment_datetime
                  AND a2.status = 'Confirmed' AND a2.id != a.id AND a.employee_id IS NOT NULL
            ) AS hasConflict
        FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        LEFT JOIN branches br ON br.id = a.branch_id
        LEFT JOIN employees e ON e.id = a.employee_id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services s ON s.id = aps.service_id
        WHERE a.branch_id = ?
        GROUP BY a.id
        ORDER BY a.appointment_datetime ASC
    ");
    $stmt->execute([$myBranch['id']]);
    $bookings = array_map(function ($row) {
        $row['staffId'] = $row['staffId'] !== null ? (string) $row['staffId'] : null;
        $row['price'] = (float) $row['price'];
        $row['depositAmount'] = $row['depositAmount'] !== null ? (float) $row['depositAmount'] : null;
        $row['requiredAmount'] = $row['requiredAmount'] !== null ? (float) $row['requiredAmount'] : null;
        $row['depositPaid'] = (bool) $row['depositPaid'];
        $row['depositVerified'] = (bool) $row['depositVerified'];
        $row['hasConflict'] = (bool) $row['hasConflict'];
        $row['durationMinutes'] = (int) $row['durationMinutes'];
        $endTime = new DateTime($row['startDateTime']);
        $endTime->modify('+' . $row['durationMinutes'] . ' minutes');
        $row['endTime'] = $endTime->format('h:i A');
        unset($row['startDateTime']);
        return $row;
    }, $stmt->fetchAll());

    // --- Staff (rating/completedCount/attendance computed like the admin
    // panel; tips are cashier-specific). Availability ("On Shift"/"Busy
    // until"/"Off Shift") is derived from real attendance + today's
    // appointments, not a cashier-editable status field -- see
    // backend/cashier/updateStaffStatus.php (now Admin-only) and
    // Scheduling::autoCloseAttendance for why shift_status is no longer the
    // source of truth here. -- this branch only ---
    $stmt = $pdo->prepare("
        SELECT
            e.id,
            CONCAT(e.first_name, ' ', e.last_name) AS name,
            br.branch_key AS branchId,
            e.position AS role,
            (SELECT COUNT(*) FROM appointments ap WHERE ap.employee_id = e.id AND ap.status = 'Completed') AS completedCount,
            (SELECT ROUND(AVG(f.rating), 1) FROM feedback f
                JOIN appointments ap2 ON ap2.id = f.appointment_id
                WHERE ap2.employee_id = e.id) AS avgRating,
            (SELECT COUNT(DISTINCT DATE(at2.clock_in_time)) FROM attendance at2
                WHERE at2.employee_id = e.id AND at2.clock_in_time >= (NOW() - INTERVAL 30 DAY)) AS attendanceDays,
            (SELECT COALESCE(SUM(p.tip_amount), 0) FROM payments p
                JOIN appointments ap3 ON ap3.id = p.appointment_id
                WHERE ap3.employee_id = e.id AND p.status = 'Paid') AS totalTips,
            (SELECT GROUP_CONCAT(sv.service_name ORDER BY sv.id SEPARATOR ', ') FROM staff_services ss
                JOIN services sv ON sv.id = ss.service_id WHERE ss.employee_id = e.id) AS specialties,
            EXISTS (
                SELECT 1 FROM attendance att WHERE att.employee_id = e.id
                AND DATE(att.clock_in_time) = CURDATE() AND att.clock_out_time IS NULL
            ) AS onShift,
            (SELECT DATE_FORMAT(MAX(att2.clock_in_time), '%h:%i %p') FROM attendance att2 WHERE att2.employee_id = e.id
                AND DATE(att2.clock_in_time) = CURDATE() AND att2.clock_out_time IS NULL) AS clockInTime
        FROM employees e
        LEFT JOIN branches br ON br.id = e.branch_id
        WHERE e.is_active = 1 AND e.branch_id = ?
        ORDER BY e.id
    ");
    $stmt->execute([$myBranch['id']]);
    $staffRows = $stmt->fetchAll();

    // Today's workload / next appointment / "busy until" all come from this
    // branch's own bookings list (already fetched above), not a second
    // round-trip -- one pass groups them per staff member.
    // MySQL's own clock, not PHP's -- they can run in different timezones
    // in this environment, and every appointment_datetime/clock_in_time was
    // stamped via MySQL's NOW(), so only its own NOW() reliably agrees.
    $mysqlNow = $pdo->query('SELECT NOW()')->fetchColumn();
    $now = new DateTime($mysqlNow);
    $today = $now->format('Y-m-d');
    $byStaff = [];
    foreach ($bookings as $b) {
        if (!$b['staffId']) continue;
        $byStaff[$b['staffId']][] = $b;
    }

    $staffList = array_map(function ($row) use ($byStaff, $today, $now) {
        $staffId = (string) $row['id'];
        $theirBookings = $byStaff[$staffId] ?? [];
        $todaysWorkload = 0;
        $busyUntil = null;
        $nextAppointment = null;
        foreach ($theirBookings as $b) {
            if ($b['date'] === $today && in_array($b['status'], ['Confirmed', 'In Progress'], true)) {
                $todaysWorkload++;
                $start = DateTime::createFromFormat('Y-m-d h:i A', $b['date'] . ' ' . $b['time']);
                $end = DateTime::createFromFormat('Y-m-d h:i A', $b['date'] . ' ' . $b['endTime']);
                if ($start && $end && $start <= $now && $now < $end) {
                    $busyUntil = $b['endTime'];
                }
                if ($start && $start > $now && ($nextAppointment === null || $start < $nextAppointment['dt'])) {
                    $nextAppointment = ['dt' => $start, 'label' => $b['date'] . ' ' . $b['time']];
                }
            } elseif ($b['status'] === 'Confirmed') {
                $start = DateTime::createFromFormat('Y-m-d h:i A', $b['date'] . ' ' . $b['time']);
                if ($start && $start > $now && ($nextAppointment === null || $start < $nextAppointment['dt'])) {
                    $nextAppointment = ['dt' => $start, 'label' => $b['date'] . ' ' . $b['time']];
                }
            }
        }

        $onShift = (bool) $row['onShift'];
        $availability = !$onShift ? 'Off Shift' : ($busyUntil ? "Busy until {$busyUntil}" : 'Available');

        return [
            'id' => $staffId,
            'name' => $row['name'],
            'branchId' => $row['branchId'],
            'role' => $row['role'],
            'onShift' => $onShift,
            'clockInTime' => $row['clockInTime'],
            'availability' => $availability,
            'todaysWorkload' => $todaysWorkload,
            'nextAppointment' => $nextAppointment ? $nextAppointment['label'] : null,
            'completedCount' => (int) $row['completedCount'],
            'rating' => $row['avgRating'] !== null ? (float) $row['avgRating'] : 0,
            'attendance' => min(100, (int) round(((int) $row['attendanceDays'] / 30) * 100)),
            'totalTips' => (float) $row['totalTips'],
            'specialties' => $row['specialties'] ?: 'No specialties set',
        ];
    }, $staffRows);

    $stmt = $pdo->prepare("
        SELECT i.id, i.product_name AS name, i.category, br.branch_key AS branchId,
               i.quantity_on_hand AS stock, i.max_stock AS maxStock, i.sale_price AS price
        FROM inventory i
        LEFT JOIN branches br ON br.id = i.branch_id
        WHERE i.branch_id = ? AND i.is_active = 1
        ORDER BY i.id
    ");
    $stmt->execute([$myBranch['id']]);
    $inventory = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['category'] = $row['category'] ?: 'Uncategorized';
        $row['stock'] = (int) $row['stock'];
        $row['maxStock'] = (int) $row['maxStock'];
        $row['price'] = (float) $row['price'];
        return $row;
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare("
        SELECT sv.id, sv.service_name AS name, sv.price
        FROM services sv
        WHERE sv.is_active = 1 AND sv.branch_id = ?
        ORDER BY sv.id
    ");
    $stmt->execute([$myBranch['id']]);
    $servicesByBranch = [
        $myBranchKey => array_map(function ($row) {
            $row['id'] = (string) $row['id'];
            $row['price'] = (float) $row['price'];
            return $row;
        }, $stmt->fetchAll()),
    ];

    $stmt = $pdo->prepare("
        SELECT id, type, message, DATE_FORMAT(created_at, '%Y-%m-%d %h:%i %p') AS time
        FROM notifications
        WHERE user_id IS NULL OR user_id = ?
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$userId]);
    $notifications = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        return $row;
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare("
        SELECT
            COUNT(p.id) AS invoiceCount,
            COALESCE(SUM(p.amount), 0) AS grossRevenue
        FROM payments p
        JOIN appointments a ON a.id = p.appointment_id
        WHERE a.branch_id = ? AND p.status = 'Paid' AND DATE(p.payment_date) = CURDATE()
    ");
    $stmt->execute([$myBranch['id']]);
    $eodRow = $stmt->fetch();
    $eodByBranch = [
        $myBranchKey => [
            'invoiceCount' => (int) $eodRow['invoiceCount'],
            'grossRevenue' => (float) $eodRow['grossRevenue'],
        ],
    ];

    $cashierProfile = [
        'name' => $_SESSION['user_name'] ?? 'Cashier',
        'email' => $_SESSION['user_email'] ?? null,
        'role' => $_SESSION['user_role'],
    ];

    echo json_encode([
        'success' => true,
        'branches' => $branches,
        'myBranch' => ['key' => $myBranchKey, 'name' => $myBranch['name']],
        'bookings' => $bookings,
        'staffList' => $staffList,
        'inventory' => $inventory,
        'servicesByBranch' => $servicesByBranch,
        'notifications' => $notifications,
        'eodByBranch' => $eodByBranch,
        'cashierProfile' => $cashierProfile,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier getDashboardData error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading the dashboard.']);
} catch (RuntimeException $e) {
    http_response_code(500);
    error_log('cashier getDashboardData error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading the dashboard.']);
}
