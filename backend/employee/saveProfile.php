<?php

/**
 * Save Profile API Endpoint (Staff)
 *
 * Updates the logged-in staff member's name/phone (on `employees`),
 * email (on `users`), and notification preferences (also on `users` --
 * the same notify_* columns backend/admin/saveAdminProfile.php uses,
 * just read/written from the staff side too).
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

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

$firstName = trim($_POST['firstName'] ?? '');
$lastName = trim($_POST['lastName'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$profilePicture = $_POST['profilePicture'] ?? null;
$bookingAlerts = filter_var($_POST['bookingAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);
$inventoryAlerts = filter_var($_POST['inventoryAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);
$orderAlerts = filter_var($_POST['orderAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);
$marketingAlerts = filter_var($_POST['marketingAlerts'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($firstName === '' || $lastName === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'First name, last name, and phone number are required.']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM employees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No staff profile is linked to this account.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $stmt->execute([$email, $userId]);
    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email address is already in use.']);
        exit();
    }

    $userParams = [$email, $bookingAlerts ? 1 : 0, $inventoryAlerts ? 1 : 0, $orderAlerts ? 1 : 0, $marketingAlerts ? 1 : 0];
    $userSql = 'UPDATE users SET email = ?, notify_booking = ?, notify_inventory = ?, notify_order = ?, notify_marketing = ? WHERE id = ?';
    $userParams[] = $userId;

    $pdo->prepare($userSql)->execute($userParams);

    $params = [$firstName, $lastName, $phone];
    $sql = 'UPDATE employees SET first_name = ?, last_name = ?, phone_number = ?';
    if ($profilePicture !== null && $profilePicture !== '') {
        $sql .= ', profile_picture = ?';
        $params[] = $profilePicture;
    }
    $sql .= ' WHERE user_id = ?';
    $params[] = $userId;

    $pdo->prepare($sql)->execute($params);

    $pdo->commit();

    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);

    echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('employee saveProfile error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving your profile.']);
}
