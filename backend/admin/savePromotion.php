<?php

/**
 * Admin: Create/Edit Promotion
 *
 * promo_code is required + unique in the schema but isn't part of the admin
 * UI, so it's auto-generated from the title on create.
 */

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

$promoId = (int) ($_POST['id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$branchKey = trim($_POST['branchId'] ?? '');
$serviceId = (int) ($_POST['serviceId'] ?? 0);
$discountType = trim($_POST['discountType'] ?? '');
$discountValue = (float) ($_POST['discountValue'] ?? 0);
$startDate = trim($_POST['startDate'] ?? '');
$endDate = trim($_POST['endDate'] ?? '');
$active = filter_var($_POST['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

$allowedTypes = ['Percentage', 'Fixed Amount'];

if ($title === '' || $description === '' || $branchKey === '' || $serviceId <= 0 || !in_array($discountType, $allowedTypes, true)
    || $discountValue <= 0 || $startDate === '' || $endDate === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all promotion fields correctly, including the linked service.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id FROM branches WHERE branch_key = ? LIMIT 1');
    $stmt->execute([$branchKey]);
    $branchId = $stmt->fetchColumn();
    if (!$branchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT price FROM services WHERE id = ? AND branch_id = ? AND is_active = 1');
    $stmt->execute([$serviceId, $branchId]);
    $originalPrice = $stmt->fetchColumn();
    if ($originalPrice === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid service selected for this branch.']);
        exit();
    }
    $originalPrice = (float) $originalPrice;
    $price = $discountType === 'Percentage'
        ? $originalPrice * (1 - $discountValue / 100)
        : $originalPrice - $discountValue;
    $price = round(max(0, $price), 2);

    if ($promoId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM promotions WHERE id = ?');
        $stmt->execute([$promoId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Promotion not found.']);
            exit();
        }

        $pdo->prepare('
            UPDATE promotions SET title = ?, description = ?, branch_id = ?, service_id = ?, discount_type = ?, discount_value = ?,
                price = ?, original_price = ?, start_date = ?, end_date = ?, is_active = ?
            WHERE id = ?
        ')->execute([$title, $description, $branchId, $serviceId, $discountType, $discountValue, $price, $originalPrice, $startDate, $endDate, $active ? 1 : 0, $promoId]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PROMO", ?)')
            ->execute(["Promotion updated: {$title}."]);

        echo json_encode(['success' => true, 'message' => 'Promotion updated.']);
        exit();
    }

    // Truncate the title portion first so the year + random suffix (which
    // guarantees uniqueness) never gets cut off for a long title.
    $titlePart = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $title)), 0, 40);
    $promoCode = $titlePart . date('Y') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

    $pdo->prepare('
        INSERT INTO promotions (promo_code, description, discount_type, discount_value, price, original_price, start_date, end_date, is_active, branch_id, service_id, title)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$promoCode, $description, $discountType, $discountValue, $price, $originalPrice, $startDate, $endDate, $active ? 1 : 0, $branchId, $serviceId, $title]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PROMO", ?)')
        ->execute(["New promotion launched: {$title}."]);

    echo json_encode(['success' => true, 'message' => 'Promotion created.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin savePromotion error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving the promotion.']);
}
