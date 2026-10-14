<?php

/**
 * Public: PayMongo payment result
 *
 * Powers pages/payment/result.html, where PayMongo sends the customer back
 * after checkout. Requires the booking's online_payment_token (only the
 * booker's success/cancel URLs carry it). Checks the checkout session with
 * PayMongo directly, so a payment registers even when the webhook can't
 * reach this server (e.g. plain localhost without ngrok).
 */

require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/PayMongo.php';

sendCorsHeaders();
header('Content-Type: application/json');

$reference = trim($_GET['ref'] ?? '');
$token = trim($_GET['token'] ?? '');

if ($reference === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment link.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    // Home service reservation fee (see PayMongo::startHomeServiceCheckout).
    $hsStmt = $pdo->prepare('
        SELECT id, customer_id, reference_code, status, payment_status, deposit_paid, deposit_amount,
               paymongo_checkout_id, paymongo_checkout_url, online_payment_token,
               balance_checkout_id, balance_checkout_url, balance_payment_token
        FROM home_service_requests WHERE reference_code = ? LIMIT 1
    ');
    $hsStmt->execute([$reference]);
    $homeService = $hsStmt->fetch();

    // Home service remaining balance (HomeServicePayment::sendBalanceLink).
    if ($homeService && $homeService['balance_payment_token'] && hash_equals($homeService['balance_payment_token'], $token)) {
        require_once '../config/HomeServicePayment.php';
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
        $paid = (int) $homeService['deposit_paid'] === 1;
        if (!$paid) {
            try {
                $paid = PayMongo::syncHomeService($pdo, $homeService);
            } catch (RuntimeException $e) {
                error_log('paymongoStatus home sync ' . $reference . ': ' . $e->getMessage());
            }
        }
        $hsStmt->execute([$reference]);
        $homeService = $hsStmt->fetch();
        echo json_encode([
            'success' => true,
            'type' => 'home_service',
            'reference' => $homeService['reference_code'],
            'paid' => $paid,
            'status' => $homeService['status'],
            'paymentStatus' => $homeService['payment_status'],
            'amountPaid' => $paid ? (float) $homeService['deposit_amount'] : 0,
            'amountDue' => (float) $homeService['deposit_amount'],
            'checkoutUrl' => !$paid && $homeService['status'] === 'Payment Required' ? $homeService['paymongo_checkout_url'] : null,
        ]);
        exit();
    }

    $stmt = $pdo->prepare('
        SELECT id, customer_id, reference_code, status, payment_status, deposit_paid, deposit_amount,
               reservation_amount_due, paymongo_checkout_id, paymongo_checkout_url, online_payment_token
        FROM appointments WHERE reference_code = ? LIMIT 1
    ');
    $stmt->execute([$reference]);
    $appointment = $stmt->fetch();
    if (!$appointment || !$appointment['online_payment_token'] || !hash_equals($appointment['online_payment_token'], $token)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    $paid = (int) $appointment['deposit_paid'] === 1;
    if (!$paid) {
        try {
            $paid = PayMongo::syncAppointment($pdo, $appointment);
        } catch (RuntimeException $e) {
            error_log('paymongoStatus sync ' . $reference . ': ' . $e->getMessage());
        }
    }
    $stmt->execute([$reference]);
    $appointment = $stmt->fetch();
    $canRetry = !$paid && $appointment['status'] === 'Pending' && $appointment['paymongo_checkout_url'];

    echo json_encode([
        'success' => true,
        'reference' => $appointment['reference_code'],
        'paid' => $paid,
        'status' => $appointment['status'],
        'paymentStatus' => $appointment['payment_status'],
        'amountPaid' => $paid ? (float) $appointment['deposit_amount'] : 0,
        'amountDue' => (float) $appointment['reservation_amount_due'],
        'holdMinutes' => PayMongo::HOLD_MINUTES,
        'checkoutUrl' => $canRetry ? $appointment['paymongo_checkout_url'] : null,
    ]);
} catch (PDOException $e) {
    error_log('paymongoStatus error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
