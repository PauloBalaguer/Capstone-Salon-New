<?php

/**
 * Save Profile API Endpoint
 *
 * Updates the logged-in customer's name/email/phone/notification prefs
 * and (optionally) their profile picture, which the frontend already
 * turns into a data-URI client-side before saving.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/setup/cloudinary.php';

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
$updatesNameParts = array_key_exists('firstName', $_POST);
[$legacyFirst, $legacyLast] = array_pad(explode(' ', $name, 2), 2, '');
$firstName = trim($_POST['firstName'] ?? $legacyFirst);
$middleName = trim($_POST['middleName'] ?? '');
$lastName = trim($_POST['lastName'] ?? $legacyLast);
$name = implode(' ', array_filter([$firstName, $middleName, $lastName], fn($part) => $part !== ''));
if ($updatesNameParts && ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($middleName) > 100 || mb_strlen($lastName) > 100)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'First and last name are required. Each name must be 100 characters or fewer.']);
    exit();
}
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

// The photo is normally saved on its own by saveProfilePhoto.php. Older
// clients may still send it here, so apply the same format and size check.
if ($profilePicture !== null && $profilePicture !== '' && (strlen($profilePicture) > 900_000
    || !preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#D', $profilePicture))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please choose a PNG, JPG or WebP photo smaller than 600 KB.']);
    exit();
}


require_once __DIR__ . '/../../backend/shared/config/helpers/Address.php';
// Omitted address means preserve the legacy value (older clients).
$updatesAddress = array_key_exists('structuredAddress', $_POST);
$structuredAddress = $updatesAddress ? Address::fromPost(false) : null;

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
    if ($updatesNameParts) {
        $sql .= ', middle_name = ?';
        $params[] = $middleName !== '' ? $middleName : null;
    }
    if ($updatesAddress) {
        $sql .= ', address = ?, structured_address = ?';
        $params[] = $structuredAddress['formatted'] ?? null;
        $params[] = $structuredAddress ? json_encode($structuredAddress) : null;
    }
    if ($profilePicture !== null && $profilePicture !== '') {
        $sql .= ', profile_picture = ?';
        $params[] = Cloudinary::storePhoto($profilePicture, 'customer-' . (int) $userId);
    }
    $sql .= ' WHERE user_id = ?';
    $params[] = $userId;
    $pdo->prepare($sql)->execute($params);

    $pdo->commit();

    // Keep the session in sync so subsequent requireLogin()-style checks and
    // any UI reading session data reflect the change immediately.
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $name;

    $response = ['success' => true, 'message' => 'Profile and preferences updated successfully!'];
    if ($updatesNameParts) {
        $response['nameProfile'] = compact('name', 'firstName', 'middleName', 'lastName');
    }
    if ($updatesAddress) {
        $response['addressProfile'] = ['address' => $structuredAddress['formatted'] ?? null, 'structuredAddress' => $structuredAddress];
    }
    echo json_encode($response);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('saveProfile error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving your profile.']);
}
