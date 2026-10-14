<?php

/**
 * Public: Track a Booking
 *
 * No login required. Looks up a guest's salon appointment or home &
 * event service request by reference code + the mobile number on file,
 * for the "Track your booking" flow on the public landing page. Both
 * reference and phone are required — a booking's reference alone isn't
 * treated as sufficient proof of ownership, since it's shown on screen
 * and could be shoulder-surfed or shared.
 */

require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/HomeServiceRequest.php';

date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
header('Content-Type: application/json');

$reference = strtoupper(trim($_GET['reference'] ?? ''));
$phone = trim($_GET['phone'] ?? '');
// Home services are tracked with reference + the EMAIL given on the request.
$email = strtolower(trim($_GET['email'] ?? ''));

if ($reference === '' || ($phone === '' && $email === '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter both the booking reference and the ' . (str_starts_with($reference, 'LM-HOM') ? 'email address' : 'mobile number') . ' used for it.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT
            a.id, a.reference_code AS reference, a.status, a.appointment_datetime, a.total_price,
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
        if ($appt['phone'] !== $phone) {
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
            'services' => $appt['services'],
            'price' => $appt['total_price'] !== null ? (float) $appt['total_price'] : null,
            'date' => date('Y-m-d', strtotime($appt['appointment_datetime'])),
            'time' => date('h:i A', strtotime($appt['appointment_datetime'])),
            'canReview' => $appt['status'] === 'Completed' && !$appt['has_feedback'],
        ]);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT
            h.id, h.reference_code AS reference, h.status, h.event_type, h.preferred_date, h.address, h.requests,
            h.quote_price, h.deposit_amount, h.deposit_paid, h.paymongo_checkout_url,
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
        if (!$emailMatches && !$phoneMatches) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No home service request found for that reference and email address.']);
            exit();
        }

        echo json_encode([
            'success' => true,
            'type' => 'home_service',
            'reference' => $hs['reference'],
            'status' => $hs['status'],
            'event' => $hs['event_type'],
            'date' => $hs['preferred_date'],
            'venue' => $hs['address'],
            'requests' => $hs['requests'],
            'quote' => $hs['quote_price'] !== null ? (float) $hs['quote_price'] : null,
            'reservationFee' => $hs['deposit_amount'] !== null ? (float) $hs['deposit_amount'] : null,
            'feePaid' => (int) $hs['deposit_paid'] === 1,
            // PayMongo link for the reservation fee while it's still unpaid.
            'payUrl' => $hs['status'] === 'Payment Required' && !(int) $hs['deposit_paid'] ? $hs['paymongo_checkout_url'] : null,
            // Remaining balance after the DP, and its PayMongo link once the salon sends it.
            'amountPaid' => (float) $hs['amount_paid'],
            'balanceDue' => $hs['quote_price'] !== null ? max(0, round((float) $hs['quote_price'] - (float) $hs['amount_paid'], 2)) : null,
            'balancePayUrl' => $hs['quote_price'] !== null && (float) $hs['quote_price'] > (float) $hs['amount_paid'] ? $hs['balance_checkout_url'] : null,
            'canReview' => $hs['status'] === 'Completed' && !$hs['has_feedback'],
            // Online rescheduling (backend/public/rescheduleHomeService.php).
            'time' => $hs['preferred_time'] ? date('h:i A', strtotime($hs['preferred_time'])) : null,
            'canReschedule' => ($rescheduleBlocked = HomeServiceRequest::rescheduleBlockedReason($hs)) === null,
            'rescheduleNote' => $rescheduleBlocked,
            'reschedulesLeft' => max(0, HomeServiceRequest::MAX_ONLINE_RESCHEDULES - (int) $hs['reschedule_count']),
            'rescheduleCutoffDays' => HomeServiceRequest::RESCHEDULE_CUTOFF_HOURS / 24,
        ]);
        exit();
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No booking found for that reference and mobile number.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('trackBooking error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while looking up your booking.']);
}
