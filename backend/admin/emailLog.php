<?php

/**
 * Admin: every email attempt for one booking (Admin > Bookings > Details).
 *
 * GET  ?reference=LM-...      -> { emails[] } sent, waiting, failed (with the reason) and
 *                                 not sent (no address / email turned off)
 * POST action=retry, id       -> puts a failed email back in the queue and sends it now
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/notifications/EmailOutbox.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin', 'Owner'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $reference = strtoupper(trim((string) ($_GET['reference'] ?? '')));
        if ($reference === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A booking reference is required.']);
            exit();
        }
        echo json_encode(['success' => true, 'emails' => EmailOutbox::history($pdo, $reference)]);
        exit();
    }
    if (($_POST['action'] ?? '') !== 'retry') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit();
    }
    $id = (int) ($_POST['id'] ?? 0);
    if (!EmailOutbox::retry($pdo, $id)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only a failed email with an address can be sent again.']);
        exit();
    }
    $stmt = $pdo->prepare('SELECT status, last_error, reference_code FROM email_outbox WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    AuditLog::record($pdo, 'email/retry', 'Email sent again', $row['reference_code'] ?? null, $row['status'] ?? null);
    echo json_encode($row['status'] === 'Sent'
        ? ['success' => true, 'message' => 'Email sent.']
        : ['success' => false, 'message' => 'Still not sent: ' . ($row['last_error'] ?: 'it will be tried again later') . '.']);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('admin emailLog error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading the email log.']);
}
