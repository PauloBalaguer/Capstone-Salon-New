<?php

/**
 * Cashier: Update Service Status
 *
 * Drives the "Confirmed / In Progress / Completed" workflow buttons in the
 * checkout terminal's invoice panel -- this tracks whether the stylist has
 * started/finished the service, which is independent of payment.php
 * actually collecting money (a ticket can be marked Completed here and
 * still be sitting unpaid in the queue). Cancelling/No-Show stays an
 * admin-only action (admin/backend/updateBookingStatus.php).
 */

require_once '../../shared/backend/config/cors.php';
require_once '../../shared/backend/config/session.php';
require_once '../../shared/backend/config/database.php';
require_once '../../shared/backend/config/AuditLog.php';
require_once '../../shared/backend/config/CustomerNotifier.php';
require_once '../../shared/backend/config/EodLock.php';
require_once '../../shared/backend/config/CancellationPolicy.php';
require_once '../../shared/backend/config/Scheduling.php';
require_once '../../shared/backend/config/ReservationPayment.php';

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

require_once __DIR__ . '/../../shared/backend/config/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'depositAmount' => ['money', false, 'an amount with at most two decimal places'],
]);

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
// recording a deposit -- same policy as admin/backend/updateBookingStatus.php.
$allowedDepositMethods = ['Cash', 'GCash', 'Maya', 'PayMongo'];
$depositAmount = null;
$depositMethod = null;
if ($status === 'Confirmed' || $paymentVerifyOnly) {
    $depositAmount = filter_var($_POST['depositAmount'] ?? '', FILTER_VALIDATE_FLOAT);
    $depositMethod = trim($_POST['depositMethod'] ?? '');
    if (($depositAmount !== false || $depositMethod !== '') && ($depositAmount === false || $depositAmount <= 0 || !in_array($depositMethod, $allowedDepositMethods, true))) {
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
    $pdo->beginTransaction();
    $appointment = Scheduling::lockAppointment($pdo, (int) $appointment['id']);
    if (in_array($status, ['Confirmed', 'In Progress'], true)) {
        $settled = $pdo->prepare("SELECT id FROM payments WHERE appointment_id = ? AND status = 'Paid' FOR UPDATE");
        $settled->execute([$appointment['id']]);
        if ($settled->fetchColumn()) throw new RuntimeException('This visit has already been checked out. View its receipt instead.');
    }
    $appointmentCustomerId = (int) $appointment['customer_id'];

    $appointmentDate = substr($appointment['appointment_datetime'], 0, 10);
    if ($isCashier && EodLock::isDateLocked($pdo, (int) $appointment['branch_id'], $appointmentDate)) {
        http_response_code(423);
        echo json_encode(['success' => false, 'message' => 'This business day is closed. Ask an administrator to reopen it to make changes.']);
        exit();
    }

    $statusMessage = null;
    if (!$paymentAttentionOnly && !$paymentVerifyOnly && filter_var($_POST['arrivalReview'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $review = Scheduling::arrivalReview($pdo, $appointment);
        AuditLog::record($pdo, 'booking/arrival_review', 'Reviewed arrival', $referenceCode,
            'Arrival reviewed; no booking or payment changed.', $review);
        $pdo->commit();
        echo json_encode(['success' => true, 'review' => $review]);
        exit();
    }
    if (!$paymentAttentionOnly && !$paymentVerifyOnly) {
        CancellationPolicy::validateTransition($pdo, $appointment, $status);
        if ($status === $appointment['status'] && in_array($status, ['Cancelled', 'No-Show', 'In Progress', 'Completed', 'Reviewed'], true)) {
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'This booking is already ' . $status . '. No additional payment action was taken.']);
            exit();
        }
        if ($status === 'In Progress') {
            $statusMessage = Scheduling::startAppointment($pdo, $appointment,
                (int) ($_POST['arrivalStaffId'] ?? 0) ?: null,
                filter_var($_POST['arrivalConfirmed'] ?? false, FILTER_VALIDATE_BOOLEAN));
            AuditLog::record($pdo, 'booking/arrival_started', 'Started service after arrival review', $referenceCode, $statusMessage);
        }
    }


    if ($paymentAttentionOnly) {
        if (in_array($appointment['payment_status'], ['Down Payment Verified', 'Fully Paid'], true)) {
            throw new RuntimeException('This payment is already verified. Refresh payment details.');
        }
        $pdo->prepare("UPDATE appointments SET payment_status = 'Rejected' WHERE id = ?")->execute([$appointment['id']]);
        CustomerNotifier::notify($pdo, $appointmentCustomerId, 'PAYMENT_ATTENTION', "Your payment for {$referenceCode} needs attention. Please contact the branch.");
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
            ->execute(["Payment for booking {$referenceCode} flagged as needing attention."]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Payment flagged as needing attention.']);
        exit();
    }

    if ($paymentVerifyOnly) {
        if (in_array($appointment['payment_status'], ['Down Payment Verified', 'Fully Paid'], true)) {
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Payment was already verified.']);
            exit();
        }
        ReservationPayment::validateVerification($appointment, $depositAmount, $depositMethod);
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
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Payment verified.']);
        exit();
    }

    if ($status === 'Confirmed') {
        Scheduling::validateAppointmentStaff($pdo, $appointment, $appointment['appointment_datetime']);
        if (Scheduling::branchWindowIsFull($pdo, (int) $appointment['branch_id'], $appointment['appointment_datetime'],
            Scheduling::totalDurationMinutes($pdo, array_column($pdo->query('SELECT service_id FROM appointment_services WHERE appointment_id = ' . (int) $appointment['id'])->fetchAll(), 'service_id')), (int) $appointment['id'])) {
            throw new RuntimeException(Scheduling::SLOT_UNAVAILABLE_MESSAGE);
        }

        if (in_array($appointment['payment_status'], ['Down Payment Verified', 'Fully Paid'], true)
            || (!(float) $depositAmount && $appointment['reservation_amount_due'] !== null && (float) $appointment['reservation_amount_due'] <= 0)) {
            // Confirmation must not rewrite an already verified payment.
            $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $appointment['id']]);
        } else {
            ReservationPayment::validateVerification($appointment, $depositAmount, $depositMethod);
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
        }
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

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => $statusMessage ?? 'Status updated.']);
} catch (PDOException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!($e instanceof PDOException) || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? Scheduling::STAFF_UNAVAILABLE_MESSAGE : $e->getMessage()]);
        exit();
    }
    http_response_code(500);
    error_log('cashier updateStatus error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the status.']);
}
