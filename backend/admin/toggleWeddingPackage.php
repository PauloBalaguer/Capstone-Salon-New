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

    $stmt = $pdo->prepare('SELECT package_name, is_active FROM wedding_packages WHERE id = ?');
    $stmt->execute([$packageId]);
    $package = $stmt->fetch();
    if (!$package) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Wedding package not found.']);
        exit();
    }

    $newActive = $package['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE wedding_packages SET is_active = ? WHERE id = ?')->execute([$newActive, $packageId]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PACKAGE", ?)')
        ->execute(["{$package['package_name']} is now " . ($newActive ? 'active' : 'inactive') . '.']);

    echo json_encode(['success' => true, 'message' => 'Wedding package updated.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin toggleWeddingPackage error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the wedding package.']);
}
