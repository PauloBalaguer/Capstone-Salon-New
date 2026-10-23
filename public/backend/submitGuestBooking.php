<?php

/**
 * Public: Submit Guest Booking
 *
 * No login required — this is the "zero mandatory upfront accounts"
 * booking flow from the landing page. A guest becomes a `customers` row
 * with no linked `user_id` (the schema already supports this); if the
 * phone number they enter already belongs to a known customer (guest or
 * registered), that existing record is reused instead of overwritten.
 */

require_once '../../shared/backend/config/cors.php';
require_once '../../shared/backend/config/database.php';
require_once '../../shared/backend/config/AuditLog.php';
require_once '../../shared/backend/config/RateLimiter.php';
require_once '../../shared/backend/config/Scheduling.php';
require_once '../../shared/backend/config/ServiceDuration.php';
require_once '../../shared/backend/config/ReservationPayment.php';
require_once '../../shared/backend/config/PayMongo.php';
require_once '../../shared/backend/config/url.php';
require_once '../../shared/backend/config/CancellationPolicy.php';

// php.ini on this XAMPP install is Europe/Berlin; "no slots in the past"
// must be judged on the salon's clock.
date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

require_once __DIR__ . '/../../shared/backend/config/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'fullname' => ['name', true, 'your full name'],
    'contact' => ['mobile', true, 'a mobile number (09XXXXXXXXX)'],
    'branch' => ['id', true, 'a staff or branch selection'],
    'services' => ['ids', true, 'service or staff selections'],
    'appointment_date' => ['date', true, 'an appointment date'],
    'time_slot' => ['time', true, 'an appointment time'],
    'staff_id' => ['id', false, 'a staff or branch selection'],
    'service_staff' => ['staffMap', false, 'staff selections for each service'],
    'email' => ['email', false, 'an email address'],
]);

