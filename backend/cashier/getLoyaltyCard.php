<?php
require_once '../shared/config/setup/cors.php';
require_once '../shared/config/setup/session.php';
require_once '../shared/config/setup/database.php';
require_once '../shared/config/helpers/LoyaltyCard.php';
sendCorsHeaders();
header('Content-Type: application/json');
if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401); echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']); exit;
}
try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT id, customer_id, branch_id, total_price, loyalty_points_used FROM appointments WHERE reference_code = ?');
    $stmt->execute([trim($_GET['reference'] ?? '')]);
    $appointment = $stmt->fetch();
    if (!$appointment || (($_SESSION['user_role'] ?? '') === 'Cashier' && (int) $appointment['branch_id'] !== (int) ($_SESSION['branch_id'] ?? 0))) {
        http_response_code(404); echo json_encode(['success' => false, 'message' => 'Booking not found.']); exit;
    }
    $stmt = $pdo->prepare('SELECT s.id, s.service_name AS name, s.price, s.loyalty_treatment_eligible FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = ? ORDER BY aps.id');
    $stmt->execute([$appointment['id']]);
    $services = $stmt->fetchAll();
    $prices = ReservationPayment::receiptServicePrices($services, (float) $appointment['total_price']);
    $treatments = [];
    foreach ($services as $i => $service) {
        if ($service['loyalty_treatment_eligible']) $treatments[] = ['id' => (int) $service['id'], 'name' => $service['name'], 'discount' => $prices[$i]];
    }
    echo json_encode(['success' => true, 'card' => LoyaltyCard::summary($pdo, (int) $appointment['customer_id']), 'treatments' => $treatments, 'pointsUsed' => (int) $appointment['loyalty_points_used']]);
} catch (PDOException $e) {
    error_log('Loyalty card lookup: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['success' => false, 'message' => 'Could not load the loyalty card.']);
}
