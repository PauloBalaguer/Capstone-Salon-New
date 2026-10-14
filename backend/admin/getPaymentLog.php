<?php

/**
 * Admin: Online Payment Log
 *
 * Returns the newest payment_transactions rows (PayMongo checkouts,
 * payments, expiries, webhook deliveries, deposit forfeits/refunds -- see
 * backend/config/PaymentLog.php) for Reports -> Online Payment Log.
 * GET reference=<booking ref> narrows it to one booking's history.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

$reference = trim($_GET['reference'] ?? '');

try {
    $pdo = Database::getInstance();
    $sql = 'SELECT id, reference_code AS reference, event, external_id AS externalId, amount, status, details,
                   DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS createdAt
            FROM payment_transactions';
    $params = [];
    if ($reference !== '') {
        $sql .= ' WHERE reference_code LIKE ?';
        $params[] = '%' . $reference . '%';
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 300');
    $stmt->execute($params);
    $events = array_map(function ($row) {
        $row['amount'] = $row['amount'] !== null ? (float) $row['amount'] : null;
        // Raw webhook payloads are long; the list only needs a short preview.
        $row['details'] = $row['details'] !== null ? mb_substr($row['details'], 0, 300) : null;
        return $row;
    }, $stmt->fetchAll());

    echo json_encode(['success' => true, 'events' => $events]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getPaymentLog error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load the payment log.']);
}
