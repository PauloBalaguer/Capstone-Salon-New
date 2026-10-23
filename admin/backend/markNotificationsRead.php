<?php
require_once '../../shared/backend/config/cors.php';
require_once '../../shared/backend/config/session.php';
require_once '../../shared/backend/config/database.php';
sendCorsHeaders();
header('Content-Type: application/json');
if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(401); echo json_encode(['success'=>false]); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false]); exit;
}
$through = filter_var($_POST['through'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$through) { http_response_code(422); echo json_encode(['success'=>false]); exit; }
try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('INSERT IGNORE INTO admin_notification_reads (user_id, notification_id) SELECT ?, id FROM notifications WHERE id <= ? AND (user_id IS NULL OR user_id = ?)');
    $stmt->execute([$_SESSION['user_id'], $through, $_SESSION['user_id']]);
    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    error_log('Admin notification read failed: '.$e->getMessage());
    http_response_code(500); echo json_encode(['success'=>false]);
}
