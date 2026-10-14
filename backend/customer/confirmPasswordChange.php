<?php

/**
 * Change Password API Endpoint (step 2 of 2)
 *
 * Confirms the code emailed by backend/customer/changePassword.php and, if
 * valid, applies the new password. The current password was already
 * verified in step 1; this step's job is proving the requester also
 * controls the account's real inbox before the change takes effect.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/RateLimiter.php';

sendCorsHeaders();
AuditLog::captureRequest();
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
$limiter = new RateLimiter($ip_address, 'confirm_password_change_fail', 8, 3600);

if ($limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please try again in an hour.']);
    exit();
}

$code = trim($_POST['code'] ?? '');
$newPassword = $_POST['newPassword'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

if (!$code || !$newPassword || !$confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter the confirmation code and your new password.']);
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

    $stmt = $pdo->prepare('SELECT code, expires_at FROM password_change_verifications WHERE user_id = ?');
    $stmt->execute([$userId]);
    $pending = $stmt->fetch();

    if (!$pending || !hash_equals($pending['code'], $code)) {
        $limiter->record();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired confirmation code.']);
        exit();
    }

    if (new DateTime() > new DateTime($pending['expires_at'])) {
        $pdo->prepare('DELETE FROM password_change_verifications WHERE user_id = ?')->execute([$userId]);
        $limiter->record();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This code has expired. Please start again.']);
        exit();
    }

    $limiter->clear();

    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    $pdo->prepare('DELETE FROM password_change_verifications WHERE user_id = ?')->execute([$userId]);

    echo json_encode(['success' => true, 'message' => 'Password updated successfully!']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('confirmPasswordChange error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while changing your password.']);
}
