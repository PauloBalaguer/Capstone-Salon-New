<?php
require_once __DIR__ . '/../shared/config/setup/cors.php';
require_once __DIR__ . '/../shared/config/setup/database.php';
require_once __DIR__ . '/../shared/config/booking/Scheduling.php';

sendCorsHeaders();
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT TRIM(service_name)) FROM services WHERE is_active = 1');
    $stmt->execute(); $services = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE is_active = 1 AND branch_id IS NOT NULL AND position IN ('Hair Stylist', 'Nail Technician', 'Aesthetics Specialist')");
    $stmt->execute(); $professionals = (int) $stmt->fetchColumn();
    $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT branch_key FROM branches WHERE is_active = 1');
    $stmt->execute(); $branchHours = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $branch) {
        [$open, $close] = Scheduling::operatingHours($branch, $date);
        $branchHours[$branch] = ['open' => $open, 'close' => $close];
    }
    echo json_encode(['success' => true, 'services' => $services, 'professionals' => $professionals, 'date' => $date, 'branchHours' => $branchHours]);
} catch (Throwable $e) {
    error_log('Public site statistics: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
