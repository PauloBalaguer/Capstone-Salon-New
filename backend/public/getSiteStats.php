<?php
require_once __DIR__ . '/../shared/config/setup/cors.php';
require_once __DIR__ . '/../shared/config/setup/database.php';

sendCorsHeaders();
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $pdo = Database::getInstance();
    $services = (int) $pdo->query('SELECT COUNT(DISTINCT TRIM(service_name)) FROM services WHERE is_active = 1')->fetchColumn();
    $professionals = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE is_active = 1 AND branch_id IS NOT NULL AND position IN ('Hair Stylist', 'Nail Technician', 'Aesthetics Specialist')")->fetchColumn();
    echo json_encode(['success' => true, 'services' => $services, 'professionals' => $professionals]);
} catch (Throwable $e) {
    error_log('Public site statistics: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
