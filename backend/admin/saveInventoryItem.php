<?php

/**
 * Admin: Create/Edit Inventory Item
 *
 * The admin form collects name/branch/stock/minQty/costPrice/salePrice —
 * sku and type aren't part of that UI, so new rows get sensible defaults
 * (type 'Retail', sku NULL). salePrice is optional (supplies that are never
 * resold to a customer, e.g. gloves, don't need one), costPrice is not.
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

$itemId = (int) ($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$branchKey = trim($_POST['branchId'] ?? '');
$stock = (int) ($_POST['stock'] ?? -1);
$minQty = (int) ($_POST['minQty'] ?? -1);
$costPrice = filter_var(trim($_POST['costPrice'] ?? ''), FILTER_VALIDATE_FLOAT);

// salePrice is optional -- an item that's never resold at checkout (e.g. a
// professional-use-only supply) has no sale price at all, not zero.
$salePriceRaw = trim($_POST['salePrice'] ?? '');
$salePrice = $salePriceRaw === '' ? null : filter_var($salePriceRaw, FILTER_VALIDATE_FLOAT);
$supplier = trim($_POST['supplier'] ?? '');

if ($name === '' || $branchKey === '' || $stock < 0 || $minQty < 0 || $costPrice === false || $costPrice < 0 || $salePrice === false || ($salePrice !== null && $salePrice < 0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, branch, stock, minimum quantity, and cost price are required (sale price is optional).']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id FROM branches WHERE branch_key = ? LIMIT 1');
    $stmt->execute([$branchKey]);
    $branchId = $stmt->fetchColumn();
    if (!$branchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }

    if ($itemId > 0) {
        $stmt = $pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE id = ?');
        $stmt->execute([$itemId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Inventory item not found.']);
            exit();
        }
        $previousStock = (int) $existing['quantity_on_hand'];

        $pdo->prepare('UPDATE inventory SET product_name = ?, branch_id = ?, quantity_on_hand = ?, reorder_level = ?, cost_price = ?, sale_price = ?, supplier = ? WHERE id = ?')
            ->execute([$name, $branchId, $stock, $minQty, $costPrice, $salePrice, $supplier !== '' ? $supplier : null, $itemId]);

        // Audit trail: item-detail edits are logged alongside quantity
        // adjustments (see adjustStock.php) so the full change history for
        // a product lives in one place.
        $pdo->prepare('
            INSERT INTO inventory_adjustments (inventory_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by)
            VALUES (?, "edit", ?, ?, ?, ?, ?)
        ')->execute([$itemId, abs($stock - $previousStock), "Item details edited: {$name}.", $previousStock, $stock, $_SESSION['user_id']]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')
            ->execute(["Stock item updated: {$name}."]);

        echo json_encode(['success' => true, 'message' => 'Inventory item updated.']);
        exit();
    }

    $pdo->prepare("
        INSERT INTO inventory (branch_id, product_name, quantity_on_hand, reorder_level, cost_price, sale_price, supplier, type)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Retail')
    ")->execute([$branchId, $name, $stock, $minQty, $costPrice, $salePrice, $supplier !== '' ? $supplier : null]);
    $newItemId = $pdo->lastInsertId();

    $pdo->prepare('
        INSERT INTO inventory_adjustments (inventory_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by)
        VALUES (?, "create", ?, ?, 0, ?, ?)
    ')->execute([$newItemId, $stock, "New stock item added: {$name}.", $stock, $_SESSION['user_id']]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')
        ->execute(["New stock item added: {$name}."]);

    echo json_encode(['success' => true, 'message' => 'Inventory item added.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin saveInventoryItem error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving the inventory item.']);
}
