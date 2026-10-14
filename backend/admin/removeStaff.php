<?php

/** Soft delete (is_active = 0) so appointment/feedback history stays intact. */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin'], true)) {
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
if ($staffId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid staff member.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM employees WHERE id = ?");
    $stmt->execute([$staffId]);
    $name = $stmt->fetchColumn();
    if (!$name) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Staff member not found.']);
        exit();
    }

    $pdo->prepare('UPDATE employees SET is_active = 0 WHERE id = ?')->execute([$staffId]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "STAFF", ?)')
        ->execute(["{$name} was removed from the roster."]);

    echo json_encode(['success' => true, 'message' => 'Staff member removed.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin removeStaff error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while removing the staff member.']);
}
