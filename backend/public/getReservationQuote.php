<?php
require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/payments/ReservationPayment.php';
sendCorsHeaders();
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/shared/config/booking/BookingValidation.php';
BookingValidation::enforce($_GET, [
    'serviceIds' => ['ids', true, 'service or staff selections'],
]);


header('Cache-Control: no-store');

try {
    $pdo = Database::getInstance();
    $branch = trim($_GET['branch'] ?? '');
    $ids = $_GET['serviceIds'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids || $branch === '') throw new InvalidArgumentException('Please select a branch and services.');
    $stmt = $pdo->prepare('SELECT id FROM branches WHERE branch_key = ? OR id = ? LIMIT 1');
    $stmt->execute([$branch, ctype_digit($branch) ? (int) $branch : 0]);
    $branchId = $stmt->fetchColumn();
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, service_name, price, payment_requirement FROM services WHERE branch_id = ? AND is_active = 1 AND id IN ($marks)");
    $stmt->execute([$branchId, ...$ids]);
    $services = $stmt->fetchAll();
    if (count($services) !== count($ids)) throw new InvalidArgumentException('One or more services are unavailable for this branch.');
    // Loyalty card rewards replace legacy points redemption.
    $plan = ($_GET['paymentPlan'] ?? 'deposit') === 'full' ? 'full' : 'deposit';
    echo json_encode(['success' => true, 'quote' => ReservationPayment::quote($services, 0, false, $plan)]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('Reservation quote: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to calculate payment. Please try again.']);
}
