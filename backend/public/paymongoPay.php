<?php

/**
 * Public: start (or restart) an online payment -- GCash, Maya or Card
 *
 * POST ref, token (the booking's online_payment_token), method, from. Used by
 * frontend/public/payment-result.html for "Try again" / "Choose another
 * method" and by the home service pay links. Always creates a NEW PayMongo
 * attempt for the same unpaid booking (never a second booking, never a reused
 * failed attempt) and returns the page to send the customer to. If the
 * current attempt turns out to be paid after all, nothing new is created.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/RateLimiter.php';
require_once '../../backend/shared/config/payments/PayMongo.php';
require_once '../../backend/shared/config/payments/PaymentLog.php';

sendCorsHeaders();
header('Content-Type: application/json');

$reference = trim($_POST['ref'] ?? '');
$token = trim($_POST['token'] ?? '');
$method = trim($_POST['method'] ?? '');
$from = ($_POST['from'] ?? '') === 'customer' ? 'customer' : 'guest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $reference === '' || !preg_match('/^[a-f0-9]{32}$/', $token)
    || !in_array($method, PayMongo::METHODS, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment link.']);
    exit();
}
if (!PayMongo::isConfigured()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Online payment is not available right now.']);
    exit();
}

$limiter = new RateLimiter(RateLimiter::getIpAddress(), 'paymongo_pay', 20, 3600);
if ($limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many payment attempts. Please try again later.']);
    exit();
}
$limiter->record();

try {
    $pdo = Database::getInstance();

    $homeService = true;
    $stmt = $pdo->prepare('SELECT id, reference_code, status, deposit_paid, deposit_amount AS amount, online_payment_token FROM home_service_requests WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$reference]);
    $row = $stmt->fetch();
    if (!$row) {
        $homeService = false;
        $stmt = $pdo->prepare('SELECT id, reference_code, status, deposit_paid, reservation_amount_due AS amount, online_payment_token, preferred_payment_method FROM appointments WHERE reference_code = ? LIMIT 1');
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
    }
    if (!$row || !$row['online_payment_token'] || !hash_equals($row['online_payment_token'], $token)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    // A late success on the current attempt wins over starting another one.
    if (PayMongo::sync($pdo, $row, $homeService)['state'] === 'paid') {
        echo json_encode(['success' => true, 'paid' => true]);
        exit();
    }

    $payable = $homeService
        ? $row['status'] === 'Payment Required'
        : $row['status'] === 'Pending' && PayMongo::isOnline($row['preferred_payment_method']);
    if ((int) $row['deposit_paid'] === 1 || !$payable || (float) $row['amount'] <= 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $homeService
            ? 'This request has no reservation fee waiting for payment.'
            : 'This booking can no longer be paid online. Please book again.']);
        exit();
    }
    if ($error = PayMongo::amountError((float) $row['amount'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $error]);
        exit();
    }

    $payment = PayMongo::startPayment($pdo, $method, (int) $row['id'], $row['reference_code'], (float) $row['amount'], $from, $homeService);
    echo json_encode(['success' => true, 'paid' => false, 'checkoutUrl' => $payment['url']]);
} catch (PDOException $e) {
    error_log('paymongoPay error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
} catch (RuntimeException $e) {
    error_log('paymongoPay ' . $reference . ': ' . $e->getMessage());
    PaymentLog::record($pdo, 'api_error', null, $reference, null, null, 'checkout_failed', $e->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'We could not start the ' . PayMongo::label($method) . ' payment. Please try again or choose another method.']);
}
