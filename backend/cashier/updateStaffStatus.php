<?php

/**
 * Admin-only: Set Shifting Status
 *
 * Previously also reachable by Cashier from the Staff Assignments tab's
 * "Update Shifting Parameters" action -- removed from the Cashier UI.
 * Attendance/shift status is now automatic (see Scheduling::autoCloseAttendance
 * and the Staff Portal's login/logout-driven clock in/out); a Cashier
 * manually flipping "On Duty"/"Off Shift" here would fight that system by
 * writing directly to the same attendance table. Kept for Admin in case of
 * a genuine manual override need, out of scope for this patch.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

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
$allowedStatuses = ['On Duty', 'With Client', 'Off Shift'];

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

    $pdo->prepare('UPDATE employees SET shift_status = ? WHERE id = ?')->execute([$status, $staffId]);

    if ($status === 'On Duty') {
        $stmt = $pdo->prepare('SELECT id FROM attendance WHERE employee_id = ? AND DATE(clock_in_time) = CURDATE() AND clock_out_time IS NULL');
        $stmt->execute([$staffId]);
        if (!$stmt->fetchColumn()) {
            $pdo->prepare('INSERT INTO attendance (employee_id, clock_in_time) VALUES (?, NOW())')->execute([$staffId]);
        }
    } elseif ($status === 'Off Shift') {
        $pdo->prepare('UPDATE attendance SET clock_out_time = NOW() WHERE employee_id = ? AND DATE(clock_in_time) = CURDATE() AND clock_out_time IS NULL')
            ->execute([$staffId]);
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "ATTENDANCE", ?)')
        ->execute(["{$employee['name']} at {$employee['branch_name']} is now {$status}."]);

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
