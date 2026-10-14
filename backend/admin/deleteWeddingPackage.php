<?php

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin', 'Owner'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$packageId = (int) ($_POST['id'] ?? 0);
if ($packageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid wedding package.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT package_name FROM wedding_packages WHERE id = ?');
    $stmt->execute([$packageId]);
    $packageName = $stmt->fetchColumn();
    if (!$packageName) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Wedding package not found.']);
        exit();
    }

    $pdo->prepare('DELETE FROM wedding_packages WHERE id = ?')->execute([$packageId]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PACKAGE", ?)')
        ->execute(["{$packageName} was removed."]);

    echo json_encode(['success' => true, 'message' => 'Wedding package deleted.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin deleteWeddingPackage error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while deleting the wedding package.']);
}
