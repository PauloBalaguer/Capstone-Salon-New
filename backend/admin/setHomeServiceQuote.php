<?php

/**
 * Admin: Home Service Quote / Payment Management
 *
 * The Booking Desk's home service workflow needs two steps that
 * updateBookingStatus.php doesn't cover: setting the final quoted price
 * (with an optional reservation fee), and recording that a customer's
 * reservation payment was received (self-reported by phone/in person --
 * this app has no online payment step for home service post-quote).
 * Verifying that recorded payment and moving to 'Confirmed' still goes
 * through the existing updateBookingStatus.php (same verify flow salon
 * appointments use).
 *
 * action=quote: sets quote_price, and optionally a required reservation
 * fee. Wedding Package D always carries the fixed ₱2,000 fee configured in
 * `wedding_packages`; Packages A-C have none unless the admin sets one here.
 *
 * action=recordPayment: marks the reservation fee as paid (self-reported by
 * staff on the customer's behalf) and moves the request to
 * 'Payment Being Verified', awaiting the admin's separate verify step.
 *
 * action=requestScheduleChange: notifies the customer only -- no status/
 * column changes, since this app has no reschedule-request status for home
 * service (see migration 031's status enum).
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/notifications/CustomerNotifier.php';
require_once '../../backend/shared/config/payments/PayMongo.php';
require_once '../../backend/shared/config/booking/HomeServiceRequest.php';

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

require_once __DIR__ . '/../../backend/shared/config/booking/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'quotePrice' => ['money', false, 'an amount with at most two decimal places'],
    'reservationFee' => ['money', false, 'an amount with at most two decimal places'],
    'depositAmount' => ['money', false, 'an amount with at most two decimal places'],
]);

$referenceCode = trim($_POST['id'] ?? '');
$action = trim($_POST['action'] ?? '');

if ($referenceCode === '' || !in_array($action, ['quote', 'recordPayment', 'requestScheduleChange'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A booking reference and valid action are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT h.*, h.deposit_amount AS required_deposit, c.user_id FROM home_service_requests h JOIN customers c ON c.id = h.customer_id WHERE h.reference_code = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$referenceCode]);
    $homeService = $stmt->fetch();
    if (!$homeService) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Home service request not found.']);
        exit();
    }
    $homeServiceId = $homeService['id'];
    $customerId = (int) $homeService['customer_id'];

    if ($action === 'quote') {
        if (in_array($homeService['status'], ['Cancelled','Completed','Service in Progress'], true)) throw new RuntimeException('This request can no longer be quoted.');
        $team = HomeServiceRequest::team($pdo, $homeService);
        HomeServiceRequest::requireAvailable($pdo, $homeService, $team ?: null);
        $quotePrice = filter_var($_POST['quotePrice'] ?? '', FILTER_VALIDATE_FLOAT);
        $reservationFee = filter_var($_POST['reservationFee'] ?? '0', FILTER_VALIDATE_FLOAT);
        if ($quotePrice === false || $quotePrice <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please provide a valid quote price.']);
            exit();
        }
        if ($reservationFee === false || $reservationFee < 0) {
            $reservationFee = 0;
        }

        if ((int) $homeService['deposit_paid'] === 1) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'The reservation fee for this request is already paid, so the quote can no longer be changed.']);
            exit();
        }
        if ($reservationFee > $quotePrice) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The reservation fee cannot be more than the quote.']);
            exit();
        }

        $requiresPayment = $reservationFee > 0;
        $status = $requiresPayment ? 'Payment Required' : 'Quote Ready';

        $token = $requiresPayment ? bin2hex(random_bytes(16)) : null; // Authorizes this quote's pay links.
        $pdo->prepare('
            UPDATE home_service_requests
            SET quote_price = ?, deposit_amount = ?, deposit_paid = 0, deposit_recorded_by = NULL, deposit_recorded_at = NULL, status = ?, payment_status = ?,
                paymongo_checkout_id = NULL, paymongo_checkout_url = NULL, paymongo_payment_intent_id = NULL, paymongo_payment_id = NULL, online_payment_token = ?
            WHERE id = ?
        ')->execute([
            $quotePrice,
            $requiresPayment ? $reservationFee : null,
            $status,
            'Payment Required',
            $token,
            $homeServiceId,
        ]);

        // Pay the reservation fee (down payment) online: the quote message links
        // to a page where the customer picks GCash, Maya or Card; the PayMongo
        // payment is created only then, so the link can't expire. If PayMongo
        // isn't set up, the fee is paid in person (recorded with "Record Payment").
        $payUrl = null;
        $payNote = '';
        if ($requiresPayment && PayMongo::isConfigured()) {
            if ($reservationFee < PayMongo::MIN_AMOUNT) {
                $payNote = ' The fee is below PayMongo\'s ₱' . number_format(PayMongo::MIN_AMOUNT, 2) . ' minimum, so it must be paid in person.';
            } else {
                $payUrl = PayMongo::payUrls($token, $referenceCode, $reservationFee, $homeService['user_id'] !== null ? 'customer' : 'guest')['choose'] ?? null;
            }
        }

        $message = $requiresPayment
            ? "Your home service request {$referenceCode} has a final quote of ₱" . number_format($quotePrice, 2) . " and requires a ₱" . number_format($reservationFee, 2) . ' reservation fee (down payment) to confirm it.'
                . ($payUrl ? " Pay it online with GCash, Maya or card: {$payUrl} — or use Track My Request." : ' Please contact the branch to arrange payment.')
            : "Your home service request {$referenceCode} has a final quote of ₱" . number_format($quotePrice, 2) . ' with no reservation payment required.';
        CustomerNotifier::notify($pdo, $customerId, 'QUOTE_READY', $message);

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Quote saved.' . ($payUrl ? ' A payment link (GCash, Maya or card) was sent. Staff availability is checked again before confirmation.' : '') . $payNote,
            'payUrl' => $payUrl,
        ]);
        exit();
    }

    if ($action === 'recordPayment') {
        if ($homeService['status'] !== 'Payment Required') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This request is not currently awaiting a reservation payment.']);
            exit();
        }

        $allowedDepositMethods = ['Cash', 'GCash', 'Maya'];
        $depositAmount = filter_var($_POST['depositAmount'] ?? '', FILTER_VALIDATE_FLOAT);
        $depositMethod = trim($_POST['depositMethod'] ?? '');
        $depositReference = trim($_POST['depositReference'] ?? '');
        if ($depositAmount === false || $depositAmount <= 0 || !in_array($depositMethod, $allowedDepositMethods, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A deposit amount and payment method are required.']);
            exit();
        }

        if ($depositAmount < (float) $homeService['required_deposit'] || $homeService['quote_price'] === null || $depositAmount > (float) $homeService['quote_price']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'The payment must cover the required reservation fee and cannot exceed the quote.', 'errors' => ['depositAmount' => 'Enter an amount between the required reservation fee and the quote total.']]);
            exit();
        }

        $pdo->prepare('
            UPDATE home_service_requests
            SET deposit_paid = 1, deposit_amount = ?, deposit_method = ?, deposit_reference = ?, status = ?, payment_status = ?
            WHERE id = ?
        ')->execute([$depositAmount, $depositMethod, $depositReference !== '' ? $depositReference : null, 'Payment Being Verified', 'Awaiting Verification', $homeServiceId]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Payment recorded. Verify it to confirm the request.']);
        exit();
    }

    // requestScheduleChange
    $note = trim($_POST['note'] ?? '');
    $message = "Your home service request {$referenceCode} needs a schedule change. " . ($note !== '' ? $note : 'Please contact the branch to confirm a new date/time.');
    CustomerNotifier::notify($pdo, $customerId, 'RESCHEDULE', $message);
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["Schedule change requested for home service {$referenceCode}."]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Customer notified of the requested schedule change.']);
} catch (PDOException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!($e instanceof PDOException) || in_array((int) ($e->errorInfo[1] ?? 0), [1205,1213], true)) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>$e instanceof PDOException ? HomeServiceRequest::UNAVAILABLE : $e->getMessage()]);
        exit();
    }
    http_response_code(500);
    error_log('admin setHomeServiceQuote error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating this request.']);
}
