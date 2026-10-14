<?php

/**
 * Admin: Mark a guest review Reviewed / Resolved.
 *
 * Only ever touches feedback.admin_status -- the customer's own rating and
 * comments columns are never written here, by design (Admin moderates,
 * never edits, what a guest actually said).
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

$feedbackId = (int) ($_POST['id'] ?? 0);
$status = trim($_POST['status'] ?? '');
$allowedStatuses = ['New', 'Reviewed', 'Resolved'];

if ($feedbackId <= 0 || !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid review or status.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id FROM feedback WHERE id = ?');
    $stmt->execute([$feedbackId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Review not found.']);
        exit();
    }

    $pdo->prepare('UPDATE feedback SET admin_status = ? WHERE id = ?')->execute([$status, $feedbackId]);

    echo json_encode(['success' => true, 'message' => 'Review status updated.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin setFeedbackStatus error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the review.']);
}
