<?php

/**
 * Signing in from a browser that "Remember me" recognises (TrustedDevice).
 * POST action:
 *   check  + email: is this browser remembered for this email?
 *   login  + email: signs in without the password. remember keeps the device.
 *   forget: "Not you?", this browser is no longer remembered.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/security/TrustedDevice.php';

sendCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$action = (string) ($_POST['action'] ?? '');
$email = trim((string) ($_POST['email'] ?? ''));

try {
    $pdo = Database::getInstance();

    if ($action === 'forget') {
        TrustedDevice::forget($pdo);
        echo json_encode(['success' => true]);
        exit();
    }

    $user = TrustedDevice::userFor($pdo, $email);

    if ($action === 'check') {
        echo json_encode(['recognised' => $user !== null]);
        exit();
    }

    if ($action !== 'login') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit();
    }

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'recognised' => false, 'message' => 'Please enter your password to sign in.']);
        exit();
    }

    AuditLog::captureRequest();
    signInUser($user);
    if (empty($_POST['remember'])) {
        TrustedDevice::forget($pdo);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login successful! Redirecting...',
        'redirect' => getRoleDashboardUrl($user['role_name']),
        'portal' => getRolePortal($user['role_name']),
    ]);
} catch (PDOException $e) {
    error_log('Device login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
