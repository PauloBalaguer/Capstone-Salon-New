<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/RateLimiter.php';

sendCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Limit: 10 failed reset-code attempts per IP address within 15 minutes.
$ip_address = RateLimiter::getIpAddress();
$verify_limiter = new RateLimiter($ip_address, 'verify_reset_code_fail', 10, 900); // 10 attempts, 15 mins

if ($verify_limiter->isExceeded()) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false,
        'message' => 'Too many verification attempts. Please try again in 15 minutes.'
    ]);
    exit();
}

$email = trim($_POST['email'] ?? '');
$code = trim($_POST['code'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and code are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT code, expires_at FROM password_resets WHERE email = ?");
    $stmt->execute([$email]);
    $reset_request = $stmt->fetch();

    if (!$reset_request || !hash_equals($reset_request['code'], $code)) {
        $verify_limiter->record();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset code.']);
        exit();
    }

    $now = new DateTime();
    $expires_at = new DateTime($reset_request['expires_at']);

    if ($now > $expires_at) {
        $verify_limiter->record();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset code.']);
        exit();
    }

    // Code is valid. Note: we deliberately don't delete it here — the final
    // reset in resetPassword.php re-validates and consumes it, so this step
    // is just a UX gate that reveals the new-password fields.
    $verify_limiter->clear();

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Code verified.']);

} catch (Exception $e) {
    error_log('Reset Code Verification Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
