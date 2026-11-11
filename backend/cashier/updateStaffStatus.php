<?php

/**
 * Admin-only: Set Shifting Status
 *
 * Attendance/shift status is automatic (see Scheduling::autoCloseAttendance
 * and the Staff Portal's login/logout-driven clock in/out); a Cashier
 * manually flipping "On Duty"/"Off Shift" here would fight that system by
 * writing directly to the same attendance table. Kept for Admin in case of
 * a genuine manual override need.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/helpers/StaffShift.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$staffId = (int) ($_POST['id'] ?? 0);
$status = trim($_POST['status'] ?? '');
// Only the attendance itself can be overridden; Busy / On Break are derived
// (StaffShift::liveStatusSql). Accepts the status keys ('available' = clock
// in, 'off_shift' = clock out) and the older names 'On Shift' / 'On Duty' /
// 'Off Shift' from cached pages.
$status = ['On Shift' => 'available', 'On Duty' => 'available', 'Off Shift' => 'off_shift'][$status] ?? $status;
$allowedStatuses = [StaffShift::STATUS_AVAILABLE, StaffShift::STATUS_OFF];

if ($staffId <= 0 || !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid staff member or status.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS name, e.branch_id, br.branch_name
        FROM employees e LEFT JOIN branches br ON br.id = e.branch_id
        WHERE e.id = ?
    ");
    $stmt->execute([$staffId]);
    $employee = $stmt->fetch();
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Staff member not found.']);
        exit();
    }

    // A Cashier can only manage their own branch's staff -- see
    // database/migrations/007_cashier_branch_lock.sql.
    $isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';
    if ($isCashier && (int) $employee['branch_id'] !== (int) ($_SESSION['branch_id'] ?? 0)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Staff member not found.']);
        exit();
    }

    $pdo->beginTransaction();

    // Same lock and open-shift rule as the Staff Portal's shiftAction.php,
    // so this override can't open a second shift or leave a break running.
    $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE')->execute([$staffId]);
    $openShift = StaffShift::openShift($pdo, $staffId, true);
    if ($status === StaffShift::STATUS_OFF) {
        if ($openShift) {
            $pdo->prepare('UPDATE attendance_breaks SET break_end = GREATEST(break_start, NOW()) WHERE attendance_id = ? AND break_end IS NULL')
                ->execute([$openShift['id']]);
            $pdo->prepare('UPDATE attendance SET clock_out_time = GREATEST(clock_in_time, NOW()) WHERE id = ?')->execute([$openShift['id']]);
        }
    } elseif (!$openShift) {
        $pdo->prepare('INSERT INTO attendance (employee_id, clock_in_time) VALUES (?, NOW())')->execute([$staffId]);
    }
    $label = $status === StaffShift::STATUS_OFF ? 'off shift' : 'on shift';

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "ATTENDANCE", ?)')
        ->execute(["{$employee['name']} at {$employee['branch_name']} is now {$label}."]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Shifting status updated.']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('cashier updateStaffStatus error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating shifting status.']);
}
