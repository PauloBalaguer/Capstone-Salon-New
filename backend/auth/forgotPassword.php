<?php
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

// Limit: 3 password reset requests per IP address per hour.
$ip_address = RateLimiter::getIpAddress();
$reset_limiter = new RateLimiter($ip_address, 'forgot_password', 3, 3600); // 3 requests, 3600 seconds (1 hour)

if ($reset_limiter->isExceeded()) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false,
        'message' => 'You have requested a password reset too many times. Please wait an hour before trying again.'
    ]);
    exit();
}

$email = trim($_POST['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // We still return a success-like message to prevent email enumeration.
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'If an account with that email exists, a reset code has been sent.']);
    exit();
}

try {
    // Record the request attempt for rate limiting. We do this for every request to this endpoint.
    $reset_limiter->record();

    $pdo = Database::getInstance();

    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // User exists, generate a 6-digit code (same shape as the registration OTP).
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = new DateTime('now + 10 minutes');
        $expires_at = $expires->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO password_resets (email, code, expires_at)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE code = ?, expires_at = ?
        ");
        $stmt->execute([$email, $code, $expires_at, $code, $expires_at]);

        sendPasswordResetOtpEmail($email, $code);
    }

    // IMPORTANT: For security, always return the same message whether the user was found or not.
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'If an account with that email exists, a reset code has been sent.']);

} catch (Exception $e) {
    error_log('Password Reset Error: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'If an account with that email exists, a reset code has been sent.']);
}
?>