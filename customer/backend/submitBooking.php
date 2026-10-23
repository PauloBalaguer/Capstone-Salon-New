<?php

/**
 * Submit Booking API Endpoint
 *
 * Creates a new appointment for the logged-in customer: validates the
 * selected services/date/time/slot capacity, optionally applies a loyalty
 * point discount, assigns staff, and returns the created appointment in
 * the same shape the dashboard already renders.
 */

require_once '../../shared/backend/config/cors.php';
require_once '../../shared/backend/config/session.php';
require_once '../../shared/backend/config/database.php';
require_once '../../shared/backend/config/AuditLog.php';
require_once '../../shared/backend/config/Scheduling.php';
require_once '../../shared/backend/config/ServiceDuration.php';
require_once '../../shared/backend/config/ReservationPayment.php';
require_once '../../shared/backend/config/CustomerNotifier.php';
require_once '../../shared/backend/config/PayMongo.php';
require_once '../../shared/backend/config/url.php';
require_once '../../shared/backend/config/CancellationPolicy.php';

// php.ini on this XAMPP install is Europe/Berlin; "no slots in the past"
// must be judged on the salon's clock.
date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to book an appointment.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

require_once __DIR__ . '/../../shared/backend/config/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'branch' => ['text', true, 'branch'],
    'serviceIds' => ['ids', true, 'service or staff selections'],
    'date' => ['date', true, 'an appointment date'],
    'time' => ['time', true, 'an appointment time'],
    'staffId' => ['id', false, 'a staff or branch selection'],
    'customerName' => ['name', true, 'your full name'],
    'customerPhone' => ['mobile', true, 'a mobile number (09XXXXXXXXX)'],
]);

