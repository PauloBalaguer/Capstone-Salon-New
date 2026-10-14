<?php

/**
 * Public: Get Branch Services
 *
 * No login required — powers the "Quick Guest Booking" service checkboxes
 * on the public landing page.
 */

require_once '../config/cors.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

$branchId = (int) ($_GET['branch'] ?? 0);

if ($branchId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid branch is required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('
        SELECT id, service_name AS name, category, duration_label AS duration, duration_minutes AS durationMinutes, price, payment_requirement AS paymentRequirement
        FROM services
        WHERE branch_id = ? AND is_active = 1
        ORDER BY category, service_name
    ');
    $stmt->execute([$branchId]);

    $services = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['price'] = (float) $row['price'];
        $row['durationMinutes'] = (int) $row['durationMinutes'];
        return $row;
    }, $stmt->fetchAll());

    echo json_encode(['success' => true, 'services' => $services]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getBranchServices error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading services.']);
}
