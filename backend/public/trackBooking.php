<?php

/**
 * Public: Track a Booking
 *
 * No login required. Looks up a salon appointment or home & event
 * service request by its reference code ("Track your booking").
 *
 * The reference alone shows the status view: status, date and time,
 * branch, services, stylists, amounts and pay links. References are
 * numbered in order, so anything personal or that changes the booking
 * needs proof of ownership as well -- the mobile number (or, for home
 * services, the email) on file: the home-service venue and notes,
 * online rescheduling and leaving a review. Lookups by reference alone
 * are rate limited per IP to slow down guessing.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/booking/HomeServiceRequest.php';
require_once '../../backend/shared/config/payments/PayMongo.php';
require_once '../../backend/shared/config/security/RateLimiter.php';

date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

$reference = strtoupper(trim($_GET['reference'] ?? ''));
$phone = trim($_GET['phone'] ?? '');
// Home services are tracked with reference + the EMAIL given on the request.
$email = strtolower(trim($_GET['email'] ?? ''));

if ($reference === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter your booking reference.']);
    exit();
}
// Reference only: the status view, without personal details or actions.
$proofGiven = $phone !== '' || $email !== '';
if (!$proofGiven) {
    $lookupLimiter = new RateLimiter(RateLimiter::getIpAddress(), 'track_reference_only', 30, 600);
    if ($lookupLimiter->isExceeded()) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many lookups. Please wait a few minutes and try again.']);
        exit();
    }
    $lookupLimiter->record();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT
            a.id, a.reference_code AS reference, a.status, a.appointment_datetime, a.total_price,
            a.updated_at, a.payment_status, a.deposit_amount, a.deposit_recorded_by,
            COALESCE(a.deposit_method, a.preferred_payment_method) AS payment_method,
            CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.id AND p.status = 'Paid') THEN 0
                ELSE GREATEST(0, a.total_price - CASE WHEN a.deposit_paid = 1 AND a.deposit_recorded_by IS NOT NULL THEN COALESCE(a.deposit_amount, 0) ELSE 0 END) END AS remaining_balance,
            COALESCE((SELECT GROUP_CONCAT(DISTINCT CONCAT(e.first_name, ' ', e.last_name) SEPARATOR ', ') FROM appointment_services sx JOIN employees e ON e.id = COALESCE(sx.employee_id, a.employee_id) WHERE sx.appointment_id = a.id),
                (SELECT CONCAT(e.first_name, ' ', e.last_name) FROM employees e WHERE e.id = a.employee_id)) AS staff_name,
            a.deposit_paid, a.reservation_amount_due, a.online_payment_token, a.preferred_payment_method,
            br.branch_name AS branch, c.phone_number AS phone,
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS services,
            EXISTS(SELECT 1 FROM feedback f WHERE f.appointment_id = a.id) AS has_feedback
        FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        LEFT JOIN branches br ON br.id = a.branch_id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services s ON s.id = aps.service_id
        WHERE a.reference_code = ?
        GROUP BY a.id
    ");
    $stmt->execute([$reference]);
    $appt = $stmt->fetch();

    if ($appt) {
        if ($proofGiven && $appt['phone'] !== $phone) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No booking found for that reference and mobile number.']);
            exit();
        }

        echo json_encode([
            'success' => true,
            'type' => 'appointment',
            'reference' => $appt['reference'],
            'status' => $appt['status'],
            'branch' => $appt['branch'],
            'staffName' => $appt['staff_name'],
            'updatedAt' => $appt['updated_at'],
            'paymentStatus' => $appt['payment_status'],
            'paymentMethod' => $appt['payment_method'],
            'depositAmount' => $appt['deposit_amount'] !== null ? (float) $appt['deposit_amount'] : null,
            'depositVerified' => $appt['deposit_recorded_by'] !== null,
            'remainingBalance' => (float) $appt['remaining_balance'],
            'amountPaid' => max(0, (float) $appt['total_price'] - (float) $appt['remaining_balance']),
            'services' => $appt['services'],
            'price' => $appt['total_price'] !== null ? (float) $appt['total_price'] : null,
            'date' => date('Y-m-d', strtotime($appt['appointment_datetime'])),
            'time' => date('h:i A', strtotime($appt['appointment_datetime'])),
            'canReview' => $proofGiven && $appt['status'] === 'Completed' && !$appt['has_feedback'],
            // GCash / Maya / Card links while an online reservation payment is unpaid (e.g. it failed or expired on PayMongo).
            'payUrls' => $appt['status'] === 'Pending' && !(int) $appt['deposit_paid'] && PayMongo::isOnline($appt['preferred_payment_method'])
                ? PayMongo::payUrls($appt['online_payment_token'], $appt['reference'], (float) $appt['reservation_amount_due'], 'guest') : null,
            'amountDue' => (float) $appt['reservation_amount_due'],
        ]);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT
            h.id, h.reference_code AS reference, h.status, h.event_type, h.preferred_date, h.address, h.requests,
            h.payment_status, h.deposit_method, h.deposit_recorded_by,
            (SELECT branch_name FROM branches WHERE id = h.branch_id) AS branch_name,
            COALESCE((SELECT GROUP_CONCAT(CONCAT(e.first_name, ' ', e.last_name) ORDER BY t.id SEPARATOR ', ') FROM home_service_staff t JOIN employees e ON e.id = t.employee_id WHERE t.home_service_request_id = h.id),
                (SELECT CONCAT(e.first_name, ' ', e.last_name) FROM employees e WHERE e.id = h.employee_id)) AS staff_name,
            h.quote_price, h.deposit_amount, h.deposit_paid, h.online_payment_token,
            h.reference_code, h.customer_id, h.preferred_time, h.reschedule_count, h.contact_email,
            h.balance_checkout_url, (SELECT COALESCE(SUM(hp.amount), 0) FROM home_service_payments hp WHERE hp.home_service_request_id = h.id) AS amount_paid,
            c.phone_number AS phone,
            EXISTS(SELECT 1 FROM feedback f WHERE f.home_service_request_id = h.id) AS has_feedback
        FROM home_service_requests h
        JOIN customers c ON c.id = h.customer_id
        WHERE h.reference_code = ?
    ");
    $stmt->execute([$reference]);
    $hs = $stmt->fetch();

    if ($hs) {
        $emailMatches = $email !== '' && $hs['contact_email'] && strcasecmp($hs['contact_email'], $email) === 0;
        $phoneMatches = $phone !== '' && $hs['phone'] === $phone;
        if ($proofGiven && !$emailMatches && !$phoneMatches) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No booking found for those details.']);
            exit();
        }

        echo json_encode([
            'success' => true,
            'type' => 'home_service',
            'reference' => $hs['reference'],
            'status' => $hs['status'],
            'event' => $hs['event_type'],
            'branch' => $hs['branch_name'],
            'staffName' => $hs['staff_name'],
            'price' => $hs['quote_price'] !== null ? (float) $hs['quote_price'] : null,
            'depositAmount' => $hs['deposit_amount'] !== null ? (float) $hs['deposit_amount'] : null,
            'depositVerified' => $hs['deposit_recorded_by'] !== null || ((int) $hs['deposit_paid'] === 1 && PayMongo::isOnline($hs['deposit_method'])),
            'paymentStatus' => $hs['payment_status'],
            'paymentMethod' => $hs['deposit_method'],
            'remainingBalance' => $hs['quote_price'] !== null ? max(0, round((float) $hs['quote_price'] - (float) $hs['amount_paid'], 2)) : null,
            'date' => $hs['preferred_date'],
            // Personal: the event address and notes (they include the contact email).
            'venue' => $proofGiven ? $hs['address'] : null,
            'requests' => $proofGiven ? $hs['requests'] : null,
            'quote' => $hs['quote_price'] !== null ? (float) $hs['quote_price'] : null,
            'reservationFee' => $hs['deposit_amount'] !== null ? (float) $hs['deposit_amount'] : null,
            'feePaid' => (int) $hs['deposit_paid'] === 1,
            // GCash / Maya / Card links for the reservation fee while it's still unpaid; the payment is created when one is opened.
            'payUrls' => $hs['status'] === 'Payment Required' && !(int) $hs['deposit_paid']
                ? PayMongo::payUrls($hs['online_payment_token'], $hs['reference'], (float) $hs['deposit_amount'], 'guest') : null,
            // Remaining balance after the DP, and its PayMongo link once the salon sends it.
            'amountPaid' => (float) $hs['amount_paid'],
            'balanceDue' => $hs['quote_price'] !== null ? max(0, round((float) $hs['quote_price'] - (float) $hs['amount_paid'], 2)) : null,
            'balancePayUrl' => $hs['quote_price'] !== null && (float) $hs['quote_price'] > (float) $hs['amount_paid'] ? $hs['balance_checkout_url'] : null,
            'canReview' => $proofGiven && $hs['status'] === 'Completed' && !$hs['has_feedback'],
            // Online rescheduling (backend/public/rescheduleHomeService.php).
            'time' => $hs['preferred_time'] ? date('h:i A', strtotime($hs['preferred_time'])) : null,
            'canReschedule' => $proofGiven && ($rescheduleBlocked = HomeServiceRequest::rescheduleBlockedReason($hs)) === null,
            'rescheduleNote' => $proofGiven ? $rescheduleBlocked : 'To move it online, log in and open My Account → Home Service.',
            'reschedulesLeft' => max(0, HomeServiceRequest::MAX_ONLINE_RESCHEDULES - (int) $hs['reschedule_count']),
            'rescheduleCutoffDays' => HomeServiceRequest::RESCHEDULE_CUTOFF_HOURS / 24,
        ]);
        exit();
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No booking found with that reference.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('trackBooking error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while looking up your booking.']);
}
