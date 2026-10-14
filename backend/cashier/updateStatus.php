<?php

/**
 * Cashier: Update Service Status
 *
 * Drives the "Confirmed / In Progress / Completed" workflow buttons in the
 * checkout terminal's invoice panel -- this tracks whether the stylist has
 * started/finished the service, which is independent of payment.php
 * actually collecting money (a ticket can be marked Completed here and
 * still be sitting unpaid in the queue). Cancelling/No-Show stays an
 * admin-only action (backend/admin/updateBookingStatus.php).
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/CustomerNotifier.php';
require_once '../config/EodLock.php';
require_once '../config/CancellationPolicy.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$referenceCode = trim($_POST['id'] ?? '');
$status = trim($_POST['status'] ?? '');
$allowedStatuses = ['Confirmed', 'In Progress', 'Completed', 'Cancelled', 'Reschedule Requested'];

// "Payment Needs Attention" (Checkout & Payments) flags a self-reported
// payment as bad without otherwise touching the booking's workflow status --
// so this is the one case where $status is allowed to be empty.
$paymentAttentionOnly = trim($_POST['paymentAction'] ?? '') === 'needs_attention';
// "Verify" on a booking that isn't Pending (e.g. Reschedule Requested)
// records the payment without moving the booking to Confirmed.
$paymentVerifyOnly = trim($_POST['paymentAction'] ?? '') === 'verify';

if ($referenceCode === '' || (!$paymentAttentionOnly && !$paymentVerifyOnly &&!in_array($status, $allowedStatuses, true))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking reference or status.']);
    exit();
}

// Confirming a reservation (e.g. the "Admit Client" action) requires
// recording a deposit -- same policy as backend/admin/updateBookingStatus.php.
$allowedDepositMethods = ['Cash', 'GCash', 'Maya', 'PayMongo'];
$depositAmount = null;
$depositMethod = null;
if ($status === 'Confirmed' || $paymentVerifyOnly) {
    $depositAmount = filter_var($_POST['depositAmount'] ?? '', FILTER_VALIDATE_FLOAT);
    $depositMethod = trim($_POST['depositMethod'] ?? '');
    if ($depositAmount === false || $depositAmount <= 0 || !in_array($depositMethod, $allowedDepositMethods, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A deposit amount and payment method are required to confirm a booking.']);
        exit();
    }
}

try {
    $pdo = Database::getInstance();

    // A Cashier can only touch their own branch's bookings -- see
    // database/migrations/007_cashier_branch_lock.sql. A reference code
    // that belongs to another branch just won't match, same as not found.
    $isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';
    if ($isCashier) {
        $stmt = $pdo->prepare('SELECT ' . CancellationPolicy::COLUMNS . ', branch_id, total_price, reservation_amount_due FROM appointments WHERE reference_code = ? AND branch_id = ? LIMIT 1');
        $stmt->execute([$referenceCode, $_SESSION['branch_id'] ?? 0]);
    } else {
        $stmt = $pdo->prepare('SELECT ' . CancellationPolicy::COLUMNS . ', branch_id, total_price, reservation_amount_due FROM appointments WHERE reference_code = ? LIMIT 1');
        $stmt->execute([$referenceCode]);
    }
    $appointment = $stmt->fetch();
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }
    $appointmentCustomerId = (int) $appointment['customer_id'];

    $appointmentDate = substr($appointment['appointment_datetime'], 0, 10);
    if ($isCashier && EodLock::isDateLocked($pdo, (int) $appointment['branch_id'], $appointmentDate)) {
        http_response_code(423);
        echo json_encode(['success' => false, 'message' => 'This business day is closed. Ask an administrator to reopen it to make changes.']);
        exit();
    }

    // A service can only start on its appointment day -- starting a future
    // booking early made the stylist show "With a client" days ahead.
    if ($status === 'In Progress') {
        $stmt = $pdo->prepare('SELECT DATE(?) > CURDATE()');
        $stmt->execute([$appointment['appointment_datetime']]);
        if ((int) $stmt->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This appointment is on a later date -- it can only be started on the day itself.']);
            exit();
        }
    }

    if ($paymentAttentionOnly) {
        $pdo->prepare("UPDATE appointments SET payment_status = 'Rejected' WHERE id = ?")->execute([$appointment['id']]);
        CustomerNotifier::notify($pdo, $appointmentCustomerId, 'PAYMENT_ATTENTION', "Your payment for {$referenceCode} needs attention. Please contact the branch.");
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
            ->execute(["Payment for booking {$referenceCode} flagged as needing attention."]);
        echo json_encode(['success' => true, 'message' => 'Payment flagged as needing attention.']);
        exit();
    }

    if ($paymentVerifyOnly) {
        if (in_array($appointment['status'], ['Cancelled', 'Completed'], true)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This booking can no longer have its reservation payment verified.']);
            exit();
        }
        $minimum = (float) ($appointment['reservation_amount_due'] ?? $appointment['deposit_amount'] ?? 0);
        $total = (float) $appointment['total_price'];
        if (!is_finite((float) $depositAmount) || $depositAmount < $minimum - 0.001 || $depositAmount > $total + 0.001) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The verified payment must cover Pay Now and cannot exceed the booking total.']);
            exit();
        }
        $resolvedPaymentStatus = round($depositAmount * 100) >= round($total * 100)
            ? 'Fully Paid' : 'Down Payment Verified';
        $pdo->prepare('UPDATE appointments SET payment_status = ?, deposit_paid = 1, deposit_amount = ?, deposit_method = ?, deposit_recorded_by = ?, deposit_recorded_at = NOW() WHERE id = ?')
            ->execute([$resolvedPaymentStatus, $depositAmount, $depositMethod, $_SESSION['user_id'], $appointment['id']]);
        $paymentVerifiedLabel = $resolvedPaymentStatus === 'Fully Paid' ? 'Paid in Full' : 'Reservation Payment Received';
        CustomerNotifier::notify($pdo, $appointmentCustomerId, 'PAYMENT_VERIFIED', "Your payment for {$referenceCode} has been verified. Status: {$paymentVerifiedLabel}.");
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
            ->execute(["Payment for booking {$referenceCode} verified."]);
        echo json_encode(['success' => true, 'message' => 'Payment verified.']);
        exit();
    }

    if ($status === 'Confirmed') {
        if ($appointment['employee_id'] !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE branch_id = ? AND employee_id = ? AND appointment_datetime = ? AND status = 'Confirmed' AND id != ?");
            $stmt->execute([$appointment['branch_id'], $appointment['employee_id'], $appointment['appointment_datetime'], $appointment['id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'This stylist already has a confirmed booking at this exact date and time. Reassign staff or resolve the conflict first.']);
                exit();
            }
        }

        // Verify against the original backend quote, even if the catalog changes later.
        $minimum = (float) ($appointment['reservation_amount_due'] ?? $appointment['deposit_amount'] ?? 0);
        $total = (float) $appointment['total_price'];
        if (!is_finite((float) $depositAmount) || $depositAmount < $minimum - 0.001 || $depositAmount > $total + 0.001) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The verified payment must cover Pay Now and cannot exceed the booking total.']);
            exit();
        }
        $resolvedPaymentStatus = round($depositAmount * 100) >= round($total * 100)
            ? 'Fully Paid' : 'Down Payment Verified';

        $pdo->prepare('UPDATE appointments SET status = ?, payment_status = ?, deposit_paid = 1, deposit_amount = ?, deposit_method = ?, deposit_recorded_by = ?, deposit_recorded_at = NOW() WHERE id = ?')
            ->execute([$status, $resolvedPaymentStatus, $depositAmount, $depositMethod, $_SESSION['user_id'], $appointment['id']]);
        $paymentVerifiedLabel = $resolvedPaymentStatus === 'Fully Paid' ? 'Paid in Full' : 'Reservation Payment Received';
        CustomerNotifier::notify($pdo, $appointmentCustomerId, 'PAYMENT_VERIFIED', "Your payment for {$referenceCode} has been verified. Status: {$paymentVerifiedLabel}.");
        CustomerNotifier::notify($pdo, $appointmentCustomerId, 'CONFIRMED', "Your appointment {$referenceCode} is now Confirmed.");
    } else {
        $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $appointment['id']]);
        if ($status === 'Completed') {
            CustomerNotifier::notify($pdo, $appointmentCustomerId, 'COMPLETED', "Your appointment {$referenceCode} is now Completed. Thank you for choosing us!");
        } elseif ($status === 'Cancelled') {
            CustomerNotifier::notify($pdo, $appointmentCustomerId, 'CANCELLED', "Your appointment {$referenceCode} has been cancelled by the salon.");
            if ($appointment['status'] !== 'Cancelled') {
                CancellationPolicy::apply($pdo, $appointment, CancellationPolicy::BY_SALON);
            }
        } elseif ($status === 'Reschedule Requested') {
            CustomerNotifier::notify($pdo, $appointmentCustomerId, 'RESCHEDULE', "Your appointment {$referenceCode} needs a schedule change. Please check the details.");
        }
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["Booking {$referenceCode} status updated to {$status}."]);

    echo json_encode(['success' => true, 'message' => 'Status updated.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier updateStatus error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the status.']);
}
