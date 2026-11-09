<?php

/**
 * Save Profile Photo API Endpoint
 *
 * Stores the logged-in customer's profile picture in customers.profile_picture
 * (the same column saveProfile.php and getDashboardData.php already use).
 * The photo picker on My Profile shrinks the image in the browser and sends
 * it as a small data URI; this endpoint checks that it really is a PNG, JPEG
 * or WebP image before saving it.
 *
 * POST profilePicture=<data URI>, csrfToken (or X-CSRF-Token header).
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/security/Csrf.php';
require_once '../../backend/shared/config/setup/cloudinary.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

function photoFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    photoFail(401, 'Please log in to update your profile photo.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    photoFail(405, 'Invalid request method.');
}
Csrf::requireValid();

$photo = (string) ($_POST['profilePicture'] ?? '');

// Stays under MySQL's default 1MB max_allowed_packet (XAMPP).
if ($photo === '' || strlen($photo) > 900_000
    || !preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#D', $photo, $match)) {
    photoFail(400, 'Please choose a PNG, JPG or WebP photo smaller than 600 KB.');
}

// The bytes must decode to a real image of the declared type.
$bytes = base64_decode($match[2], true);
$info = $bytes === false ? false : @getimagesizefromstring($bytes);
$expected = ['png' => IMAGETYPE_PNG, 'jpeg' => IMAGETYPE_JPEG, 'webp' => IMAGETYPE_WEBP][$match[1]];
if ($info === false || $info[2] !== $expected || $info[0] < 1 || $info[1] < 1 || $info[0] > 4000 || $info[1] > 4000) {
    photoFail(400, 'That file is not a valid image. Please choose another photo.');
}

// Kept on Cloudinary when it is set up (only the URL is saved), else in the database.
$photo = Cloudinary::storePhoto($photo, 'customer-' . (int) $_SESSION['user_id']);

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('UPDATE customers SET profile_picture = ? WHERE user_id = ?');
    $stmt->execute([$photo, $_SESSION['user_id']]);

    $check = $pdo->prepare('SELECT 1 FROM customers WHERE user_id = ? LIMIT 1');
    $check->execute([$_SESSION['user_id']]);
    if (!$check->fetchColumn()) {
        photoFail(404, 'Customer profile not found.');
    }

    echo json_encode(['success' => true, 'message' => 'Profile photo updated.', 'profilePicture' => $photo]);
} catch (PDOException $e) {
    error_log('saveProfilePhoto error: ' . $e->getMessage());
    photoFail(500, 'A server error occurred while saving your photo.');
}
