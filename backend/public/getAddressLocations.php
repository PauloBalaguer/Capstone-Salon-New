<?php
require_once __DIR__ . '/../../backend/shared/config/helpers/Address.php';
header('Content-Type: application/json');
try {
    $rows = Address::formLocations(is_string($_GET['level'] ?? null) ? $_GET['level'] : '', is_string($_GET['parent'] ?? null) ? $_GET['parent'] : '');
    echo json_encode(['success' => true, 'locations' => $rows]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
