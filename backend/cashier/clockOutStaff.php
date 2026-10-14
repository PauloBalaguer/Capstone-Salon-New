<?php

/**
 * Cashier: Clock Out a Staff Member
 *
 * For a stylist who left without signing out (or left early): closes their
 * open attendance row at the time they actually left. The time is optional
 * (HH:MM, 24h, same day as the clock-in); it defaults to now, must be after
 * the clock-in, and can't be in the future. The row is tagged with who
 * clocked them out so the hours are traceable. Only staff at the cashier's
 * own branch.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$staffId = (int) ($_POST['staffId'] ?? 0);
$time = trim($_POST['time'] ?? '');
if ($staffId <= 0 || ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a staff member and a valid time.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $sql = "SELECT a.id, a.clock_in_time, DATE(a.clock_in_time) AS work_date, CONCAT(e.first_name, ' ', e.last_name) AS name
            FROM attendance a JOIN employees e ON e.id = a.employee_id
            WHERE a.employee_id = ? AND a.clock_out_time IS NULL";
    $params = [$staffId];
    if (($_SESSION['user_role'] ?? '') === 'Cashier') {
        $sql .= ' AND e.branch_id = ?';
        $params[] = (int) ($_SESSION['branch_id'] ?? 0);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY a.clock_in_time DESC LIMIT 1');
    $stmt->execute($params);
    $shift = $stmt->fetch();
    if (!$shift) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'This staff member is not currently clocked in at your branch.']);
        exit();
    }

    // MySQL's clock stamped the clock-in, so compare against its NOW().
    $now = $pdo->query('SELECT NOW()')->fetchColumn();
    $clockOut = $time === '' ? $now : $shift['work_date'] . ' ' . $time . ':00';
    if ($clockOut <= $shift['clock_in_time']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'The clock-out time must be after they clocked in (' . date('g:i A', strtotime($shift['clock_in_time'])) . ').']);
        exit();
    }
    if ($clockOut > $now) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'The clock-out time can\'t be in the future.']);
        exit();
    }

    $by = ($_SESSION['user_name'] ?? '') ?: 'the cashier';
    $pdo->prepare('UPDATE attendance SET clock_out_time = ?, notes = ? WHERE id = ?')
        ->execute([$clockOut, "Clocked out by {$by} — staff did not sign out", $shift['id']]);
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "ATTENDANCE", ?)')
        ->execute(["{$shift['name']} clocked out by {$by} at " . date('g:i A', strtotime($clockOut)) . '.']);

    echo json_encode(['success' => true, 'message' => "{$shift['name']} clocked out at " . date('g:i A', strtotime($clockOut)) . '.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier clockOutStaff error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while clocking out.']);
}
