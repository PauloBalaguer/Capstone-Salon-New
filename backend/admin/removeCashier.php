<?php

/**
 * Soft delete (users.is_active = 0), which also revokes login since
 * backend/auth/login.php blocks any account where is_active is false.
 * Payment/adjustment history stays intact (processed_by/adjusted_by are
 * ON DELETE SET NULL, and we never hard-delete the row anyway).
 */

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

$cashierId = (int) ($_POST['id'] ?? 0);
if ($cashierId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid cashier account.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT u.display_name, u.is_protected FROM users u JOIN roles r ON u.role_id = r.id
        WHERE u.id = ? AND r.role_name = 'Cashier' AND u.is_active = 1
    ");
    $stmt->execute([$cashierId]);
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Cashier account not found.']);
        exit();
    }
    if ((int) $row['is_protected'] === 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This is a permanent account and cannot be removed.']);
        exit();
    }
    $name = $row['display_name'] ?: 'A cashier account';

    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$cashierId]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "STAFF", ?)')
        ->execute(["{$name} was removed."]);

    echo json_encode(['success' => true, 'message' => 'Cashier account removed.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin removeCashier error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while removing the cashier account.']);
}
