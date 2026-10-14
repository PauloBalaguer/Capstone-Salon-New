<?php

/**
 * Public: PayMongo webhook receiver
 *
 * PayMongo POSTs checkout_session.payment.paid here once a customer pays.
 * The signature is verified with the webhook secret, then the session is
 * re-read from PayMongo's API (PayMongo::syncAppointment) rather than
 * trusting the payload's amounts. Every delivery -- accepted, ignored, or
 * rejected for a bad signature -- is written to payment_transactions.
 * Register this URL once per environment; it must be publicly reachable
 * (e.g. through ngrok on XAMPP).
 */

require_once '../config/database.php';
require_once '../config/PayMongo.php';
require_once '../config/PaymentLog.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?? [];
$eventId = $payload['data']['id'] ?? null;
$event = $payload['data']['attributes'] ?? [];
$eventType = $event['type'] ?? 'unknown';
$checkoutId = $event['data']['id'] ?? null;

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    error_log('paymongoWebhook DB error: ' . $e->getMessage());
    http_response_code(500);
    exit();
}

if (!PayMongo::verifyWebhookSignature($raw, $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '')) {
    PaymentLog::record($pdo, 'webhook_rejected', null, null, $eventId, null, 'invalid_signature',
        ['type' => $eventType, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit();
}

try {
    $appointment = null;
    if ($checkoutId) {
        $stmt = $pdo->prepare('SELECT id, customer_id, reference_code, status, deposit_paid, paymongo_checkout_id FROM appointments WHERE paymongo_checkout_id = ? LIMIT 1');
        $stmt->execute([$checkoutId]);
        $appointment = $stmt->fetch() ?: null;
    }
    PaymentLog::record($pdo, 'webhook_received', $appointment ? (int) $appointment['id'] : null,
        $appointment['reference_code'] ?? null, $eventId, null, $eventType, $raw);

    if ($eventType === 'checkout_session.payment.paid' && $appointment) {
        PayMongo::syncAppointment($pdo, $appointment);
    } elseif ($eventType === 'checkout_session.payment.paid' && $checkoutId) {
        // Not a salon booking -- maybe a home service reservation fee.
        $stmt = $pdo->prepare('SELECT id, customer_id, reference_code, status, deposit_paid, paymongo_checkout_id FROM home_service_requests WHERE paymongo_checkout_id = ? LIMIT 1');
        $stmt->execute([$checkoutId]);
        if ($homeService = $stmt->fetch()) {
            PayMongo::syncHomeService($pdo, $homeService);
        } else {
            // Or a home service remaining-balance checkout.
            require_once '../config/HomeServicePayment.php';
            $stmt = $pdo->prepare('SELECT id, customer_id, reference_code, balance_checkout_id FROM home_service_requests WHERE balance_checkout_id = ? LIMIT 1');
            $stmt->execute([$checkoutId]);
            if ($homeService = $stmt->fetch()) {
                HomeServicePayment::syncBalance($pdo, $homeService);
            }
        }
    }
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    // A non-2xx makes PayMongo retry the delivery later.
    error_log('paymongoWebhook error: ' . $e->getMessage());
    PaymentLog::record($pdo, 'api_error', null, null, $eventId, null, $eventType, $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
