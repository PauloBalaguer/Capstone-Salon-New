<?php

/**
 * Admin-only: Add/Edit Retail Product
 *
 * Backed the cashier Inventory tab's "Add New Product"/"Edit Product
 * Details" modals; those were removed from the Cashier UI (cashiers may
 * record stock adjustments via adjustStock.php but must not add products,
 * delete them, or change prices). Admin's own inventory page uses separate
 * endpoints (backend/admin/saveInventoryItem.php etc.) and never called
 * this file, so restricting it to Admin-only here does not affect Owner
 * inventory management. Kept under backend/cashier/ rather than moved, to
 * avoid touching unrelated admin code for this patch.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$productId = (int) ($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? '');
$stock = (int) ($_POST['stock'] ?? -1);
$maxStock = (int) ($_POST['maxStock'] ?? -1);
$price = (float) ($_POST['price'] ?? -1);

if ($name === '' || $stock < 0 || $maxStock <= 0 || $price < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, stock, max quantity, and price are required.']);
    exit();
}

$reorderLevel = max(1, (int) ceil($maxStock * 0.2));
$isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';
$sessionBranchId = $_SESSION['branch_id'] ?? null;

try {
    $pdo = Database::getInstance();

    if ($productId > 0) {
        $stmt = $pdo->prepare('SELECT id, branch_id FROM inventory WHERE id = ?');
        $stmt->execute([$productId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
            exit();
        }
        if ($isCashier && (int) $existing['branch_id'] !== (int) $sessionBranchId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'This product belongs to another branch.']);
            exit();
        }

        $pdo->prepare('UPDATE inventory SET product_name = ?, category = ?, max_stock = ?, reorder_level = ?, sale_price = ? WHERE id = ?')
            ->execute([$name, $category ?: null, $maxStock, $reorderLevel, $price, $productId]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')
            ->execute(["Product updated: {$name}."]);

        echo json_encode(['success' => true, 'message' => 'Product updated.']);
        exit();
    }

    if ($isCashier) {
        if (!$sessionBranchId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your account is not assigned to a branch.']);
            exit();
        }
        $branchId = $sessionBranchId;
    } else {
        $branchKey = trim($_POST['branchId'] ?? '');
        if ($branchKey === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A branch is required to add a new product.']);
            exit();
        }
        $stmt = $pdo->prepare('SELECT id FROM branches WHERE branch_key = ? LIMIT 1');
        $stmt->execute([$branchKey]);
        $branchId = $stmt->fetchColumn();
        if (!$branchId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
            exit();
        }
    }

    $pdo->prepare("
        INSERT INTO inventory (branch_id, product_name, category, quantity_on_hand, reorder_level, max_stock, cost_price, sale_price, type)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'Retail')
    ")->execute([$branchId, $name, $category ?: null, $stock, $reorderLevel, $maxStock, $price]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')
        ->execute(["New product added to catalog: {$name}."]);

    echo json_encode(['success' => true, 'message' => 'Product added to inventory.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier saveProduct error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving the product.']);
}
