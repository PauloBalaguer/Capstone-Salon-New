<?php

/**
 * Change Password API Endpoint (step 1 of 2)
 *
 * Verifies the logged-in customer's current password and new-password
 * rules, then emails a 6-digit confirmation code to their real address
 * instead of applying the change immediately. The change only takes effect
 * once backend/customer/confirmPasswordChange.php receives that code --
 * see database/migrations/016_password_change_verification.sql. This means
 * a compromised session/current-password alone isn't enough to hijack the
 * account; the attacker would also need the real inbox.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/RateLimiter.php';
require_once '../config/mail.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to change your password.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$ip_address = RateLimiter::getIpAddress();
$limiter = new RateLimiter($ip_address, 'change_password_fail', 5, 3600);

if ($limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please try again in an hour.']);
    exit();
}

$currentPassword = $_POST['currentPassword'] ?? '';
$newPassword = $_POST['newPassword'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

if (!$currentPassword || !$newPassword || !$confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all password fields.']);
    exit();
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
    exit();
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters long.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT password, email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        $limiter->record();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Incorrect current password.']);
        exit();
    }

    $limiter->clear();

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = (new DateTime('now + 10 minutes'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('
        INSERT INTO password_change_verifications (user_id, code, expires_at)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE code = VALUES(code), expires_at = VALUES(expires_at)
    ');
    $stmt->execute([$userId, $code, $expires_at]);

    sendPasswordChangeOtpEmail($user['email'], $code);

    echo json_encode([
        'success' => true,
        'message' => 'We sent a confirmation code to your email. Enter it below to finish changing your password.',
        'requiresVerification' => true,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('changePassword error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while changing your password.']);
}
