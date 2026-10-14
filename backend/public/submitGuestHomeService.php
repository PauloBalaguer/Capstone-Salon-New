<?php

/**
 * Public: Submit Guest Home Service Request
 *
 * No login required. Same guest-customer find-or-create pattern as
 * submitGuestBooking.php.
 */

require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/HomeServiceRequest.php';
require_once '../config/RateLimiter.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Limit: 5 home service requests per IP address per hour.
$ip_address = RateLimiter::getIpAddress();
$request_limiter = new RateLimiter($ip_address, 'submit_home_service', 5, 3600);

if ($request_limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'You have submitted too many requests. Please wait an hour before trying again.'
    ]);
    exit();
}

$fullName = trim($_POST['fullname'] ?? '');
$contact = trim($_POST['contact'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$venueDetails = trim($_POST['venueDetails'] ?? '');
$eventType = trim($_POST['eventType'] ?? '');
$date = trim($_POST['date'] ?? '');
$time = trim($_POST['time'] ?? '');
$clients = trim($_POST['clients'] ?? '');
$services = $_POST['services'] ?? [];
$weddingPackage = trim($_POST['weddingPackage'] ?? '');
$requests = trim($_POST['requests'] ?? '');
$agreedToTerms = filter_var($_POST['agreedToTerms'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!is_array($services)) {
    $services = [$services];
}
$services = array_values(array_filter(array_map('trim', $services), fn($s) => $s !== ''));

// Preferred date/time is a request only -- no deposit/payment is required
// to submit. Admin reviews the request and determines availability,
// pricing, staff, and whether a reservation payment is required
// (see backend/admin/updateBookingStatus.php).
if ($fullName === '' || $contact === '' || $address === '' || $eventType === '' || $date === ''
    || $time === '' || $clients === '' || ($eventType !== 'Wedding' && empty($services))
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Please fill in your name, contact number, address, event type, preferred date and time, number of clients, and at least one service."]);
    exit();
}

if (!$agreedToTerms) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please agree to the Terms and Conditions to continue.']);
    exit();
}

// Required: guests track and reschedule this request with reference + email,
// and receive the quote / payment link / updates by email.
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address — you\'ll use it to track and reschedule your request.']);
    exit();
}

$validationError = HomeServiceRequest::validate($date, $time, $eventType, $weddingPackage, $clients, $services);
if ($validationError !== null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $validationError]);
    exit();
}
if (!preg_match('/^09[0-9]{9}$/', $contact)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid 11-digit mobile number.']);
    exit();
}

[$firstName, $lastName] = array_pad(explode(' ', $fullName, 2), 2, '');

$fullRequests = HomeServiceRequest::details($eventType, $weddingPackage, $clients, $services, $venueDetails, $requests);
if ($email !== '') $fullRequests .= "\nEmail: $email";

try {
    $request_limiter->record();

    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    // This form has no OTP/verification step at all, so unlike
    // submitGuestBooking.php there's no way to confirm the submitter owns a
    // phone number. Reusing a phone that already belongs to a registered
    // account would silently attach the request to that person's real
    // account — refuse instead of guessing.
    $stmt = $pdo->prepare('SELECT c.id, u.email FROM customers c LEFT JOIN users u ON u.id = c.user_id WHERE c.phone_number = ?');
    $stmt->execute([$contact]);
    $existingCustomer = $stmt->fetch();

    if ($existingCustomer && $existingCustomer['email'] !== null) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This mobile number is linked to an existing account. Please log in to submit a request, or use a different mobile number.']);
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

    // No deposit is collected here -- status defaults to 'Pending Review'
    // and payment_status defaults to 'Payment Required', to be set only
    // if/when admin review determines a reservation payment is needed
    // (see backend/admin/updateBookingStatus.php).
    $ins = $pdo->prepare('
        INSERT INTO home_service_requests (
            reference_code, customer_id, contact_email, address, event_type, wedding_package, preferred_date, preferred_time,
            requests, number_of_clients, terms_accepted_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    $ins->execute([
        $referenceCode, $customerId, $email, $address, $eventType, $eventType === 'Wedding' ? $weddingPackage : null, $date, $time, $fullRequests, (int) $clients,
    ]);
    // Guest updates (quote, payment link, reschedules) go out by email.
    $pdo->prepare('UPDATE customers SET email = ? WHERE id = ? AND user_id IS NULL AND (email IS NULL OR email = "")')->execute([$email, $customerId]);

    $requestId = $pdo->lastInsertId();
    $refStmt = $pdo->prepare('SELECT reference_code FROM home_service_requests WHERE id = ?');
    $refStmt->execute([$requestId]);
    $referenceCode = $refStmt->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Thank you! Your home service request {$referenceCode} has been received. Our team will review your request and email you at {$email} about availability, pricing, staff assignment, and any required reservation payment. Track or reschedule it anytime with your reference number and this email.",
        'reference' => $referenceCode,
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('submitGuestHomeService error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while submitting your request.']);
}
