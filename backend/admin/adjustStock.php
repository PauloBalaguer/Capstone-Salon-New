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

$itemId = (int) ($_POST['id'] ?? 0);
$delta = (int) ($_POST['delta'] ?? 0);

if ($itemId <= 0 || $delta === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid inventory item or adjustment.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT product_name, branch_id, quantity_on_hand, max_stock, reorder_level FROM inventory WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        exit();
    }

    $previousStock = (int) $item['quantity_on_hand'];
    $wasLow = $previousStock <= (int) $item['reorder_level'];
    $newStock = max(0, min((int) $item['max_stock'], $previousStock + $delta));

    $pdo->beginTransaction();

    $pdo->prepare('UPDATE inventory SET quantity_on_hand = ? WHERE id = ?')->execute([$newStock, $itemId]);

    $pdo->prepare('
        INSERT INTO inventory_adjustments (inventory_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$itemId, $delta >= 0 ? 'add' : 'sub', abs($delta), 'Quick adjustment from Inventory page', $previousStock, $newStock, $_SESSION['user_id']]);

    if (!$wasLow && $newStock <= (int) $item['reorder_level']) {
        $stmt = $pdo->prepare('SELECT branch_name FROM branches WHERE id = ?');
        $stmt->execute([$item['branch_id']]);
        $branchName = $stmt->fetchColumn() ?: 'the branch';
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')
            ->execute(["{$item['product_name']} at {$branchName} fell below minimum stock threshold."]);
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Stock updated.', 'stock' => $newStock]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('admin adjustStock error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while adjusting stock.']);
}
