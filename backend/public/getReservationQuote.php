<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/ReservationPayment.php';
sendCorsHeaders();
header('Content-Type: application/json');
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
    $points = 0;
    $redeem = filter_var($_GET['useLoyaltyPoints'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($redeem) {
        if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'Customer') {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please log in to redeem loyalty points.']); exit;
        }
        $stmt = $pdo->prepare('SELECT loyalty_points FROM customers WHERE user_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $points = (int) $stmt->fetchColumn();
    }
    $plan = ($_GET['paymentPlan'] ?? 'deposit') === 'full' ? 'full' : 'deposit';
    echo json_encode(['success' => true, 'quote' => ReservationPayment::quote($services, $points, $redeem, $plan)]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('Reservation quote: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to calculate payment. Please try again.']);
}
