<?php

/**
 * Public: "Pay remaining balance" on Track your booking (home services).
 *
 * POST reference -> { success, url } the PayMongo checkout for everything
 * still owed. Reuses the link Admin already sent (editing the quote clears
 * it, so a stored link always matches the balance); otherwise creates one
 * with HomeServicePayment::sendBalanceLink(), which also emails it.
 *
 * The reference alone is enough: it only lets someone pay this booking's
 * balance, nothing personal is returned. Rate limited per IP.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/payments/HomeServicePayment.php';
require_once '../../backend/shared/config/security/RateLimiter.php';

sendCorsHeaders();
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Invalid request method.');
$reference = strtoupper(trim((string) ($_POST['reference'] ?? '')));
if (!preg_match('/^LM-[A-Z0-9-]{4,36}$/', $reference)) fail(400, 'Please enter a valid booking reference.');

$limiter = new RateLimiter(RateLimiter::getIpAddress(), 'pay_hs_balance', 10, 600);
if ($limiter->isExceeded()) fail(429, 'Too many tries. Please wait a few minutes and try again.');
$limiter->record();

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT h.id, h.reference_code, h.customer_id, h.status, h.quote_price, h.deposit_paid, h.balance_checkout_url, c.user_id
        FROM home_service_requests h JOIN customers c ON c.id = h.customer_id WHERE h.reference_code = ? FOR UPDATE');
    $stmt->execute([$reference]);
    $request = $stmt->fetch();
    if (!$request) { $pdo->rollBack(); fail(404, 'No home service booking found with that reference.'); }
    if ($request['status'] === 'Cancelled') { $pdo->rollBack(); fail(409, 'This booking was cancelled.'); }
    if ($request['quote_price'] === null || !(int) $request['deposit_paid']) {
        $pdo->rollBack();
        fail(409, 'The balance can be paid after your quote is set and the reservation fee is paid.');
    }
    $due = HomeServicePayment::totals($pdo, (int) $request['id'])['due'];
    if ($due <= 0) { $pdo->rollBack(); fail(409, 'This booking is already fully paid. Thank you!'); }

    $url = $request['balance_checkout_url'] ?: HomeServicePayment::sendBalanceLink($pdo, $request);
    $pdo->commit();
    echo json_encode(['success' => true, 'url' => $url, 'amount' => $due]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
        error_log('payHomeServiceBalance error: ' . $e->getMessage());
        fail(500, 'Something went wrong. Please try again.');
    }
    // e.g. below PayMongo's minimum, or online payment not set up: pay at the event instead.
    fail(409, preg_replace('/^PayMongo error \d+: /', '', $e->getMessage()) . ' You can pay the balance in cash on the event day.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('payHomeServiceBalance error: ' . $e->getMessage());
    fail(500, 'Something went wrong. Please try again.');
}
