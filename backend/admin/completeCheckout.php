<?php

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/notifications/CustomerNotifier.php';
require_once '../../backend/shared/config/booking/Scheduling.php';
require_once '../../backend/shared/config/helpers/LoyaltyTier.php';

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

    $pdo->beginTransaction();
    $appointment = Scheduling::lockAppointment($pdo, (int) $appointment['id']);
    if (in_array($appointment['status'], ['Cancelled', 'No-Show', 'Reviewed'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This booking is closed and cannot be checked out. Use the eligible rescheduling action first.']);
        exit();
    }

    // The locked appointment and unique checkout row prevent repeat rewards.
    if (!in_array($appointment['status'], ['Confirmed','In Progress','Completed'], true)) throw new RuntimeException('This booking is not ready for checkout.');
    if ($appointment['deposit_paid'] && (float)$appointment['deposit_amount']>0 && $appointment['deposit_recorded_by']===null) throw new RuntimeException('Verify the reservation payment before checkout.');
    if (($appointment['paymongo_checkout_id'] || $appointment['paymongo_payment_intent_id']) && !$appointment['deposit_paid']) throw new RuntimeException('The online reservation payment is still pending.');

    $stmt = $pdo->prepare('SELECT id FROM payments WHERE appointment_id = ?');
    $stmt->execute([$appointment['id']]);
    if ($stmt->fetchColumn()) throw new RuntimeException('This booking has already been paid.');
    $verifiedDeposit = $appointment['deposit_paid'] && $appointment['deposit_recorded_by'] !== null ? (float)$appointment['deposit_amount'] : 0;
    $balance = max(0,(float)$appointment['total_price']-$verifiedDeposit);
    $pdo->prepare('INSERT INTO payments (appointment_id, amount, payment_method, status) VALUES (?, ?, ?, "Paid")')
        ->execute([$appointment['id'], $balance, $paymentMethod]);

    $pdo->prepare("UPDATE appointments SET status = 'Completed', payment_status = 'Fully Paid' WHERE id = ?")->execute([$appointment['id']]);

    // Loyalty points earned on completion, same rate + per-service weighting
    // (customer scoring) as cashier/payment.php.
    $stmt = $pdo->prepare('
        SELECT s.price, s.loyalty_multiplier AS loyaltyMultiplier
        FROM appointment_services aps
        JOIN services s ON s.id = aps.service_id
        WHERE aps.appointment_id = ?
    ');
    $stmt->execute([$appointment['id']]);
    $bookedServices = $stmt->fetchAll();
    $pointsEarned = LoyaltyTier::earnedPoints((float)$appointment['total_price'], $bookedServices);
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
} catch (PDOException | RuntimeException $e) {
    if (!($e instanceof PDOException)) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);exit();
    }
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('admin completeCheckout error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while completing checkout.']);
}
