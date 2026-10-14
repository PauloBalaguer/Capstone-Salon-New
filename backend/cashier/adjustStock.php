<?php

/**
 * Cashier: Stock Adjustment
 *
 * The Inventory tab's "Apply Stock Modification" form (Restock +/Deduct -,
 * with a required reason). Every adjustment is written to
 * inventory_adjustments so it shows up in the "View Adjustment Log" modal.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/EodLock.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$itemId = (int) ($_POST['id'] ?? 0);
$adjType = trim($_POST['adjType'] ?? '');
$quantity = (int) ($_POST['quantity'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if ($itemId <= 0 || !in_array($adjType, ['add', 'sub'], true) || $quantity <= 0 || $reason === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid product, adjustment type, quantity, and reason are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT product_name, branch_id, quantity_on_hand, max_stock, reorder_level FROM inventory WHERE id = ? AND is_active = 1'); // archived items can't be adjusted here
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit();
    }

    // A Cashier can only adjust their own branch's products -- see
    // database/migrations/007_cashier_branch_lock.sql.
    $isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';
    if ($isCashier && (int) $item['branch_id'] !== (int) ($_SESSION['branch_id'] ?? 0)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit();
    }

    $today = $pdo->query('SELECT CURDATE()')->fetchColumn();
    if ($isCashier && EodLock::isDateLocked($pdo, (int) $item['branch_id'], $today)) {
        http_response_code(423);
        echo json_encode(['success' => false, 'message' => 'This business day is closed. Ask an administrator to reopen it to make changes.']);
        exit();
    }

    $previousStock = (int) $item['quantity_on_hand'];
    $wasLow = $previousStock <= (int) $item['reorder_level'];
    $delta = $adjType === 'add' ? $quantity : -$quantity;
    $newStock = max(0, min((int) $item['max_stock'], $previousStock + $delta));

    $pdo->beginTransaction();

    $pdo->prepare('UPDATE inventory SET quantity_on_hand = ? WHERE id = ?')->execute([$newStock, $itemId]);

    $pdo->prepare('
        INSERT INTO inventory_adjustments (inventory_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$itemId, $adjType, $quantity, $reason, $previousStock, $newStock, $_SESSION['user_id']]);

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
    error_log('cashier adjustStock error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while adjusting stock.']);
}