$fullName = trim($_POST['fullname'] ?? '');
$contact = trim($_POST['contact'] ?? '');
$branchId = (int) ($_POST['branch'] ?? 0);
$date = trim($_POST['appointment_date'] ?? '');
$time = trim($_POST['time_slot'] ?? '');
$serviceIds = $_POST['services'] ?? [];
$email = trim($_POST['email'] ?? '');
$otpCode = trim($_POST['otp_code'] ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? '');
$staffId = (int) ($_POST['staff_id'] ?? 0);
// Payment amounts are always calculated from the database, never POST data.
$agreedToTerms = filter_var($_POST['agreedToTerms'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentPlan = ($_POST['payment_plan'] ?? 'deposit') === 'full' ? 'full' : 'deposit';

if (!is_array($serviceIds)) {
    $serviceIds = [$serviceIds];
}
$serviceIds = array_values(array_filter(array_map('intval', $serviceIds)));

// One stylist per service: service_staff[<serviceId>] = <employeeId>, in the
// same order as services[] (they run back-to-back in that order). A plain
// staff_id (one stylist for everything) is still accepted.
$serviceStaff = [];
foreach ((array) ($_POST['service_staff'] ?? []) as $svc => $emp) {
    if ((int) $svc > 0 && (int) $emp > 0) $serviceStaff[(int) $svc] = (int) $emp;
}
foreach ($serviceIds as $svc) {
    if (!isset($serviceStaff[$svc]) && $staffId > 0) $serviceStaff[$svc] = $staffId;
}
if ($serviceStaff && $staffId <= 0) $staffId = $serviceStaff[$serviceIds[0] ?? 0] ?? 0;

// Online reservation payments go through PayMongo only; Cash is paid in person at the branch.
$allowedPaymentMethods = ['Cash', PayMongo::METHOD];

// Only Full Name and Contact Number are required to book as a guest -- no
// account needed. Email is optional; when the guest does provide one, it's
// verified by OTP as an extra confirmation channel, but its absence never
// blocks a booking.
if ($fullName === '' || $contact === '' || $branchId <= 0 || $date === '' || $time === '' || empty($serviceIds)
    || !in_array($paymentMethod, $allowedPaymentMethods, true) || $staffId <= 0
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in your name, contact number, branch, date, time, staff selection, reservation deposit, and at least one service.']);
    exit();
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address, or leave it blank.']);
    exit();
}

if ($email !== '' && $otpCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter the verification code sent to your email.']);
    exit();
}

if ($paymentMethod === PayMongo::METHOD && !PayMongo::isConfigured()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Online payment is not available right now. Please choose another payment method.']);
    exit();
}

if (!$agreedToTerms) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please agree to the Terms and Conditions to continue.']);
    exit();
}

[$firstName, $lastName] = array_pad(explode(' ', $fullName, 2), 2, '');

$ip_address = RateLimiter::getIpAddress();
$otp_fail_limiter = new RateLimiter($ip_address, 'submit_booking_otp_fail', 10, 900);

if ($otp_fail_limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many verification attempts. Please try again in 15 minutes.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    // Email is optional, so OTP verification only applies when one was given.
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT code, expires_at FROM booking_verifications WHERE email = ?');
        $stmt->execute([$email]);
        $verification = $stmt->fetch();

        if (!$verification || !hash_equals($verification['code'], $otpCode) || new DateTime() > new DateTime($verification['expires_at'])) {
            $otp_fail_limiter->record();
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired verification code.']);
            exit();
        }

        $otp_fail_limiter->clear();
    }

    $stmt = $pdo->prepare('SELECT id, branch_key, branch_name FROM branches WHERE id = ?');
    $stmt->execute([$branchId]);
    $branch = $stmt->fetch();
    if (!$branch) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare("SELECT id, service_name, price, payment_requirement FROM services WHERE branch_id = ? AND id IN ($placeholders) AND is_active = 1");
    $stmt->execute([$branchId, ...$serviceIds]);
    $services = $stmt->fetchAll();
    if (count($services) !== count($serviceIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'One or more selected services are invalid for this branch.']);
        exit();
    }
    $subtotal = array_sum(array_map(fn($s) => (float) $s['price'], $services));
    $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));

    $quote = ReservationPayment::quote($services, 0, false, $paymentPlan);
    $subtotal = $quote['serviceTotal'];
    $depositAmount = $quote['amountDue'];
    if (isset($_POST['quoteToken']) && !hash_equals($quote['quoteToken'], (string) $_POST['quoteToken'])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'quoteChanged' => true, 'message' => 'Your service price or payment requirement changed. Please review Pay Now again.']);
        exit();
    }
    // PayMongo bookings are inserted unpaid; the deposit is recorded once
    // PayMongo reports the checkout paid (see shared/backend/config/PayMongo.php).
    $payOnline = $paymentMethod === PayMongo::METHOD && $depositAmount > 0;
    if ($payOnline && $depositAmount < PayMongo::MIN_AMOUNT) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Online payment needs at least ₱' . number_format(PayMongo::MIN_AMOUNT, 2) . '. Please choose another payment method.']);
        exit();
    }

    $appointmentTimestamp = strtotime("$date $time");
    if ($appointmentTimestamp === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date or time selected.']);
        exit();
    }
    if ($appointmentTimestamp <= time()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'That time has already passed. Please pick a later slot.']);
        exit();
    }
    $appointmentDateTime = date('Y-m-d H:i:s', $appointmentTimestamp);
    $durationMinutes = Scheduling::totalDurationMinutes($pdo, $serviceIds);

    if (Scheduling::isOutsideOperatingHours($appointmentDateTime, $durationMinutes, $branch['branch_key'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This booking falls outside the selected branch operating hours. Please choose another time.']);
        exit();
    }

    PayMongo::releaseExpiredHolds($pdo);

    $pdo->beginTransaction();

    // Locking read: serializes concurrent bookings for this branch+day so two
    // simultaneous requests can't both slip past the slot limit. Duration-aware:
    // a long treatment occupies every slot it overlaps, not just its start time.
    if (Scheduling::branchWindowIsFull($pdo, $branchId, $appointmentDateTime, $durationMinutes)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => Scheduling::SLOT_UNAVAILABLE_MESSAGE]);
        exit();
    }

    // Re-validate the guest's chosen staff member server-side (mirrors
    // getAvailableStaff.php): must be active at this branch, must cover
    // every selected service, and must have no overlapping appointment
    // during this window. Never trust the client's staff pick blindly --
    // availability may have changed since getAvailableStaff.php was called.
    // One stylist per service: services run back-to-back in the submitted
    // order, and each chosen stylist must work at this branch, offer that
    // service, and be free for that service's own window.
    $durations = [];
    foreach ($services as $service) $durations[(int) $service['id']] = 0;
    $stmt = $pdo->prepare("SELECT id, duration_minutes FROM services WHERE id IN ($servicePlaceholders)");
    $stmt->execute($serviceIds);
    foreach ($stmt->fetchAll() as $row) {
        $durations[(int) $row['id']] = (int) $row['duration_minutes'] > 0 ? (int) $row['duration_minutes'] : Scheduling::DEFAULT_DURATION_MINUTES;
    }
    $employeeCheck = $pdo->prepare('SELECT CONCAT(e.first_name, " ", e.last_name) FROM employees e JOIN staff_services ss ON ss.employee_id = e.id AND ss.service_id = ? WHERE e.id = ? AND e.branch_id = ? AND e.is_active = 1');
    $segmentStart = new DateTime($appointmentDateTime);
    foreach ($serviceIds as $serviceId) {
        $stylistId = $serviceStaff[$serviceId] ?? 0;
        $employeeCheck->execute([$serviceId, $stylistId, $branchId]);
        $stylistName = $employeeCheck->fetchColumn();
        if (!$stylistId || $stylistName === false) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Selected staff member cannot perform this service at this branch. Please choose a qualified stylist for every service.']);
            exit();
        }
        $until = Scheduling::staffConflictUntil($pdo, $stylistId, $segmentStart->format('Y-m-d H:i:s'), $durations[$serviceId]);
        if ($until !== null) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => Scheduling::SLOT_UNAVAILABLE_MESSAGE]);
            exit();
        }
        $segmentStart->modify("+{$durations[$serviceId]} minutes");
    }

    // The booking's main stylist is whoever does the first service.
    $employeeId = $serviceStaff[$serviceIds[0]];

    // Reuse an existing customer record by phone, but only silently attach to
    // one with a linked (registered) account if the OTP-verified email
    // matches that account's email on file. Otherwise a stranger who merely
    // knows someone's phone number could get a booking attached to that
    // person's real account.
    $stmt = $pdo->prepare('SELECT c.id, u.email FROM customers c LEFT JOIN users u ON u.id = c.user_id WHERE c.phone_number = ?');
    $stmt->execute([$contact]);
    $existingCustomer = $stmt->fetch();

    if ($existingCustomer && $existingCustomer['email'] !== null && strcasecmp($existingCustomer['email'], $email) !== 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This mobile number is linked to an existing account. Please log in to book, or use the mobile number and email on that account.']);
        exit();
    }

    if ($existingCustomer) {
        $customerId = $existingCustomer['id'];
    } else {
        $ins = $pdo->prepare('INSERT INTO customers (first_name, last_name, phone_number) VALUES (?, ?, ?)');
        $ins->execute([$firstName, $lastName, $contact]);
        $customerId = $pdo->lastInsertId();
    }

    $referenceCode = ''; // Generated atomically by the database insert trigger.
    $notes = "Guest booking for {$fullName}, Phone: {$contact}" . ($email !== '' ? ", Email: {$email}" : '');

    // deposit_recorded_by stays NULL until staff confirm the booking in
    // admin/backend/updateBookingStatus.php (or the cashier equivalent). A
    // Cash deposit is collected in person; a PayMongo deposit is recorded
    // automatically once PayMongo reports the checkout paid.
    $ins = $pdo->prepare('
        INSERT INTO appointments (
            reference_code, customer_id, employee_id, branch_id, appointment_datetime, total_price,
            preferred_payment_method, deposit_paid, deposit_amount, deposit_method, deposit_reference, deposit_recorded_at,
            status, payment_status, reminder_sent, notes, terms_accepted_at, reservation_requirement, reservation_amount_due, payment_plan
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL), "Pending", ?, 0, ?, NOW(), ?, ?, ?)
    ');
    $ins->execute([
        $referenceCode, $customerId, $employeeId, $branchId, $appointmentDateTime, $subtotal,
        $paymentMethod, $payOnline ? 0 : 1, $payOnline ? null : $depositAmount, $payOnline ? null : $paymentMethod,
        null, $payOnline ? 0 : 1,
        $payOnline ? 'Payment Required' : 'Awaiting Verification', $notes, $quote['reservationRequirement'], $depositAmount, $quote['paymentPlan'],
    ]);
    $appointmentId = $pdo->lastInsertId();
    $refStmt = $pdo->prepare('SELECT reference_code FROM appointments WHERE id = ?');
    $refStmt->execute([$appointmentId]);
    $referenceCode = $refStmt->fetchColumn();

    $checkoutUrl = null;
    if ($payOnline) {
        // Created before commit so a PayMongo failure rolls the booking back
        // instead of leaving an unpayable slot hold behind.
        try {
            $checkoutUrl = PayMongo::startCheckout($pdo, (int) $appointmentId, $referenceCode, $depositAmount, 'guest');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            error_log('submitGuestBooking PayMongo error: ' . $e->getMessage());
            PaymentLog::record($pdo, 'api_error', null, null, null, $depositAmount, 'checkout_failed', $e->getMessage());
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'We could not start the online payment. Please try again or choose another payment method.']);
            exit();
        }
    }

    // In booking order (they run back-to-back), each with its own stylist.
    $linkStmt = $pdo->prepare('INSERT INTO appointment_services (appointment_id, service_id, employee_id) VALUES (?, ?, ?)');
    foreach ($serviceIds as $serviceId) {
        $linkStmt->execute([$appointmentId, $serviceId, $serviceStaff[$serviceId] ?? null]);
    }

    if ($email !== '') {
        $pdo->prepare('DELETE FROM booking_verifications WHERE email = ?')->execute([$email]);
        // OTP-verified above -- lets booking/status emails reach this guest.
        $pdo->prepare('UPDATE customers SET email = ? WHERE id = ? AND user_id IS NULL')->execute([$email, $customerId]);
    }

    CustomerNotifier::notify($pdo, (int) $customerId, 'BOOKING_SUBMITTED', CancellationPolicy::bookingReceivedMessage(
        $referenceCode, array_column($services, 'service_name'), $branch['branch_name'], $appointmentDateTime, $depositAmount, $quote['remainingBalance'], $payOnline));

    // Branch-wide log (admin notification bell) so the salon hears about new online bookings.
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["New guest booking {$referenceCode} from {$fullName} at {$branch['branch_name']} on "
            . date('M j, Y g:i A', strtotime($appointmentDateTime)) . ($depositAmount <= 0 ? '.' : ($payOnline ? ' — online payment in progress.' : ' — payment awaiting verification.'))]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Your appointment request has been received! Reference #: {$referenceCode}. We'll verify your deposit and contact you at {$contact} to confirm.",
        'reference' => $referenceCode,
        'payment' => $quote,
        'checkoutUrl' => $checkoutUrl,
        'durationMinutes' => $durationMinutes,
        'endTime' => ServiceDuration::end($appointmentDateTime, $durationMinutes)->format('g:i A'),
    ]);
} catch (PDOException | InvalidArgumentException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'conflict' => true, 'message' => Scheduling::SLOT_UNAVAILABLE_MESSAGE]);
        exit();
    }
    http_response_code(500);
    error_log('submitGuestBooking error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while booking your appointment.']);
}
