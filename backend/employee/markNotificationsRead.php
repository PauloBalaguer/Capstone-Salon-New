<?php

/**
 * Staff: Mark My Notifications as Read
 *
 * Mirrors backend/customer/markNotificationsRead.php -- scoped to the
 * session's own user_id, Staff role only.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Staff') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a staff member.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
        ->execute([$_SESSION['user_id']]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('employee markNotificationsRead error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating notifications.']);
}
