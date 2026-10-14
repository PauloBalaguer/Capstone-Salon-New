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

$promoId = (int) ($_POST['id'] ?? 0);
if ($promoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid promotion.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT title FROM promotions WHERE id = ?');
    $stmt->execute([$promoId]);
    $title = $stmt->fetchColumn();
    if (!$title) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Promotion not found.']);
        exit();
    }

    $pdo->prepare('DELETE FROM promotions WHERE id = ?')->execute([$promoId]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PROMO", ?)')
        ->execute(["{$title} was removed."]);

    echo json_encode(['success' => true, 'message' => 'Promotion deleted.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin deletePromotion error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while deleting the promotion.']);
}
