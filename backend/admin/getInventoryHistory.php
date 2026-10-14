<?php

/**
 * Admin: Inventory History
 *
 * Read-only log of every stock movement, from inventory_adjustments:
 * staff supply usage (backend/inventory/update.php), retail sales at
 * checkout (cashier/payment.php), manual adjustments (admin/cashier
 * adjustStock.php), item creation/edits (saveInventoryItem.php), and
 * archive/restore (toggleInventoryItemActive.php).
 *
 * GET filters (all optional):
 *   itemId   one item's full history (the "History" button)
 *   branchId branch_key
 *   kind     usage | sale | adjustment | item   (see KIND below)
 *   from, to Y-m-d (inclusive)
 *   q        item name contains
 * Returns the newest 300 matching rows.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin', 'Owner'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

// How each row is classified for the filter and the label shown.
// Staff usage is a deduction logged from a booking (or with a "used" reason);
// a retail sale is the checkout deduction.
const KIND_SQL = "
    CASE
        WHEN ia.adjustment_type IN ('create', 'edit', 'deactivate', 'reactivate') THEN 'item'
        WHEN ia.adjustment_type = 'sub' AND ia.reason LIKE 'Retail sale%' THEN 'sale'
        WHEN ia.adjustment_type = 'sub' AND (ia.appointment_id IS NOT NULL OR ia.reason LIKE '%used%' OR ia.reason LIKE '%usage%') THEN 'usage'
        ELSE 'adjustment'
    END";

$itemId = (int) ($_GET['itemId'] ?? 0);
$branchKey = trim($_GET['branchId'] ?? '');
$kind = trim($_GET['kind'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$q = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($itemId > 0) { $where[] = 'ia.inventory_id = ?'; $params[] = $itemId; }
if ($branchKey !== '' && $branchKey !== 'all') { $where[] = 'b.branch_key = ?'; $params[] = $branchKey; }
if (in_array($kind, ['usage', 'sale', 'adjustment', 'item'], true)) { $where[] = KIND_SQL . ' = ?'; $params[] = $kind; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'ia.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'ia.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if ($q !== '') { $where[] = 'i.product_name LIKE ?'; $params[] = '%' . $q . '%'; }

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('
        SELECT
            ia.id,
            DATE_FORMAT(ia.created_at, \'%Y-%m-%d %h:%i %p\') AS happenedAt,
            ia.adjustment_type AS type,
            ' . KIND_SQL . ' AS kind,
            ia.quantity, ia.previous_stock AS previousStock, ia.new_stock AS newStock, ia.reason,
            i.id AS itemId, i.product_name AS itemName, i.is_active AS itemActive,
            b.branch_key AS branchId, b.branch_name AS branchName,
            a.reference_code AS bookingReference,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(e.first_name, \'\'), \' \', COALESCE(e.last_name, \'\'))), \'\'), u.display_name, u.email) AS byName,
            r.role_name AS byRole
        FROM inventory_adjustments ia
        JOIN inventory i ON i.id = ia.inventory_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN appointments a ON a.id = ia.appointment_id
        LEFT JOIN users u ON u.id = ia.adjusted_by
        LEFT JOIN roles r ON r.id = u.role_id
        LEFT JOIN employees e ON e.user_id = u.id
        ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
        ORDER BY ia.created_at DESC, ia.id DESC
        LIMIT 300
    ');
    $stmt->execute($params);

    $labels = [
        'create' => 'Item added', 'edit' => 'Item edited', 'deactivate' => 'Archived', 'reactivate' => 'Restored',
    ];
    $rows = array_map(function ($row) use ($labels) {
        $row['id'] = (string) $row['id'];
        $row['itemId'] = (string) $row['itemId'];
        $row['quantity'] = (int) $row['quantity'];
        $row['previousStock'] = (int) $row['previousStock'];
        $row['newStock'] = (int) $row['newStock'];
        $row['itemActive'] = (bool) $row['itemActive'];
        $row['change'] = $row['newStock'] - $row['previousStock'];
        $row['label'] = $labels[$row['type']] ?? match ($row['kind']) {
            'usage' => 'Staff usage',
            'sale' => 'Retail sale',
            default => $row['type'] === 'add' ? 'Stock added' : 'Stock removed',
        };
        return $row;
    }, $stmt->fetchAll());

    echo json_encode(['success' => true, 'history' => $rows]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin getInventoryHistory error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading inventory history.']);
}
