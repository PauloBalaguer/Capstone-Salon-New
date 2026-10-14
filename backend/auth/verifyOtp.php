<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/RateLimiter.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Limit: 10 failed OTP attempts per IP address within 15 minutes.
$ip_address = RateLimiter::getIpAddress();
$otp_limiter = new RateLimiter($ip_address, 'verify_otp_fail', 10, 900); // 10 attempts, 15 mins

if ($otp_limiter->isExceeded()) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false,
        'message' => 'Too many verification attempts. Please try again in 15 minutes.'
    ]);
    exit();
}

$email = trim($_POST['email'] ?? '');
$otp = trim($_POST['otp'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($otp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and verification code are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT id, is_active, otp_code, otp_expiry FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $otp_limiter->record();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
        exit();
    }

    if ($user['is_active']) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Account already verified. You can log in.']);
        exit();
    }

    $now = new DateTime();
    $expires_at = $user['otp_expiry'] ? new DateTime($user['otp_expiry']) : null;

    if (!$user['otp_code'] || !hash_equals($user['otp_code'], $otp) || !$expires_at || $now > $expires_at) {
        $otp_limiter->record();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired verification code.']);
        exit();
    }

    $otp_limiter->clear();

    $stmt = $pdo->prepare("UPDATE users SET is_active = 1, otp_code = NULL, otp_expiry = NULL WHERE id = ?");
    $stmt->execute([$user['id']]);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Account verified successfully! You can now log in.']);

} catch (Exception $e) {
    error_log('OTP Verification Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
