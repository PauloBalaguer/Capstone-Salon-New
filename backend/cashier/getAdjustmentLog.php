<?php

/**
 * Cashier: Inventory Adjustment Log
 *
 * Feeds the "View Adjustment Log" modal. A Cashier always sees only their
 * own branch's log (from $_SESSION['branch_id'] -- see
 * database/migrations/007_cashier_branch_lock.sql); Admin has no
 * branch lock and can optionally filter via ?branchId=<branch_key>.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']);
    exit();
}

$isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';

try {
    $pdo = Database::getInstance();

    $sql = "
        SELECT
            ia.id,
            DATE_FORMAT(ia.created_at, '%Y-%m-%d %h:%i %p') AS timestamp,
            i.product_name AS product,
            ia.adjustment_type AS action,
            ia.quantity,
            ia.reason,
            ia.previous_stock AS previousStock,
            ia.new_stock AS newStock
        FROM inventory_adjustments ia
        JOIN inventory i ON i.id = ia.inventory_id
        LEFT JOIN branches br ON br.id = i.branch_id
    ";
    $params = [];
    if ($isCashier) {
        $sql .= ' WHERE i.branch_id = ?';
        $params[] = $_SESSION['branch_id'] ?? 0;
    } else {
        $branchKey = trim($_GET['branchId'] ?? '');
        if ($branchKey !== '') {
            $sql .= ' WHERE br.branch_key = ?';
            $params[] = $branchKey;
        }
    }
    $sql .= ' ORDER BY ia.created_at DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $log = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['quantity'] = (int) $row['quantity'];
        $row['previousStock'] = (int) $row['previousStock'];
        $row['newStock'] = (int) $row['newStock'];
        return $row;
    }, $stmt->fetchAll());

    echo json_encode(['success' => true, 'log' => $log]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier getAdjustmentLog error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading the adjustment log.']);
}
