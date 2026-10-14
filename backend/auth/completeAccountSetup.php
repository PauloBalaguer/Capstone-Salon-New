<?php

/**
 * Public: Complete Account Setup
 *
 * The other half of AccountSetup::issueAndEmail() -- a Staff/Cashier
 * account created by the Admin sits with an unusable random password
 * until its owner follows the emailed link (pages/login/set-password.html)
 * and lands here to set a real one. Mirrors resetPassword.php's shape
 * (rate-limited, re-validates everything server-side), keyed by the
 * token instead of an emailed code.
 */

require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/RateLimiter.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$ip_address = RateLimiter::getIpAddress();
$limiter = new RateLimiter($ip_address, 'account_setup_fail', 10, 3600); // 10 attempts, 1 hour

if ($limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please try again later.']);
    exit();
}

$token = trim($_POST['token'] ?? '');
$password = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($token === '' || $password === '' || $confirmPassword === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This link is missing required information.']);
    exit();
}

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
    exit();
}

if ($password !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare('SELECT user_id, expires_at FROM account_setup_tokens WHERE token_hash = ?');
    $stmt->execute([$tokenHash]);
    $setupRequest = $stmt->fetch();

    if (!$setupRequest) {
        $limiter->record();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired setup link.']);
        exit();
    }

    $now = new DateTime();
    $expiresAt = new DateTime($setupRequest['expires_at']);

    if ($now > $expiresAt) {
        $limiter->record();
        $pdo->prepare('DELETE FROM account_setup_tokens WHERE user_id = ?')->execute([$setupRequest['user_id']]);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired setup link.']);
        exit();
    }

    $limiter->clear();

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hashed, $setupRequest['user_id']]);
    $pdo->prepare('DELETE FROM account_setup_tokens WHERE user_id = ?')->execute([$setupRequest['user_id']]);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Your password has been set. You can now sign in.']);
} catch (Exception $e) {
    error_log('Account Setup Completion Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
