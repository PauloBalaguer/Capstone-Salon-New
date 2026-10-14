<?php

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/CustomerNotifier.php';

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

$referenceCode = trim($_POST['id'] ?? '');
$paymentMethod = trim($_POST['paymentMethod'] ?? 'Cash');
$allowedMethods = ['Cash', 'Card', 'Online', 'Gift Card'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    $paymentMethod = 'Cash';
}

if ($referenceCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking reference is required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id, total_price, customer_id, status FROM appointments WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$referenceCode]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    // Idempotency guard: without this, re-submitting checkout for an
    // already-Completed booking (double-click, retry, re-opened tab) would
    // re-run the loyalty award below and credit the same booking twice.
    if ($appointment['status'] === 'Completed') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This booking has already been completed.']);
        exit();
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM payments WHERE appointment_id = ?');
    $stmt->execute([$appointment['id']]);
    if (!$stmt->fetchColumn()) {
        $pdo->prepare('INSERT INTO payments (appointment_id, amount, payment_method, status) VALUES (?, ?, ?, "Paid")')
            ->execute([$appointment['id'], $appointment['total_price'], $paymentMethod]);
    }

    $pdo->prepare("UPDATE appointments SET status = 'Completed', payment_status = 'Fully Paid' WHERE id = ?")->execute([$appointment['id']]);

    // Loyalty points earned on completion, same rate + per-service weighting
    // (customer scoring) as cashier/payment.php.
    define('LOYALTY_EARN_RATE', 20); // 1 point per PHP20 of services (at 1.0x weight)
    $stmt = $pdo->prepare('
        SELECT s.price, s.loyalty_multiplier AS loyaltyMultiplier
        FROM appointment_services aps
        JOIN services s ON s.id = aps.service_id
        WHERE aps.appointment_id = ?
    ');
    $stmt->execute([$appointment['id']]);
    $bookedServices = $stmt->fetchAll();
    $weightedServiceValue = array_reduce($bookedServices, fn($sum, $s) => $sum + ((float) $s['price'] * (float) $s['loyaltyMultiplier']), 0.0);
    $baseServiceValue = array_reduce($bookedServices, fn($sum, $s) => $sum + (float) $s['price'], 0.0);
    $scoringMultiplier = $baseServiceValue > 0 ? ($weightedServiceValue / $baseServiceValue) : 1.0;
    $pointsEarned = (int) floor(((float) $appointment['total_price'] * $scoringMultiplier) / LOYALTY_EARN_RATE);
    if ($pointsEarned > 0) {
        $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?')
            ->execute([$pointsEarned, $appointment['customer_id']]);
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
        ->execute(["Booking {$referenceCode} paid in full (PHP " . number_format((float) $appointment['total_price'], 2) . ")."]);

    CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'PAYMENT_VERIFIED', "Your payment for {$referenceCode} has been verified. Status: Paid in Full.");
    CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'COMPLETED', "Your appointment {$referenceCode} is now Completed. Thank you for choosing us!");

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Checkout completed.']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('admin completeCheckout error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while completing checkout.']);
}
