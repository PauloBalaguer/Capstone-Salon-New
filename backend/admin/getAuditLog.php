<?php

/**
 * Admin: Activity Log
 *
 * Returns the newest audit_log rows (see backend/config/AuditLog.php) for
 * Reports -> Activity Log. Optional GET filters: q (matches person, action,
 * record reference or message), role, from / to (YYYY-MM-DD).
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

$search = trim($_GET['q'] ?? '');
$role = trim($_GET['role'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

try {
    $pdo = Database::getInstance();
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(actor LIKE ? OR label LIKE ? OR entity_ref LIKE ? OR message LIKE ?)';
        array_push($params, ...array_fill(0, 4, '%' . $search . '%'));
    }
    if ($role === 'Guest') {
        $where[] = 'user_id IS NULL AND action <> "auth/login"';
    } elseif ($role !== '') {
        $where[] = 'user_role = ?';
        $params[] = $role;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'created_at < ? + INTERVAL 1 DAY';
        $params[] = $to;
    }

    $stmt = $pdo->prepare('
        SELECT id, user_role AS role, actor, action, label, entity_ref AS reference, outcome, message, details,
               ip_address AS ip, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS createdAt
        FROM audit_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
        ORDER BY id DESC LIMIT 500
    ');
    $stmt->execute($params);

    echo json_encode(['success' => true, 'entries' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getAuditLog error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load the activity log.']);
}
