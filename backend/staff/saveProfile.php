<?php

/**
 * Save Profile API Endpoint (Staff)
 *
 * The Staff Portal's "Edit Details" form. A staff member can change only
 * their own mobile number (employees.phone_number), email address
 * (users.email) and profile photo. Name, position, role, employee ID and
 * branch are managed by the admin and are never read from this request.
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

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Staff') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a staff member.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

Csrf::requireValid();

function profileFail(int $code, string $message, ?string $field = null): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message, 'field' => $field]);
    exit();
}

// "+63 917 123 4567", "0917-123-4567" -> "09171234567".
$phone = preg_replace('/[\s\-()]/', '', trim((string) ($_POST['phone'] ?? '')));
if (str_starts_with($phone, '+63')) $phone = '0' . substr($phone, 3);
elseif (str_starts_with($phone, '63') && strlen($phone) === 12) $phone = '0' . substr($phone, 2);
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$profilePicture = $_POST['profilePicture'] ?? '';

if (!preg_match('/^09[0-9]{9}$/D', $phone)) {
    profileFail(400, 'Enter a valid mobile number, e.g. 09171234567.', 'phone');
}
if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    profileFail(400, 'Enter a valid email address.', 'email');
}
// Only a small PNG/JPEG/WebP photo, as a data URI from the photo picker, which
// shrinks it first. Stays under MySQL's default 1MB max_allowed_packet (XAMPP).
if ($profilePicture !== '' && (strlen($profilePicture) > 900_000
    || !preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#D', $profilePicture))) {
    profileFail(400, 'Please choose a smaller PNG, JPG or WebP photo.', 'profilePicture');
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM employees WHERE user_id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$userId]);
    $employeeId = $stmt->fetchColumn();
    if (!$employeeId) {
        $pdo->rollBack();
        profileFail(404, 'No staff profile is linked to this account.');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $stmt->execute([$email, $userId]);
    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        profileFail(409, 'That email address is already used by another account.', 'email');
    }

    $stmt = $pdo->prepare('SELECT id FROM employees WHERE phone_number = ? AND id != ? LIMIT 1');
    $stmt->execute([$phone, $employeeId]);
    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        profileFail(409, 'That mobile number is already used by another staff member.', 'phone');
    }

    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);

    $params = [$phone];
    $sql = 'UPDATE employees SET phone_number = ?';
    if ($profilePicture !== '') {
        $sql .= ', profile_picture = ?';
        $params[] = Cloudinary::storePhoto($profilePicture, 'staff-' . (int) $employeeId);
    }
    $sql .= ' WHERE id = ?';
    $params[] = $employeeId;
    $pdo->prepare($sql)->execute($params);

    $pdo->commit();

    $_SESSION['user_email'] = $email;

    echo json_encode(['success' => true, 'message' => 'Your details were saved.']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('employee saveProfile error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving your details.']);
}
