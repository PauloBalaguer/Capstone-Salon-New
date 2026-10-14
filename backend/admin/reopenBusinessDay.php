<?php

/**
 * Admin-only: Reopen a Closed Business Day
 *
 * The counterpart to backend/cashier/closeEod.php's lock -- only an
 * Admin/Owner may lift it (see EodLock::isDateLocked, checked by every
 * Cashier write endpoint that touches a date-scoped record).
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

$closureId = (int) ($_POST['id'] ?? 0);
if ($closureId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A closure record is required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id FROM daily_closures WHERE id = ? AND is_reopened = 0');
    $stmt->execute([$closureId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Closed business day not found (or already reopened).']);
        exit();
    }

    $pdo->prepare('UPDATE daily_closures SET is_reopened = 1, reopened_by = ?, reopened_at = NOW() WHERE id = ?')
        ->execute([$_SESSION['user_id'], $closureId]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "EOD", ?)')
        ->execute(['A closed business day was reopened by an administrator.']);

    echo json_encode(['success' => true, 'message' => 'Business day reopened.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin reopenBusinessDay error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while reopening the business day.']);
}
