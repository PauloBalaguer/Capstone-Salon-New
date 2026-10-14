<?php

/**
 * Admin: Delete Service
 *
 * services.id is referenced with ON DELETE RESTRICT from both
 * staff_services and appointment_services, so a service that's ever been
 * booked or assigned to a staff specialty can't be hard-deleted -- that's
 * caught as a foreign-key violation (SQLSTATE 23000) and turned into a
 * message pointing the admin at deactivation instead.
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

$serviceId = (int) ($_POST['id'] ?? 0);
if ($serviceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid service.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT service_name FROM services WHERE id = ?');
    $stmt->execute([$serviceId]);
    $name = $stmt->fetchColumn();
    if (!$name) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Service not found.']);
        exit();
    }

    try {
        $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$serviceId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "{$name} is linked to staff specialties or past bookings and can't be deleted. Deactivate it instead."]);
            exit();
        }
        throw $e;
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "SERVICE", ?)')
        ->execute(["{$name} was removed from the service menu."]);

    echo json_encode(['success' => true, 'message' => 'Service deleted.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin deleteService error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while deleting the service.']);
}
