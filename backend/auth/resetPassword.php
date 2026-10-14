<?php
require_once '../config/cors.php';
require_once '../config/RateLimiter.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Limit: 10 failed password reset attempts per IP address within 1 hour.
$ip_address = RateLimiter::getIpAddress();
$reset_attempt_limiter = new RateLimiter($ip_address, 'reset_password_fail', 10, 3600); // 10 attempts, 1 hour

if ($reset_attempt_limiter->isExceeded()) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false,
        'message' => 'Too many password reset attempts. Please try again later.'
    ]);
    exit();
}

$email = trim($_POST['email'] ?? '');
$code = trim($_POST['code'] ?? '');
$password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($code) || empty($password) || empty($confirm_password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email, code, and passwords are required.']);
    exit();
}

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
    exit();
}

if ($password !== $confirm_password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    // Find the reset request record for this email
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ?");
    $stmt->execute([$email]);
    $reset_request = $stmt->fetch();

    if (!$reset_request || !hash_equals($reset_request['code'], $code)) {
        $reset_attempt_limiter->record(); // Record failed attempt
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset code.']);
        exit();
    }

    // Check if the code has expired
    $now = new DateTime();
    $expires_at = new DateTime($reset_request['expires_at']);

    if ($now > $expires_at) {
        $reset_attempt_limiter->record(); // Record failed attempt
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset code.']);
        // The code is expired, so remove it
        $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
        exit();
    }

    // Code is valid, clear any failed attempts from this IP
    $reset_attempt_limiter->clear();

    // Hash the new password
    $new_password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Update the user's password in the `users` table
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->execute([$new_password_hash, $email]);

    // Delete the code from the `password_resets` table to prevent reuse
    $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt->execute([$email]);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Password has been reset successfully. Redirecting to login...']);

} catch (Exception $e) {
    error_log('Password Reset Execution Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>