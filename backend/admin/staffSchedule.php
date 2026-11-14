<?php

/**
 * Admin: one employee's weekly schedule (staff_schedules, migration 058) and
 * date-specific leave (staff_leave, migration 062).
 *
 * GET  ?employeeId=7                         -> { days[7], leave[] }
 * POST action=previewWeek employeeId, days (JSON: [{dayOfWeek, mode: branch|work|off, start, end}])
 * POST action=saveWeek    employeeId, days, confirmed=1 (flags affected bookings like leave)
 * POST action=previewLeave employeeId, startDate, endDate
 * POST action=addLeave    employeeId, startDate, endDate, reason, confirmed=1
 * POST action=deleteLeave leaveId
 * POST action=leaveBookings employeeId, startDate, endDate -> { bookings[] }; each salon one lists
 *      the same-branch stylists free to take it over (replacements)
 * POST action=reassignLeaveBooking employeeId (the absent stylist), reference, staffId
 *
 * Adding leave never moves or cancels a booking: the preview lists the
 * confirmed salon appointments and home visits on those dates; saving flags
 * them for follow-up (needs_followup) and keeps their original details.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/setup/live-updates.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/booking/ScheduleExceptions.php';
require_once '../../backend/shared/config/notifications/CustomerNotifier.php';
require_once '../../backend/shared/config/helpers/Schema.php';

sendCorsHeaders();
LiveUpdates::afterThisRequest(); // Pusher: open panels refresh after this save (setup/live-updates.php)
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin', 'Owner'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

function validDate(string $value): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

try {
    $pdo = Database::getInstance();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $employeeId = (int) ($_GET['employeeId'] ?? 0);
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        if (!$stmt->fetchColumn()) fail(404, 'Staff member not found.');

        $stmt = $pdo->prepare('SELECT day_of_week, start_time, end_time, is_rest_day FROM staff_schedules WHERE employee_id = ?');
        $stmt->execute([$employeeId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) $rows[(int) $row['day_of_week']] = $row;
        $days = [];
        foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $dow => $name) {
            $row = $rows[$dow] ?? null;
            $off = $row && ($row['is_rest_day'] || !$row['start_time'] || !$row['end_time']);
            $days[] = ['dayOfWeek' => $dow, 'dayName' => $name,
                'mode' => !$row ? 'branch' : ($off ? 'off' : 'work'),
                'start' => $row && !$off ? substr($row['start_time'], 0, 5) : '',
                'end' => $row && !$off ? substr($row['end_time'], 0, 5) : ''];
        }
        // Leave needs migration 062 (staff_leave); the weekly schedule
        // (staff_schedules, migration 058) still loads without it.
        $leave = [];
        $leaveReady = true;
        try {
            $stmt = $pdo->prepare('SELECT id, start_date AS startDate, end_date AS endDate, reason FROM staff_leave
                WHERE employee_id = ? AND end_date >= CURDATE() - INTERVAL 30 DAY ORDER BY start_date');
            $stmt->execute([$employeeId]);
            $leave = $stmt->fetchAll();
        } catch (PDOException $e) {
            $leaveReady = false;
        }
        echo json_encode(['success' => true, 'days' => $days, 'leave' => $leave, 'leaveReady' => $leaveReady]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Invalid request method.');
    $action = (string) ($_POST['action'] ?? '');
    $employeeId = (int) ($_POST['employeeId'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();

    if ($action === 'previewWeek' || $action === 'saveWeek') {
        if (!$employee) fail(404, 'Staff member not found.');
        $days = json_decode((string) ($_POST['days'] ?? ''), true);
        if (!is_array($days) || count($days) !== 7) fail(400, 'Please send all seven days.');
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $clean = [];
        foreach ($days as $day) {
            $dow = (int) ($day['dayOfWeek'] ?? 0);
            $mode = (string) ($day['mode'] ?? '');
            $start = (string) ($day['start'] ?? '');
            $end = (string) ($day['end'] ?? '');
            if ($dow < 1 || $dow > 7 || isset($clean[$dow]) || !in_array($mode, ['branch', 'work', 'off'], true)) fail(400, 'Invalid day in the weekly schedule.');
            if ($mode === 'work' && (!preg_match($time, $start) || !preg_match($time, $end) || $start >= $end)) {
                fail(400, 'Each working day needs a start time before its end time (HH:MM).');
            }
            $clean[$dow] = ['dayOfWeek' => $dow, 'mode' => $mode, 'start' => $start, 'end' => $end];
        }

        // Same as leave: list upcoming confirmed bookings that no longer fit,
        // then (on save) flag them for follow-up without changing them.
        if ($action === 'previewWeek') {
            $affected = ScheduleExceptions::affectedBySchedule($pdo, $employeeId, array_values($clean));
            echo json_encode(['success' => true, 'affected' => ScheduleExceptions::publicRows($affected)]);
            exit();
        }
        $pdo->beginTransaction();
        // Serialize with bookings for this stylist (Scheduling locks the same row).
        $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE')->execute([$employeeId]);
        $affected = ScheduleExceptions::affectedBySchedule($pdo, $employeeId, array_values($clean));
        if ($affected && !filter_var($_POST['confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'needsConfirmation' => true, 'affected' => ScheduleExceptions::publicRows($affected),
                'message' => 'Review the affected bookings before saving this schedule.']);
            exit();
        }
        $delete = $pdo->prepare('DELETE FROM staff_schedules WHERE employee_id = ? AND day_of_week = ?');
        $upsert = $pdo->prepare('INSERT INTO staff_schedules (employee_id, day_of_week, start_time, end_time, is_rest_day) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), is_rest_day = VALUES(is_rest_day)');
        foreach ($clean as $dow => $day) {
            if ($day['mode'] === 'branch') $delete->execute([$employeeId, $dow]);
            elseif ($day['mode'] === 'off') $upsert->execute([$employeeId, $dow, null, null, 1]);
            else $upsert->execute([$employeeId, $dow, $day['start'] . ':00', $day['end'] . ':00', 0]);
        }
        ScheduleExceptions::flag($pdo, $affected, "{$employee['name']}'s weekly schedule changed - contact the customer");
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "SETTINGS", ?)')
            ->execute(["Weekly schedule updated for {$employee['name']}."]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Weekly schedule saved.' . ($affected ? ' ' . count($affected) . ' booking(s) flagged for follow-up.' : ' No confirmed bookings were affected.')]);
        exit();
    }

    if ($action === 'previewLeave' || $action === 'addLeave') {
        if (!$employee) fail(404, 'Staff member not found.');
        $from = trim((string) ($_POST['startDate'] ?? ''));
        $to = trim((string) ($_POST['endDate'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!validDate($from) || !validDate($to) || $to < $from) fail(400, 'Please choose a valid start and end date.');
        if (mb_strlen($reason) > 255) fail(400, 'Please keep the reason under 255 characters.');

        if ($action === 'previewLeave') {
            $affected = ScheduleExceptions::affectedByLeave($pdo, $employeeId, $from, $to);
            echo json_encode(['success' => true, 'affected' => ScheduleExceptions::publicRows($affected)]);
            exit();
        }
        $pdo->beginTransaction();
        // Serialize with bookings for this stylist (Scheduling locks the same row).
        $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE')->execute([$employeeId]);
        $affected = ScheduleExceptions::affectedByLeave($pdo, $employeeId, $from, $to);
        if ($affected && !filter_var($_POST['confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'needsConfirmation' => true, 'affected' => ScheduleExceptions::publicRows($affected),
                'message' => 'Review the affected bookings before saving this leave.']);
            exit();
        }
        $pdo->prepare('INSERT INTO staff_leave (employee_id, start_date, end_date, reason, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$employeeId, $from, $to, $reason !== '' ? $reason : null, $_SESSION['user_id'] ?? null]);
        ScheduleExceptions::flag($pdo, $affected, "{$employee['name']} on leave {$from}" . ($to !== $from ? " to {$to}" : '') . ' - contact the customer');
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Leave saved.' . ($affected ? ' ' . count($affected) . ' booking(s) flagged for follow-up.' : '')]);
        exit();
    }

    // Absent stylist: their bookings on the leave dates, each with who can take it over.
    // Cancelling one uses the salon-cancel flow (refund or reschedule) in Admin > Bookings.
    if ($action === 'leaveBookings') {
        if (!$employee) fail(404, 'Staff member not found.');
        $from = trim((string) ($_POST['startDate'] ?? ''));
        $to = trim((string) ($_POST['endDate'] ?? ''));
        if (!validDate($from) || !validDate($to) || $to < $from) fail(400, 'Please choose a valid start and end date.');
        $pdo->beginTransaction(); // replacements() tries each stylist and undoes it
        $bookings = ScheduleExceptions::affectedByLeave($pdo, $employeeId, $from, $to);
        $load = $pdo->prepare('SELECT * FROM appointments WHERE id = ?');
        foreach ($bookings as &$b) {
            if ($b['kind'] !== 'salon') continue;
            $load->execute([$b['internalId']]);
            $b['replacements'] = ScheduleExceptions::replacements($pdo, $load->fetch(), $employeeId);
        }
        unset($b);
        $pdo->rollBack();
        echo json_encode(['success' => true, 'bookings' => ScheduleExceptions::publicRows($bookings)]);
        exit();
    }

    if ($action === 'reassignLeaveBooking') {
        if (!$employee) fail(404, 'Staff member not found.');
        $reference = trim((string) ($_POST['reference'] ?? ''));
        $newId = (int) ($_POST['staffId'] ?? 0);
        if ($reference === '' || $newId <= 0 || $newId === $employeeId) fail(400, 'Choose another stylist for this booking.');
        $stmt = $pdo->prepare('SELECT id FROM appointments WHERE reference_code = ?');
        $stmt->execute([$reference]);
        $appointmentId = (int) $stmt->fetchColumn();
        if (!$appointmentId) fail(404, 'Booking not found.');

        $pdo->beginTransaction();
        $appointment = Scheduling::lockAppointment($pdo, $appointmentId);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointment_services WHERE appointment_id = ? AND employee_id = ?');
        $stmt->execute([$appointmentId, $employeeId]);
        if ((int) $appointment['employee_id'] !== $employeeId && !(int) $stmt->fetchColumn()) {
            throw new RuntimeException("{$employee['name']} is no longer on this booking. Refresh the list.");
        }
        if (!in_array($appointment['status'], ScheduleExceptions::LEAVE_STATUSES, true)) {
            throw new RuntimeException("This booking is {$appointment['status']} and can't be reassigned here.");
        }
        $oldMain = (int) $appointment['employee_id'];
        $appointment = ScheduleExceptions::moveParts($pdo, $appointment, $employeeId, $newId);
        $stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM employees WHERE id = ?");
        $stmt->execute([$newId]);
        $newName = (string) $stmt->fetchColumn();

        if (Schema::hasColumn($pdo, 'appointments', 'needs_followup')) {
            $pdo->prepare('UPDATE appointments SET needs_followup = NULL WHERE id = ?')->execute([$appointmentId]);
        }
        if ($oldMain === $employeeId) {
            Scheduling::notifyStaffOfReassignment($pdo, $appointment, $employeeId, $newId);
        } else {
            StaffNotifier::notifyOnce($pdo, $newId, 'APPOINTMENT_ASSIGNED', "You've been assigned to part of appointment {$reference} on "
                . date('D, M j, Y \a\t g:i A', strtotime($appointment['appointment_datetime'])) . '.');
            StaffNotifier::notifyOnce($pdo, $employeeId, 'SCHEDULE_CHANGED', "You're no longer on appointment {$reference}; it was given to {$newName}.");
        }
        CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'STYLIST_CHANGED',
            "Your stylist for appointment {$reference} is unavailable that day, so {$newName} will take care of you instead. Your date, time and any payment stay the same."
            . "\n\nIf you'd prefer another time, you can reschedule from My Appointments (or Track a Booking) or contact the branch.", $reference);
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
            ->execute(["{$reference} moved from {$employee['name']} (absent) to {$newName}."]);
        AuditLog::record($pdo, 'booking/reassign_absent', 'Reassigned from an absent stylist', $reference, "{$employee['name']} -> {$newName}");
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "{$reference} is now with {$newName}. The customer was told."]);
        exit();
    }

    if ($action === 'deleteLeave') {
        $leaveId = (int) ($_POST['leaveId'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM staff_leave WHERE id = ?');
        $stmt->execute([$leaveId]);
        if (!$stmt->rowCount()) fail(404, 'Leave not found.');
        echo json_encode(['success' => true, 'message' => 'Leave removed.']);
        exit();
    }

    fail(400, 'Unknown action.');
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail(400, $e->getMessage());
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) fail(409, 'Someone else is changing this booking. Please try again.');
    http_response_code(500);
    error_log('admin staffSchedule error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Has migration 062 been applied?']);
} catch (RuntimeException $e) {
    // moveParts() / booking checks: the chosen stylist can't take it, or the booking changed.
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail(409, $e->getMessage());
}
