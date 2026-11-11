<?php

/**
 * Customer: Mark My Notifications as Read
 *
 * With no "ids" field, marks every unread notification belonging to the
 * logged-in customer's own account as read (Mark all as read). With "ids"
 * (a comma-separated list of notification ids), marks only those rows --
 * used when a single notification card is clicked. Scoped to the session's user_id -- a customer can never
 * touch another user's notifications.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to view your notifications.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $rawIds = trim((string) ($_POST['ids'] ?? ''));
    if ($rawIds === '') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
            ->execute([$_SESSION['user_id']]);
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $rawIds)), fn ($id) => $id > 0)));
        if (!$ids || count($ids) > 20) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
            exit();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // user_id in the WHERE clause keeps a customer from touching anyone else's rows.
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($placeholders)")
            ->execute(array_merge([$_SESSION['user_id']], $ids));
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('markNotificationsRead error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating notifications.']);
}
