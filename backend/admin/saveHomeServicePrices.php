<?php

/**
 * Admin: Save the home service price list (migration 065): the per-client
 * price of each home service service and the travel fee per Albay city.
 * The request forms show an estimate from these, and
 * HomeServiceRequest::estimate() recalculates it on submit.
 *
 * POST prices = JSON {"rates": {"Hair Styling": 500, ...}, "travelFees": {"050503000": 0, ...}}
 * Only known services and existing city rows are updated.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/booking/HomeServiceRequest.php';

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

$input = json_decode((string) ($_POST['prices'] ?? ''), true);
$amount = fn($value) => is_numeric($value) && (float) $value >= 0 && (float) $value <= 1000000 ? round((float) $value, 2) : null;
$rates = [];
$fees = [];
foreach ((array) ($input['rates'] ?? []) as $service => $value) {
    if (!in_array($service, HomeServiceRequest::SERVICES, true)) continue;
    if (($rates[$service] = $amount($value)) === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Enter a price from 0 to 1,000,000 for {$service}."]);
        exit();
    }
}
foreach ((array) ($input['travelFees'] ?? []) as $code => $value) {
    if (!preg_match('/^[0-9]{9}$/D', (string) $code)) continue;
    if (($fees[$code] = $amount($value)) === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Travel fees must be amounts from 0 to 1,000,000.']);
        exit();
    }
}
if (!$rates && !$fees) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nothing to save.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    $saveRate = $pdo->prepare('INSERT INTO home_service_rates (service_name, price_per_client) VALUES (?, ?) ON DUPLICATE KEY UPDATE price_per_client = VALUES(price_per_client)');
    foreach ($rates as $service => $price) $saveRate->execute([$service, $price]);
    $saveFee = $pdo->prepare('UPDATE home_service_travel_fees SET fee = ? WHERE city_code = ?');
    foreach ($fees as $code => $fee) $saveFee->execute([$fee, $code]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Home service prices saved.']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('saveHomeServicePrices error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save the prices. Run database/migrations/065_home_service_estimate.sql if you haven\'t.']);
}
