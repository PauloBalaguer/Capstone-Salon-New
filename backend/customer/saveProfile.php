<?php

/**
 * Save Profile API Endpoint
 *
 * Updates the logged-in customer's name/email/phone/notification prefs
 * and (optionally) their profile picture, which the frontend already
 * turns into a data-URI client-side before saving.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to update your profile.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$notifyEmail = filter_var($_POST['notifyEmail'] ?? true, FILTER_VALIDATE_BOOLEAN);
$notifySms = filter_var($_POST['notifySms'] ?? true, FILTER_VALIDATE_BOOLEAN);
$profilePicture = $_POST['profilePicture'] ?? null;

if ($name === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name and phone number are required.']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit();
}

[$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, '');

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $stmt->execute([$email, $userId]);
    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email address is already in use.']);
        exit();
    }

    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);

    $params = [$firstName, $lastName, $phone, $notifyEmail ? 1 : 0, $notifySms ? 1 : 0];
    $sql = 'UPDATE customers SET first_name = ?, last_name = ?, phone_number = ?, notify_email = ?, notify_sms = ?';
    if ($profilePicture !== null && $profilePicture !== '') {
        $sql .= ', profile_picture = ?';
        $params[] = $profilePicture;
    }
    $sql .= ' WHERE user_id = ?';
    $params[] = $userId;
    $pdo->prepare($sql)->execute($params);

    $pdo->commit();

    // Keep the session in sync so subsequent requireLogin()-style checks and
    // any UI reading session data reflect the change immediately.
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);

    echo json_encode(['success' => true, 'message' => 'Profile and preferences updated successfully!']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('saveProfile error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving your profile.']);
}
