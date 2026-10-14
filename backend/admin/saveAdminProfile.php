<?php

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$bookingAlerts = filter_var($_POST['bookingAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);
$inventoryAlerts = filter_var($_POST['inventoryAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);
$orderAlerts = filter_var($_POST['orderAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);
$marketingAlerts = filter_var($_POST['marketingAlerts'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentAlerts = filter_var($_POST['paymentAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);
$homeServiceAlerts = filter_var($_POST['homeServiceAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);
$staffConflictAlerts = filter_var($_POST['staffConflictAlerts'] ?? true, FILTER_VALIDATE_BOOLEAN);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid name and email are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email is already in use by another account.']);
        exit();
    }

    $params = [
        $name, $email, $bookingAlerts ? 1 : 0, $inventoryAlerts ? 1 : 0, $orderAlerts ? 1 : 0, $marketingAlerts ? 1 : 0,
        $paymentAlerts ? 1 : 0, $homeServiceAlerts ? 1 : 0, $staffConflictAlerts ? 1 : 0,
    ];
    $sql = '
        UPDATE users SET display_name = ?, email = ?, notify_booking = ?, notify_inventory = ?, notify_order = ?, notify_marketing = ?,
            notify_payment = ?, notify_home_service = ?, notify_staff_conflict = ?
        WHERE id = ?
    ';
    $params[] = $userId;

    $pdo->prepare($sql)->execute($params);

    $_SESSION['user_email'] = $email;

    echo json_encode(['success' => true, 'message' => 'Profile updated.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin saveAdminProfile error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating your profile.']);
}
