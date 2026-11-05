<?php

/**
 * Staff Shift In / Start Break / End Break / Shift Out.
 *
 * POST action = shift_in | break_start | break_end | shift_out
 *
 * Only the logged-in Staff account can act, only on its own attendance, and
 * every time is the server's NOW() (never the browser clock). Each action
 * locks the employee row first, so a double tap or two open tabs can't create
 * a second shift, a second break, or a second Shift Out. Shift Out never
 * changes a booking: a service still In Progress has to be finished first.
 * Signing out of the portal (backend/auth/logout.php) does not end a shift.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/security/Csrf.php';
require_once '../../backend/shared/config/helpers/StaffShift.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

function shiftFail(int $code, string $message): void
{
    global $pdo;
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

try {
    $pdo = Database::getInstance();
    $employee = StaffShift::requireEmployee($pdo);
    Csrf::requireValid();

    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['shift_in', 'break_start', 'break_end', 'shift_out'], true)) {
        shiftFail(400, 'Unknown shift action.');
    }
    $employeeId = $employee['id'];

    if (!(int) $employee['is_active'] || !(int) $employee['user_active']) {
        shiftFail(403, 'Your staff account is inactive. Please contact the admin.');
    }

    // Settle a shift left open past the branch's closing time first, the
    // same existing rule login and the dashboard apply.
    Scheduling::autoCloseAttendance($pdo, $employeeId, $employee['branch_id']);

    $pdo->beginTransaction();
    // Serialises every shift action of this employee.
    $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE')->execute([$employeeId]);
    $shift = StaffShift::openShift($pdo, $employeeId, true);

    switch ($action) {
        case 'shift_in':
            if ($shift) {
                shiftFail(409, 'You are already on shift since ' . date('g:i A', strtotime($shift['clock_in_time'])) . '.');
            }
            if (!$employee['branch_id']) {
                shiftFail(409, 'You have no branch assigned yet. Please contact the admin.');
            }
            if (!(int) $employee['branch_active']) {
                shiftFail(409, 'Your assigned branch is not active.');
            }
            if (!Scheduling::canStartShift($pdo, $employeeId, $employee['branch_id'])) {
                shiftFail(409, 'Your branch has already closed for today, so a new shift can\'t start.');
            }
            StaffShift::closeDanglingBreaks($pdo, $employeeId);
            $pdo->prepare('INSERT INTO attendance (employee_id, clock_in_time) VALUES (?, NOW())')->execute([$employeeId]);
            $message = 'Shift started. Have a great day!';
            break;

        case 'break_start':
            if (!$shift) shiftFail(409, 'Shift In first before starting a break.');
            if (StaffShift::openBreak($pdo, (int) $shift['id'], true)) shiftFail(409, 'You are already on a break.');
            $pdo->prepare('INSERT INTO attendance_breaks (attendance_id, break_start) VALUES (?, NOW())')->execute([$shift['id']]);
            $message = 'Break started. Your break time is not counted as work hours.';
            break;

        case 'break_end':
            if (!$shift) shiftFail(409, 'You are not on shift.');
            $break = StaffShift::openBreak($pdo, (int) $shift['id'], true);
            if (!$break) shiftFail(409, 'You are not on a break.');
            $pdo->prepare('UPDATE attendance_breaks SET break_end = GREATEST(break_start, NOW()) WHERE id = ?')->execute([$break['id']]);
            $message = 'Welcome back! You are on shift again.';
            break;

        case 'shift_out':
            if (!$shift) shiftFail(409, 'You have no active shift to end.');
            $inProgress = StaffShift::servicesInProgress($pdo, $employeeId);
            if ($inProgress) {
                shiftFail(409, 'Finish the service in progress first (' . implode(', ', $inProgress) . '). Shift Out doesn\'t complete bookings.');
            }
            // A running break ends at the same moment as the shift.
            $pdo->prepare('UPDATE attendance_breaks SET break_end = GREATEST(break_start, NOW()) WHERE attendance_id = ? AND break_end IS NULL')
                ->execute([$shift['id']]);
            $pdo->prepare('UPDATE attendance SET clock_out_time = GREATEST(clock_in_time, NOW()) WHERE id = ? AND clock_out_time IS NULL')
                ->execute([$shift['id']]);
            $message = 'Shift ended. Thank you for today!';
            break;
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => $message,
        'shift' => StaffShift::state($pdo, $employee),
    ]);
} catch (PDOException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('staff shiftAction error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating your shift. Please try again.']);
}
