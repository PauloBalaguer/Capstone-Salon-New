<?php

/**
 * Admin: Home Service Remaining Balance
 *
 * POST id=<reference>, action=
 *   collect  amount, method (Cash|GCash|Maya), reference (optional)
 *            -> records a balance payment; 'Fully Paid' once the quote is covered.
 *   sendLink -> PayMongo checkout for everything still owed, sent to the customer.
 *   editQuote quotePrice -> corrects the final quote after the DP was paid
 *            (e.g. a typo), as long as it isn't below what's already paid.
 * Logic lives in backend/config/HomeServicePayment.php.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/HomeServicePayment.php';

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

$reference = trim($_POST['id'] ?? '');
$action = trim($_POST['action'] ?? '');
if ($reference === '' || !in_array($action, ['collect', 'sendLink', 'editQuote'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A booking reference and valid action are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT h.id, h.reference_code, h.customer_id, h.status, h.quote_price, h.deposit_paid, c.user_id
        FROM home_service_requests h JOIN customers c ON c.id = h.customer_id WHERE h.reference_code = ? LIMIT 1');
    $stmt->execute([$reference]);
    $request = $stmt->fetch();
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Home service request not found.']);
        exit();
    }
    if ($request['status'] === 'Cancelled') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This request was cancelled.']);
        exit();
    }
    if ($request['quote_price'] === null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Set a quote first.']);
        exit();
    }
    $totals = HomeServicePayment::totals($pdo, (int) $request['id']);

    if ($action === 'editQuote') {
        $quote = filter_var($_POST['quotePrice'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($quote === false || $quote <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Enter a valid quote.']);
            exit();
        }
        if ($quote < $totals['paid']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The quote can\'t be less than what the customer already paid (₱' . number_format($totals['paid'], 2) . ').']);
            exit();
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE home_service_requests SET quote_price = ?, balance_checkout_url = NULL, balance_checkout_id = NULL, balance_payment_token = NULL WHERE id = ?')
            ->execute([$quote, $request['id']]);
        $due = max(0, round($quote - $totals['paid'], 2));
        $pdo->prepare('UPDATE home_service_requests SET payment_status = ? WHERE id = ? AND deposit_paid = 1')
            ->execute([$due <= 0 ? 'Fully Paid' : 'Down Payment Verified', $request['id']]);
        CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'QUOTE_READY',
            "Your home service {$reference} quote was updated to ₱" . number_format($quote, 2) . '. Remaining balance: ₱' . number_format($due, 2) . '.');
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Quote updated to ₱' . number_format($quote, 2) . '. Balance due: ₱' . number_format($due, 2) . '.']);
        exit();
    }

    if ($totals['due'] <= 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This request is already fully paid.']);
        exit();
    }

    if ($action === 'sendLink') {
        try {
            $pdo->beginTransaction();
            $url = HomeServicePayment::sendBalanceLink($pdo, $request);
            $pdo->commit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => preg_replace('/^PayMongo error \d+: /', '', $e->getMessage())]);
            exit();
        }
        echo json_encode(['success' => true, 'message' => 'Payment link for ₱' . number_format($totals['due'], 2) . ' sent to the customer.', 'payUrl' => $url]);
        exit();
    }

    // collect
    $amount = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);
    $method = trim($_POST['method'] ?? '');
    $paymentReference = mb_substr(trim($_POST['reference'] ?? ''), 0, 255);
    if ($amount === false || $amount <= 0 || !in_array($method, ['Cash', 'GCash', 'Maya'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter the amount received and the payment method.']);
        exit();
    }
    if ($amount > $totals['due'] + 0.001) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'That\'s more than the balance due (₱' . number_format($totals['due'], 2) . ').']);
        exit();
    }
    $pdo->beginTransaction();
    $after = HomeServicePayment::record($pdo, $request, 'balance', (float) $amount, $method, $paymentReference, (int) $_SESSION['user_id']);
    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => '₱' . number_format($amount, 2) . ' recorded. ' . ($after['due'] <= 0 ? 'The request is now Fully Paid.' : 'Still due: ₱' . number_format($after['due'], 2) . '.'),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('admin homeServiceBalance error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the balance.']);
}
