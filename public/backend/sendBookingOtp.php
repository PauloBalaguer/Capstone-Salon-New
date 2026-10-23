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

require_once '../../shared/backend/config/cors.php';
require_once '../../shared/backend/config/RateLimiter.php';
require_once '../../shared/backend/config/database.php';
require_once '../../shared/backend/config/mail.php';

sendCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$ip_address = RateLimiter::getIpAddress();
$otp_limiter = new RateLimiter($ip_address, 'booking_otp', 1, 60);

if ($otp_limiter->isExceeded()) {
    $retry = Database::getInstance()->prepare("SELECT LEAST(60, GREATEST(1, 60 - TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()))) FROM rate_limit WHERE identifier = ? AND type = 'booking_otp'");
    $retry->execute([$ip_address]);
    $retryAfter = (int) $retry->fetchColumn();
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    echo json_encode(['success' => false, 'retryAfter' => $retryAfter, 'message' => "Please wait {$retryAfter} seconds before requesting another code."]);
    exit();
}

$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';

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

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('
        INSERT INTO booking_verifications (email, code, expires_at)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE code = ?, expires_at = ?
    ');
    $stmt->execute([$email, $code, $expires_at, $code, $expires_at]);

    if (!sendBookingOtpEmail($email, $code)) {
        // A failed resend must not invalidate a code that was already delivered.
        $pdo->rollBack();
        http_response_code(503);
        echo json_encode(['success' => false, 'retryAfter' => 60, 'message' => 'We could not send your verification email. Please try again later. Your booking details have been kept.']);
        exit();
    }
    $pdo->commit();

    http_response_code(200);
    echo json_encode(['success' => true, 'retryAfter' => 60, 'message' => 'Verification code sent.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Send Booking OTP Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred while sending the verification code. Please try again.']);
}
