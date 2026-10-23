<?php

require_once '../../shared/backend/config/cors.php';
require_once '../../shared/backend/config/session.php';
require_once '../../shared/backend/config/database.php';
require_once '../../shared/backend/config/AuditLog.php';
require_once '../../shared/backend/config/CustomerNotifier.php';
require_once '../../shared/backend/config/StaffNotifier.php';
require_once '../../shared/backend/config/CancellationPolicy.php';
require_once '../../shared/backend/config/Scheduling.php';
require_once '../../shared/backend/config/HomeServiceRequest.php';
require_once '../../shared/backend/config/ReservationPayment.php';

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

require_once __DIR__ . '/../../shared/backend/config/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'depositAmount' => ['money', false, 'an amount with at most two decimal places'],
]);

$referenceCode = trim($_POST['id'] ?? '');
$status = trim($_POST['status'] ?? '');
// A staff-supplied payment status is only ever honored for the three
// "problem" outcomes below -- the "good" outcomes (Awaiting Verification,
// Down Payment Verified, Fully Paid) are always derived by the backend
// from real business state (the deposit that was actually recorded, and
// which services require full vs. partial payment), never picked by a
// staff member out of thin air, and never settable by the customer at all
// (this endpoint is Admin-only, see the role check above).
$staffPaymentStatus = trim($_POST['paymentStatus'] ?? '');
$allowedStaffPaymentStatuses = ['Rejected', 'Refunded', 'Forfeited'];

if ($referenceCode === '' || $status === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking reference or status.']);
    exit();
}