$branchKey = trim($_POST['branch'] ?? '');
$serviceIds = $_POST['serviceIds'] ?? [];
$date = trim($_POST['date'] ?? '');
$time = trim($_POST['time'] ?? '');
$staffId = trim($_POST['staffId'] ?? '');
$customerName = trim($_POST['customerName'] ?? '');
$customerPhone = trim($_POST['customerPhone'] ?? '');
$paymentMethod = trim($_POST['paymentMethod'] ?? '');
// Payment amounts are always calculated from the database, never POST data.
$useLoyaltyPoints = filter_var($_POST['useLoyaltyPoints'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentPlan = ($_POST['paymentPlan'] ?? 'deposit') === 'full' ? 'full' : 'deposit';
$agreedToTerms = filter_var($_POST['agreedToTerms'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!is_array($serviceIds)) {
    $serviceIds = [$serviceIds];
}
$serviceIds = array_values(array_filter(array_map('intval', $serviceIds)));

// Online reservation payments go through PayMongo only; Cash is paid in person at the branch.
$allowedPaymentMethods = ['Cash', PayMongo::METHOD];

if ($branchKey === '' || empty($serviceIds) || $date === '' || $time === '' || $customerName === '' || $customerPhone === ''
    || !in_array($paymentMethod, $allowedPaymentMethods, true)
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide branch, services, date, time, reservation deposit, and contact details.']);
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

define('LOYALTY_POINT_VALUE', 0.1);

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT id, loyalty_points FROM customers WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer profile not found.']);
        exit();
    }
    $customerId = (int) $customer['id'];

    $stmt = $pdo->prepare('SELECT id, branch_name FROM branches WHERE branch_key = ? LIMIT 1');
    $stmt->execute([$branchKey]);
    $branch = $stmt->fetch();
    if (!$branch) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }
    $branchId = (int) $branch['id'];

    // Validate the selected services actually belong to this branch, and total their price.
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

    if (Scheduling::isOutsideOperatingHours($appointmentDateTime, $durationMinutes, $branchKey)) {
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
        echo json_encode(['success' => false, 'conflict' => true, 'message' => Scheduling::SLOT_UNAVAILABLE_MESSAGE]);
        exit();
    }

    // --- Staff assignment: never double-book a specific stylist across any
    // overlapping window (not just the same exact start time); when
    // auto-assigning, prefer whoever has fewest bookings that day instead of
    // always the same lowest-id employee. ---
    $stmt = $pdo->prepare("
        SELECT e.id, e.first_name, e.last_name
        FROM employees e
        WHERE e.branch_id = ? AND e.is_active = 1
          AND (SELECT COUNT(DISTINCT service_id) FROM staff_services WHERE employee_id = e.id AND service_id IN ($servicePlaceholders)) = ?
          " . ($staffId !== '' ? 'AND e.id = ?' : '') . "
        ORDER BY (
            SELECT COUNT(*) FROM appointments a2
            WHERE a2.employee_id = e.id AND DATE(a2.appointment_datetime) = DATE(?) AND a2.status != 'Cancelled'
        ) ASC, e.id ASC
    ");
    $params = [$branchId, ...$serviceIds, count($serviceIds)];
    if ($staffId !== '') {
        $params[] = (int) $staffId;
    }
    $params[] = $appointmentDateTime;
    $stmt->execute($params);
    $candidates = $stmt->fetchAll();

    $employee = null;
    foreach ($candidates as $candidate) {
        if (!Scheduling::staffHasConflict($pdo, (int) $candidate['id'], $appointmentDateTime, $durationMinutes)) {
            $employee = $candidate;
            break;
        }
    }
    if (!$employee) {
        $pdo->rollBack();
        http_response_code($staffId !== '' && empty($candidates) ? 400 : 409);
        echo json_encode(['success' => false, 'conflict' => $staffId !== '' && !empty($candidates), 'message' => $staffId !== '' && empty($candidates)
            ? 'Selected staff member cannot perform all selected services at this branch.' : Scheduling::SLOT_UNAVAILABLE_MESSAGE]);
        exit();
    }
    $employeeId = (int) $employee['id'];
    $staffName = trim($employee['first_name'] . ' ' . $employee['last_name']);

    // Lock the current loyalty balance before calculating and consuming points.
    $pointsStmt = $pdo->prepare('SELECT loyalty_points FROM customers WHERE id = ? FOR UPDATE');
    $pointsStmt->execute([$customerId]);
    $customer['loyalty_points'] = (int) $pointsStmt->fetchColumn();
    $quote = ReservationPayment::quote($services, $customer['loyalty_points'], $useLoyaltyPoints, $paymentPlan);
    $pointsUsed = $quote['pointsUsed'];
    $finalPrice = $quote['serviceTotal'];
    $depositAmount = $quote['amountDue'];
    if (isset($_POST['quoteToken']) && !hash_equals($quote['quoteToken'], (string) $_POST['quoteToken'])) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'quoteChanged' => true, 'message' => 'Your service price or payment requirement changed. Please review Pay Now again.']);
        exit();
    }
    // PayMongo bookings are inserted unpaid; the deposit is recorded once
    // PayMongo reports the checkout paid (see shared/backend/config/PayMongo.php).
    $payOnline = $paymentMethod === PayMongo::METHOD && $depositAmount > 0;
    if ($payOnline && $depositAmount < PayMongo::MIN_AMOUNT) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Online payment needs at least ₱' . number_format(PayMongo::MIN_AMOUNT, 2) . '. Please choose another payment method.']);
        exit();
    }
    $paymentStatus = $payOnline ? 'Payment Required' : 'Awaiting Verification';

    $referenceCode = ''; // Generated atomically by the database insert trigger.
    $notes = "Booked for: {$customerName}, Phone: {$customerPhone}";

    // deposit_recorded_by stays NULL -- self-reported by the customer, not
    // yet verified by staff (see admin/backend/updateBookingStatus.php).
    // payment_status separately tracks the deposit's own lifecycle
    // (Awaiting Verification here) independent of the appointment's status
    // (Pending) -- see database/migrations/023_appointment_payment_status.sql.
    $ins = $pdo->prepare('
        INSERT INTO appointments (
            reference_code, customer_id, employee_id, branch_id, appointment_datetime, total_price,
            preferred_payment_method, deposit_paid, deposit_amount, deposit_method, deposit_reference, deposit_recorded_at,
            status, payment_status, reminder_sent, notes, terms_accepted_at, reservation_requirement, reservation_amount_due, payment_plan
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL), ?, ?, 0, ?, NOW(), ?, ?, ?)
    ');
    $ins->execute([
        $referenceCode, $customerId, $employeeId, $branchId, $appointmentDateTime, $finalPrice,
        $paymentMethod, $payOnline ? 0 : 1, $payOnline ? null : $depositAmount, $payOnline ? null : $paymentMethod,
        null, $payOnline ? 0 : 1,
        'Pending', $paymentStatus, $notes, $quote['reservationRequirement'], $depositAmount, $quote['paymentPlan'],
    ]);
    $appointmentId = $pdo->lastInsertId();
    $refStmt = $pdo->prepare('SELECT reference_code FROM appointments WHERE id = ?');
    $refStmt->execute([$appointmentId]);
    $referenceCode = $refStmt->fetchColumn();

    $linkStmt = $pdo->prepare('INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)');
    foreach ($services as $service) {
        $linkStmt->execute([$appointmentId, $service['id']]);
    }

    if ($pointsUsed > 0) {
        $redeem = $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ? AND loyalty_points >= ?');
        $redeem->execute([$pointsUsed, $customerId, $pointsUsed]);
        if ($redeem->rowCount() !== 1) throw new RuntimeException('Your loyalty balance changed. Please review your booking total and try again.');
        $pdo->prepare('UPDATE appointments SET loyalty_points_used = ? WHERE id = ?')->execute([$pointsUsed, $appointmentId]);
    }

    $checkoutUrl = null;
    if ($payOnline) {
        // Created before commit so a PayMongo failure rolls the booking back
        // instead of leaving an unpayable slot hold behind.
        try {
            $checkoutUrl = PayMongo::startCheckout($pdo, (int) $appointmentId, $referenceCode, $depositAmount, 'customer');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            error_log('submitBooking PayMongo error: ' . $e->getMessage());
            PaymentLog::record($pdo, 'api_error', null, null, null, $depositAmount, 'checkout_failed', $e->getMessage());
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'We could not start the online payment. Please try again or choose another payment method.']);
            exit();
        }
    }

    CustomerNotifier::notify($pdo, $customerId, 'BOOKING_SUBMITTED', CancellationPolicy::bookingReceivedMessage(
        $referenceCode, array_column($services, 'service_name'), $branch['branch_name'], $appointmentDateTime, $depositAmount, $quote['remainingBalance'], $payOnline));
    if (!$payOnline) {
        CustomerNotifier::notify($pdo, $customerId, 'PAYMENT_SUBMITTED', "Your reservation payment for {$referenceCode} has been submitted and is now Payment Being Verified.");
    }
    // Branch-wide log (admin notification bell) so the salon hears about new online bookings.
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["New booking {$referenceCode} from {$customerName} at {$branch['branch_name']} on "
            . date('M j, Y g:i A', strtotime($appointmentDateTime)) . ($depositAmount <= 0 ? '.' : ($payOnline ? ' — online payment in progress.' : ' — payment awaiting verification.'))]);

    $pdo->commit();

    $serviceNames = implode(', ', array_map(fn($s) => $s['service_name'], $services));

    echo json_encode([
        'success' => true,
        'message' => "Your booking request {$referenceCode} has been sent!",
        'reference' => $referenceCode,
        'checkoutUrl' => $checkoutUrl,
        'appointment' => [
            'id' => $referenceCode,
            'branchId' => $branchKey,
            'branchName' => $branch['branch_name'],
            'serviceName' => $serviceNames,
            'price' => $finalPrice,
            'reservationRequirement' => $quote['reservationRequirement'],
            'amountDue' => $depositAmount,
            'remainingBalance' => $quote['remainingBalance'],
            'staffId' => (string) $employeeId,
            'staffName' => $staffName,
            'paymentMethod' => $paymentMethod,
            'depositAmount' => $depositAmount,
            'depositReference' => '',
            'date' => $date,
            'time' => $time,
            'status' => 'Pending',
            'paymentStatus' => $paymentStatus,
            'reminderSent' => false,
            'durationMinutes' => $durationMinutes,
            'endTime' => ServiceDuration::end($appointmentDateTime, $durationMinutes)->format('g:i A'),
        ],
        'loyaltyPoints' => (int) $customer['loyalty_points'] - $pointsUsed,
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
    error_log('submitBooking error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while booking your appointment.']);
}
