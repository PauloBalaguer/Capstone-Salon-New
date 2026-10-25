<?php

/**
 * Public: PayMongo payment result
 *
 * Powers frontend/public/payment-result.html, where PayMongo sends the
 * customer back after paying (or cancelling). Requires the booking's
 * online_payment_token (only the booker's links carry it). Asks PayMongo
 * directly (PayMongo::sync), so a payment registers even when the webhook
 * can't reach this server (e.g. plain localhost without ngrok).
 *
 * state: 'paid', 'pending' (page polls again), or 'failed' (cancelled /
 * declined -- 'reason' has PayMongo's message). While the booking is still
 * payable, 'methods' lists the buttons for "Try again" / "Choose another
 * method" (paymongoPay.php).
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/payments/PayMongo.php';

sendCorsHeaders();
header('Content-Type: application/json');

$reference = trim($_GET['ref'] ?? '');
$token = trim($_GET['token'] ?? '');

if ($reference === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment link.']);
    exit();
}

/** Response for a reservation deposit / fee: syncs with PayMongo, then reports the row's state. */
function depositResult(PDO $pdo, PDOStatement $select, string $reference, bool $homeService): array
{
    $select->execute([$reference]);
    $sync = ['state' => 'pending', 'reason' => null];
    try {
        $sync = PayMongo::sync($pdo, $select->fetch(), $homeService);
    } catch (RuntimeException $e) {
        error_log('paymongoStatus sync ' . $reference . ': ' . $e->getMessage());
    }
    $select->execute([$reference]);
    $row = $select->fetch();
    $paid = (int) $row['deposit_paid'] === 1;
    $method = $paid ? $row['deposit_method'] : ($homeService ? null : $row['preferred_payment_method']);
    $payable = !$paid && PayMongo::isConfigured() && ($homeService ? $row['status'] === 'Payment Required' : $row['status'] === 'Pending');
    return [
        'success' => true,
        'type' => $homeService ? 'home_service' : 'appointment',
        'reference' => $row['reference_code'],
        'paid' => $paid,
        'state' => $paid ? 'paid' : $sync['state'],
        'reason' => $paid ? null : $sync['reason'],
        'status' => $row['status'],
        'paymentStatus' => $row['payment_status'],
        'amountPaid' => $paid ? (float) $row['deposit_amount'] : 0,
        'amountDue' => (float) ($homeService ? $row['deposit_amount'] : $row['reservation_amount_due']),
        'method' => $method,
        'methodLabel' => $method ? PayMongo::label($method) : null,
        'paymentId' => $paid ? $row['paymongo_payment_id'] : null,
        'paidAt' => $paid ? $row['deposit_recorded_at'] : null,
        'holdMinutes' => PayMongo::HOLD_MINUTES,
        'methods' => $payable ? array_map(fn($m) => ['value' => $m, 'label' => PayMongo::label($m)], PayMongo::METHODS) : [],
    ];
}

try {
    $pdo = Database::getInstance();

    $hsStmt = $pdo->prepare('
        SELECT id, customer_id, reference_code, status, payment_status, deposit_paid, deposit_amount, deposit_method,
               deposit_recorded_at, paymongo_payment_id, online_payment_token,
               balance_checkout_id, balance_checkout_url, balance_payment_token
        FROM home_service_requests WHERE reference_code = ? LIMIT 1
    ');
    $hsStmt->execute([$reference]);
    $homeService = $hsStmt->fetch();

    // Home service remaining balance (HomeServicePayment::sendBalanceLink).
    if ($homeService && $homeService['balance_payment_token'] && hash_equals($homeService['balance_payment_token'], $token)) {
        require_once '../../backend/shared/config/payments/HomeServicePayment.php';
        $paid = false;
        try {
            $paid = HomeServicePayment::syncBalance($pdo, $homeService);
        } catch (RuntimeException $e) {
            error_log('paymongoStatus balance sync ' . $reference . ': ' . $e->getMessage());
        }
        $totals = HomeServicePayment::totals($pdo, (int) $homeService['id']);
        $hsStmt->execute([$reference]);
        $homeService = $hsStmt->fetch();
        echo json_encode([
            'success' => true,
            'type' => 'home_service',
            'kind' => 'balance',
            'reference' => $homeService['reference_code'],
            'paid' => $paid,
            'status' => $homeService['status'],
            'paymentStatus' => $homeService['payment_status'],
            'amountPaid' => $totals['paid'],
            'amountDue' => $totals['due'],
            'checkoutUrl' => !$paid ? $homeService['balance_checkout_url'] : null,
        ]);
        exit();
    }

    if ($homeService) {
        if (!$homeService['online_payment_token'] || !hash_equals($homeService['online_payment_token'], $token)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
            exit();
        }
        echo json_encode(depositResult($pdo, $hsStmt, $reference, true));
        exit();
    }

    $stmt = $pdo->prepare('
        SELECT id, customer_id, reference_code, status, payment_status, deposit_paid, deposit_amount, deposit_method,
               deposit_recorded_at, reservation_amount_due, preferred_payment_method, paymongo_payment_id, online_payment_token
        FROM appointments WHERE reference_code = ? LIMIT 1
    ');
    $stmt->execute([$reference]);
    $appointment = $stmt->fetch();
    if (!$appointment || !$appointment['online_payment_token'] || !hash_equals($appointment['online_payment_token'], $token)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }
    echo json_encode(depositResult($pdo, $stmt, $reference, false));
} catch (PDOException $e) {
    error_log('paymongoStatus error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