if ($staffPaymentStatus !== '' && !in_array($staffPaymentStatus, $allowedStaffPaymentStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment status.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    // Home service requests are folded into the same bookings list the
    // frontend renders (see getDashboardData.php) and use their own status
    // enum ('Pending Review'/'Confirmed'/'Completed'/'Cancelled' -- no
    // 'In Progress'). Unlike salon appointments there's no separate
    // deposit-entry modal for these -- the deposit was already self-reported
    // by the customer at request time (see submitGuestHomeService.php /
    // submitHomeServiceRequest.php), so confirming here also verifies it
    // (staff are expected to have checked the reference beforehand, same
    // trust model as the deposit modal's "Verify & Confirm").
    $stmt = $pdo->prepare('SELECT id, customer_id, employee_id, deposit_paid, deposit_recorded_by, deposit_amount, deposit_method, deposit_reference FROM home_service_requests WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$referenceCode]);
    $homeService = $stmt->fetch();
    if ($homeService) {
        $allowedHomeStatuses = [
            'Pending Review', 'Quote Ready', 'Payment Required', 'Payment Being Verified',
            'Confirmed', 'Staff Assigned', 'Service in Progress', 'Completed', 'Cancelled',
        ];
        if (!in_array($status, $allowedHomeStatuses, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid status for a home service request.']);
            exit();
        }

        $homeServiceId = $homeService['id'];
        $pdo->beginTransaction();
        $homeService = HomeServiceRequest::lock($pdo, (int) $homeServiceId);
        if ($status === 'Completed') HomeServiceRequest::validateCompletion($homeService);
        if (in_array($status, ['Confirmed','Staff Assigned','Service in Progress'], true)) {
            if (in_array($homeService['status'], ['Cancelled','Completed'], true)) throw new RuntimeException('This closed request cannot be confirmed.');
            $team = HomeServiceRequest::team($pdo, $homeService);
            if ($status !== 'Confirmed' && !$team) throw new RuntimeException('Please assign an available qualified team first.');
            HomeServiceRequest::requireAvailable($pdo, $homeService, $team ?: null);
            if ($homeService['quote_price'] === null) throw new RuntimeException('Please approve a quote before confirmation.');
            if ((float) $homeService['deposit_amount'] > 0 && !$homeService['deposit_paid']) throw new RuntimeException('The required reservation payment has not been received.');
        }
        $homeServiceCustomerId = (int) $homeService['customer_id'];

        if ($status === 'Confirmed' && $homeService['deposit_paid'] && $homeService['deposit_recorded_by'] === null && $homeService['payment_status'] === 'Awaiting Verification') {
            // A home service request has no per-service catalog to check for a
            // "Full Payment" requirement (unlike salon appointments), so
            // confirming one always resolves to 'Down Payment Verified'.
            $pdo->prepare("UPDATE home_service_requests SET status = ?, payment_status = 'Down Payment Verified', deposit_recorded_by = ?, deposit_recorded_at = NOW() WHERE id = ?")
                ->execute([$status, $_SESSION['user_id'], $homeServiceId]);
            // Verified DP becomes a payment record (home_service_payments, migration 049).
            if ((float) $homeService['deposit_amount'] > 0) {
                $pdo->prepare("INSERT INTO home_service_payments (home_service_request_id, kind, amount, method, reference, recorded_by) VALUES (?, 'deposit', ?, ?, ?, ?)")
                    ->execute([$homeServiceId, $homeService['deposit_amount'], $homeService['deposit_method'] ?: 'Cash', $homeService['deposit_reference'], $_SESSION['user_id']]);
            }
            CustomerNotifier::notify($pdo, $homeServiceCustomerId, 'PAYMENT_VERIFIED', "Your reservation payment for {$referenceCode} has been verified. Status: Reservation Payment Received.");
            CustomerNotifier::notify($pdo, $homeServiceCustomerId, 'CONFIRMED', "Your home service request {$referenceCode} is now Confirmed.");
        } elseif ($staffPaymentStatus !== '') {
            $pdo->prepare('UPDATE home_service_requests SET status = ?, payment_status = ? WHERE id = ?')
                ->execute([$status, $staffPaymentStatus, $homeServiceId]);
            if ($staffPaymentStatus === 'Rejected') {
                CustomerNotifier::notify($pdo, $homeServiceCustomerId, 'PAYMENT_ATTENTION', "Your reservation payment for {$referenceCode} needs attention. Please contact the branch.");
            }
        } else {
            $pdo->prepare('UPDATE home_service_requests SET status = ? WHERE id = ?')->execute([$status, $homeServiceId]);
            if ($status === 'Cancelled') {
                CustomerNotifier::notify($pdo, $homeServiceCustomerId, 'CANCELLED', "Your home service request {$referenceCode} has been cancelled.");
                if ($homeService['employee_id'] !== null) {
                    StaffNotifier::notify($pdo, (int) $homeService['employee_id'], 'APPOINTMENT_CANCELLED', "Home service request {$referenceCode} was cancelled.");
                }
            } elseif ($status === 'Completed') {
                CustomerNotifier::notify($pdo, $homeServiceCustomerId, 'COMPLETED', "Your home service request {$referenceCode} is now Completed. Thank you for choosing us!");
            }
        }

        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
            ->execute(["Home service request {$referenceCode} status updated to {$status}."]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Request status updated.']);
        exit();
    }

    $allowedStatuses = ['Pending', 'Confirmed', 'In Progress', 'Completed', 'Cancelled', 'Reviewed', 'No-Show', 'Reschedule Requested', 'Reschedule Required'];
    if (!in_array($status, $allowedStatuses, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid booking reference or status.']);
        exit();
    }

    // Confirming a reservation requires recording a deposit -- an unpaid Pending
    // booking stays soft/adjustable, but a deposit "locks" it and gives it
    // priority over any other unpaid claim on the same stylist/time.
    $allowedDepositMethods = ['Cash', 'GCash', 'Maya', 'PayMongo'];
    $depositAmount = null;
    $depositMethod = null;
    if ($status === 'Confirmed') {
        $depositAmount = filter_var($_POST['depositAmount'] ?? '', FILTER_VALIDATE_FLOAT);
        $depositMethod = trim($_POST['depositMethod'] ?? '');
        if (($depositAmount !== false || $depositMethod !== '') && ($depositAmount === false || $depositAmount <= 0 || !in_array($depositMethod, $allowedDepositMethods, true))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A deposit amount and payment method are required to confirm a booking.']);
            exit();
        }
    }

    $stmt = $pdo->prepare('SELECT ' . CancellationPolicy::COLUMNS . ', branch_id, total_price, reservation_amount_due FROM appointments WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$referenceCode]);
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
    $statusMessage = null;
    if (filter_var($_POST['arrivalReview'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $review = Scheduling::arrivalReview($pdo, $appointment);
        AuditLog::record($pdo, 'booking/arrival_review', 'Reviewed arrival', $referenceCode,
            'Arrival reviewed; no booking or payment changed.', $review);
        $pdo->commit();
        echo json_encode(['success' => true, 'review' => $review]);
        exit();
    }
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

    // Cancelled/No-Show trigger the deposit policy and waitlist alerts only
    // on the transition itself, never when re-saving an already-closed booking.
    $isNewClosure = in_array($status, ['Cancelled', 'No-Show'], true) && $appointment['status'] !== $status;




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
    } elseif ($staffPaymentStatus !== '') {
        $pdo->prepare('UPDATE appointments SET status = ?, payment_status = ? WHERE id = ?')
            ->execute([$status, $staffPaymentStatus, $appointment['id']]);
        if ($staffPaymentStatus === 'Rejected') {
            CustomerNotifier::notify($pdo, $appointmentCustomerId, 'PAYMENT_ATTENTION', "Your payment for {$referenceCode} needs attention. Please contact the branch.");
        }
        if (in_array($staffPaymentStatus, ['Refunded', 'Forfeited'], true) && $appointment['payment_status'] !== $staffPaymentStatus) {
            PaymentLog::record($pdo, $staffPaymentStatus === 'Refunded' ? 'refunded' : 'deposit_forfeited', (int) $appointment['id'], $referenceCode,
                null, (float) $appointment['deposit_amount'], $staffPaymentStatus, ['by' => 'admin', 'user_id' => $_SESSION['user_id']]);
            if ($staffPaymentStatus === 'Refunded') {
                CustomerNotifier::notify($pdo, $appointmentCustomerId, 'PAYMENT_REFUNDED',
                    'Your ₱' . number_format((float) $appointment['deposit_amount'], 2) . " reservation deposit for {$referenceCode} has been refunded.");
            }
        }
        if ($isNewClosure) {
            // Staff picked the deposit outcome explicitly -- skip the automatic
            // policy, but still record the closure and free the stylist.
            $pdo->prepare('UPDATE appointments SET cancelled_at = COALESCE(cancelled_at, NOW()) WHERE id = ?')->execute([$appointment['id']]);
            if ($appointment['employee_id'] !== null) {
                Waitlist::notifyOpening($pdo, (int) $appointment['employee_id'], $appointment['appointment_datetime']);
            }
        }
    } else {
        $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $appointment['id']]);
        if ($status === 'Cancelled') {
            CustomerNotifier::notify($pdo, $appointmentCustomerId, 'CANCELLED', "Your appointment {$referenceCode} has been cancelled by the salon.");
            if ($appointment['employee_id'] !== null) {
                StaffNotifier::notify($pdo, (int) $appointment['employee_id'], 'APPOINTMENT_CANCELLED', "Appointment {$referenceCode} was cancelled.");
            }
            if ($isNewClosure) {
                CancellationPolicy::apply($pdo, $appointment, CancellationPolicy::BY_SALON);
            }
        } elseif ($status === 'No-Show') {
            if ($isNewClosure) {
                CancellationPolicy::apply($pdo, $appointment, CancellationPolicy::NO_SHOW);
            }
        } elseif ($status === 'Completed') {
            CustomerNotifier::notify($pdo, $appointmentCustomerId, 'COMPLETED', "Your appointment {$referenceCode} is now Completed. Thank you for choosing us!");
        } elseif (in_array($status, ['Reschedule Requested', 'Reschedule Required'], true)) {
            CustomerNotifier::notify($pdo, $appointmentCustomerId, 'RESCHEDULE', "Your appointment {$referenceCode} needs a schedule change. Please check the details.");
            if ($appointment['employee_id'] !== null) {
                StaffNotifier::notify($pdo, (int) $appointment['employee_id'], 'SCHEDULE_CHANGED', "Appointment {$referenceCode}'s schedule needs a change.");
            }
        }
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["Booking {$referenceCode} status updated to {$status}."]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => $statusMessage ?? 'Booking status updated.']);
} catch (PDOException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!($e instanceof PDOException) || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? Scheduling::STAFF_UNAVAILABLE_MESSAGE : $e->getMessage()]);
        exit();
    }
    http_response_code(500);
    error_log('admin updateBookingStatus error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the booking.']);
}
