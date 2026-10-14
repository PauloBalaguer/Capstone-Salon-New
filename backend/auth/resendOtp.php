<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/RateLimiter.php';
require_once '../config/mail.php';

sendCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Limit: 3 OTP resend requests per IP address per hour.
$ip_address = RateLimiter::getIpAddress();
$resend_limiter = new RateLimiter($ip_address, 'resend_otp', 3, 3600); // 3 requests, 1 hour

if ($resend_limiter->isExceeded()) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false,
        'message' => 'Too many resend requests. Please wait before trying again.'
    ]);
    exit();
}

$email = trim($_POST['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Same message regardless, to avoid email enumeration.
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'If that account needs verification, a new code has been sent.']);
    exit();
}

try {
    $resend_limiter->record();

    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT id, is_active FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $local_verification_code = null;

    if ($user && !$user['is_active']) {
        $otp_code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_expires = new DateTime('now + 10 minutes');
        $otp_expiry = $otp_expires->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expiry = ? WHERE id = ?");
        $stmt->execute([$otp_code, $otp_expiry, $user['id']]);

        $email_sent = sendOtpEmail($email, $otp_code);
        if (!$email_sent && in_array($ip_address, ['127.0.0.1', '::1'], true)) {
            $local_verification_code = $otp_code;
        }
    }

    http_response_code(200);
    $response = ['success' => true, 'message' => 'If that account needs verification, a new code has been sent.'];
    if ($local_verification_code !== null) {
        $response['verification_code'] = $local_verification_code;
        $response['message'] = 'Your new local verification code is ' . $local_verification_code . '.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    error_log('OTP Resend Error: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'If that account needs verification, a new code has been sent.']);
}
