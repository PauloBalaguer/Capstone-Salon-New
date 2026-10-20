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

require_once __DIR__.'/../config/RevenueReport.php';
$reference = is_string($_GET['reference'] ?? '') ? trim($_GET['reference'] ?? '') : '';

try {
    $pdo = Database::getInstance();
    $sql = 'SELECT pt.id, pt.reference_code AS reference, pt.event, pt.external_id AS externalId, pt.amount, pt.status, pt.details,
                   DATE_FORMAT(pt.created_at, "%Y-%m-%d %H:%i:%s") AS createdAt
            FROM payment_transactions pt LEFT JOIN appointments a ON a.reference_code=pt.reference_code COLLATE utf8mb4_general_ci
            LEFT JOIN branches ab ON ab.id=a.branch_id LEFT JOIN home_service_requests h ON h.reference_code=pt.reference_code COLLATE utf8mb4_general_ci';
    [$where,$params] = RevenueReport::filterParts($_GET,'pt.created_at',"COALESCE(ab.branch_key,IF(h.id IS NULL,NULL,'home-service'))");
    if ($reference !== '') { $where[] = 'pt.reference_code LIKE ?'; $params[] = '%' . $reference . '%'; }
    if ($where) $sql .= ' WHERE '.implode(' AND ',$where);
    $stmt = $pdo->prepare($sql . ' ORDER BY pt.id DESC LIMIT 300');
    $stmt->execute($params);
    $events = array_map(function ($row) {
        $row['amount'] = $row['amount'] !== null ? (float) $row['amount'] : null;
        // Raw webhook payloads are long; the list only needs a short preview.
        $row['details'] = $row['details'] !== null ? mb_substr($row['details'], 0, 300) : null;
        return $row;
    }, $stmt->fetchAll());

    echo json_encode(['success' => true, 'events' => $events]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getPaymentLog error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load the payment log.']);
}
