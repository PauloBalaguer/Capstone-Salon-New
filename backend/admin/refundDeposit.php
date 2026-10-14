<?php

/**
 * Admin: Refund a reservation deposit
 *
 * For a booking whose deposit is 'Refund Due' (see CancellationPolicy):
 *  - PayMongo deposit (deposit_reference = pay_...): creates the refund
 *    through PayMongo's API, so the money goes back to the customer's GCash /
 *    Maya / card automatically. PayMongo may finish it later ('Refund
 *    Processing'); calling this again for that booking re-checks the refund
 *    instead of creating a second one.
 *  - Cash / manual deposit: staff have returned the money in person, so this
 *    just records it as Refunded.
 * Either way the customer is notified and the step is written to the
 * payment log.
 *
 * POST: reference (booking reference code).
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/PayMongo.php';
require_once '../config/PaymentLog.php';
require_once '../config/CustomerNotifier.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$reference = trim($_POST['reference'] ?? '');
if ($reference === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A booking reference is required.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('
        SELECT id, customer_id, reference_code, payment_status, deposit_amount, deposit_method, deposit_reference, paymongo_refund_id
        FROM appointments WHERE reference_code = ? LIMIT 1 FOR UPDATE
    ');
    $stmt->execute([$reference]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }
    if (!in_array($appointment['payment_status'], ['Refund Due', 'Refund Processing'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only deposits marked Refund Due can be refunded (this one is ' . $appointment['payment_status'] . ').']);
        exit();
    }

    $appointmentId = (int) $appointment['id'];
    $amount = (float) $appointment['deposit_amount'];
    $viaPaymongo = $appointment['deposit_method'] === PayMongo::METHOD && str_starts_with((string) $appointment['deposit_reference'], 'pay_');

    if ($viaPaymongo) {
        try {
            if ($appointment['paymongo_refund_id']) {
                $refund = ['id' => $appointment['paymongo_refund_id'], 'status' => PayMongo::refundStatus($appointment['paymongo_refund_id'])];
            } else {
                $refund = PayMongo::refundPayment($appointment['deposit_reference'], $amount, "Deposit refund for booking {$reference}");
                $pdo->prepare('UPDATE appointments SET paymongo_refund_id = ? WHERE id = ?')->execute([$refund['id'], $appointmentId]);
                PaymentLog::record($pdo, 'refund_requested', $appointmentId, $reference, $refund['id'], $amount, $refund['status'],
                    ['payment_id' => $appointment['deposit_reference'], 'by_user_id' => $_SESSION['user_id']]);
            }
        } catch (RuntimeException $e) {
            // Keep any refund id already saved above; nothing else changed.
            $pdo->commit();
            PaymentLog::record($pdo, 'api_error', $appointmentId, $reference, $appointment['deposit_reference'], $amount, 'refund_failed', $e->getMessage());
            error_log('refundDeposit PayMongo error: ' . $e->getMessage());
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'PayMongo did not accept the refund: ' . preg_replace('/^PayMongo error \d+: /', '', $e->getMessage())]);
            exit();
        }

        if ($refund['status'] === 'failed') {
            // Let staff retry with a fresh refund.
            $pdo->prepare("UPDATE appointments SET paymongo_refund_id = NULL, payment_status = 'Refund Due' WHERE id = ?")->execute([$appointmentId]);
            PaymentLog::record($pdo, 'refund_failed', $appointmentId, $reference, $refund['id'], $amount, 'failed');
            $pdo->commit();
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'PayMongo reported the refund as failed. You can try again, or refund the customer another way.']);
            exit();
        }
        $newStatus = $refund['status'] === 'succeeded' ? 'Refunded' : 'Refund Processing';
    } else {
        $newStatus = 'Refunded';
    }

    $changed = $newStatus !== $appointment['payment_status'];
    $pdo->prepare('UPDATE appointments SET payment_status = ? WHERE id = ?')->execute([$newStatus, $appointmentId]);
    if ($changed && $newStatus === 'Refunded') {
        PaymentLog::record($pdo, 'refunded', $appointmentId, $reference, $viaPaymongo ? $refund['id'] : null, $amount, 'Refunded',
            ['method' => $viaPaymongo ? 'PayMongo' : 'manual', 'by_user_id' => $_SESSION['user_id']]);
        CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'PAYMENT_REFUNDED',
            'Your ₱' . number_format($amount, 2) . " reservation deposit for {$reference} has been refunded"
            . ($viaPaymongo ? ' to your original payment method. It may take a few business days to appear.' : '.'));
    }
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'reference' => $reference,
        'paymentStatus' => $newStatus,
        'message' => $newStatus === 'Refunded'
            ? ($viaPaymongo ? 'Refund completed through PayMongo.' : 'Deposit marked as refunded.')
            : 'Refund sent to PayMongo and is still processing. Click "Check refund" later to update it.',
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('refundDeposit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while refunding the deposit.']);
}
