<?php

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

$serviceId = (int) ($_POST['id'] ?? 0);
if ($serviceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid service.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT service_name, is_active FROM services WHERE id = ?');
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch();
    if (!$service) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Service not found.']);
        exit();
    }

    $newActive = $service['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE services SET is_active = ? WHERE id = ?')->execute([$newActive, $serviceId]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "SERVICE", ?)')
        ->execute(["{$service['service_name']} is now " . ($newActive ? 'active' : 'inactive') . '.']);

    echo json_encode(['success' => true, 'message' => 'Service updated.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin toggleService error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the service.']);
}
