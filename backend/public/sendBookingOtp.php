<?php

/**
 * Public: Send Guest Booking OTP
 *
 * No login required — sends a 6-digit email verification code before a
 * guest's appointment request (see submitGuestBooking.php) is created,
 * so the booking can't be submitted under an email the guest doesn't
 * control. Unlike forgotPassword.php, there's no account to enumerate
 * here, so failures are reported honestly instead of always returning a
 * generic success.
 */

require_once '../config/cors.php';
require_once '../config/RateLimiter.php';
require_once '../config/database.php';
require_once '../config/mail.php';

sendCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$ip_address = RateLimiter::getIpAddress();
$otp_limiter = new RateLimiter($ip_address, 'booking_otp', 5, 3600);

if ($otp_limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many verification requests. Please wait an hour before trying again.']);
    exit();
}

$email = trim($_POST['email'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit();
}

try {
    $otp_limiter->record();

    $pdo = Database::getInstance();

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = new DateTime('now + 10 minutes');
    $expires_at = $expires->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('
        INSERT INTO booking_verifications (email, code, expires_at)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE code = ?, expires_at = ?
    ');
    $stmt->execute([$email, $code, $expires_at, $code, $expires_at]);

    sendBookingOtpEmail($email, $code);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Verification code sent.']);
} catch (Exception $e) {
    error_log('Send Booking OTP Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred while sending the verification code. Please try again.']);
}
