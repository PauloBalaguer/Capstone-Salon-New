<?php

/**
 * Admin: Deactivate / Reactivate Inventory Item
 *
 * Replaces the old hard-delete endpoint. inventory_adjustments has
 * ON DELETE CASCADE to inventory(id) -- deleting a product with any
 * adjustment history silently wiped that history too. Deactivating
 * (is_active = 0) removes it from active use (hidden from
 * the New Booking / cashier restock pickers by the existing is_active
 * filters elsewhere) while keeping every past record intact, and can be
 * reversed.
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
$active = filter_var($_POST['active'] ?? '', FILTER_VALIDATE_BOOLEAN);

if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid inventory item.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT product_name, quantity_on_hand, is_active FROM inventory WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        exit();
    }

    $pdo->prepare('UPDATE inventory SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $itemId]);

    $stock = (int) $item['quantity_on_hand'];
    $pdo->prepare('
        INSERT INTO inventory_adjustments (inventory_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by)
        VALUES (?, ?, 0, ?, ?, ?, ?)
    ')->execute([
        $itemId,
        $active ? 'reactivate' : 'deactivate',
        ($active ? 'Item restored from archive: ' : 'Item archived: ') . $item['product_name'],
        $stock, $stock, $_SESSION['user_id'],
    ]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')
        ->execute([($active ? 'Stock item restored from archive: ' : 'Stock item archived: ') . $item['product_name'] . '.']);

    echo json_encode(['success' => true, 'message' => $active ? 'Item restored.' : 'Item archived.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin toggleInventoryItemActive error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the item.']);
}
