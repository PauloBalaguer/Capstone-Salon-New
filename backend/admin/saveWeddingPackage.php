<?php

/**
 * Admin: Create/Edit Wedding Package
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin', 'Owner'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$packageId = (int) ($_POST['id'] ?? 0);
$packageName = trim($_POST['packageName'] ?? '');
$price = (float) ($_POST['price'] ?? 0);
$reservationFeeRaw = trim($_POST['reservationFee'] ?? '');
$reservationFee = $reservationFeeRaw === '' ? null : (float) $reservationFeeRaw;
$features = trim($_POST['features'] ?? '');
$style = trim($_POST['style'] ?? '');
$displayOrder = (int) ($_POST['displayOrder'] ?? 0);
$active = filter_var($_POST['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
// Team time for scheduling (Scheduling::homeServiceWindow). Blank = not set;
// a blank service time falls back to 120 minutes.
$minutes = [];
foreach (['travelMinutes' => 0, 'setupMinutes' => 0, 'serviceMinutes' => 1] as $field => $min) {
    $raw = trim($_POST[$field] ?? '');
    $minutes[$field] = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 1440]]);
    if ($minutes[$field] === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Travel, setup and service times must be whole minutes (up to 1440).']);
        exit();
    }
}
$durationsConfirmed = filter_var($_POST['durationsConfirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);

$allowedStyles = ['Plain', 'Highlight', 'Premium'];

if ($packageName === '' || $features === '' || $price <= 0 || !in_array($style, $allowedStyles, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all package fields correctly.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id FROM wedding_packages WHERE package_name = ? AND id != ?');
    $stmt->execute([$packageName, $packageId]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A wedding package with that name already exists.']);
        exit();
    }

    if ($packageId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM wedding_packages WHERE id = ?');
        $stmt->execute([$packageId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Wedding package not found.']);
            exit();
        }

        $pdo->prepare('
            UPDATE wedding_packages SET package_name = ?, price = ?, reservation_fee = ?, features = ?,
                style = ?, display_order = ?, is_active = ?,
                travel_minutes = ?, setup_minutes = ?, service_minutes = ?, durations_confirmed = ?
            WHERE id = ?
        ')->execute([$packageName, $price, $reservationFee, $features, $style, $displayOrder, $active ? 1 : 0,
            $minutes['travelMinutes'], $minutes['setupMinutes'], $minutes['serviceMinutes'], $durationsConfirmed ? 1 : 0, $packageId]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PACKAGE", ?)')
            ->execute(["Wedding package updated: {$packageName}."]);

        echo json_encode(['success' => true, 'message' => 'Wedding package updated.']);
        exit();
    }

    $pdo->prepare('
        INSERT INTO wedding_packages (package_name, price, reservation_fee, features, style, display_order, is_active,
            travel_minutes, setup_minutes, service_minutes, durations_confirmed)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$packageName, $price, $reservationFee, $features, $style, $displayOrder, $active ? 1 : 0,
        $minutes['travelMinutes'], $minutes['setupMinutes'], $minutes['serviceMinutes'], $durationsConfirmed ? 1 : 0]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PACKAGE", ?)')
        ->execute(["New wedding package added: {$packageName}."]);

    echo json_encode(['success' => true, 'message' => 'Wedding package created.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin saveWeddingPackage error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving the wedding package.']);
}
