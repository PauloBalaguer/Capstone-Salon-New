<?php

/**
 * Staff-facing "log supplies used" endpoint. Always deducts stock (a staff
 * member consumes supplies during a service, they don't restock), and is
 * scoped to their own branch's inventory only. Admin's arbitrary +/- stock
 * adjustment (backend/admin/adjustStock.php) is a separate, admin-only tool.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Staff') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a staff member.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$itemId = (int) ($_POST['id'] ?? 0);
$quantity = (int) ($_POST['quantity'] ?? 0);
$note = trim($_POST['note'] ?? '');
$referenceCode = trim($_POST['referenceCode'] ?? '');

if ($itemId <= 0 || $quantity <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid quantity used.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT id, branch_id, CONCAT(first_name, \' \', last_name) AS name FROM employees WHERE user_id = ?');
    $stmt->execute([$userId]);
    $employee = $stmt->fetch();
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No staff profile is linked to this account.']);
        exit();
    }
    $employeeId = (int) $employee['id'];

    $stmt = $pdo->prepare('SELECT product_name, branch_id, quantity_on_hand, reorder_level FROM inventory WHERE id = ? AND is_active = 1'); // archived items can't be logged
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        exit();
    }

    if ($item['branch_id'] === null || (int) $item['branch_id'] !== (int) $employee['branch_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'That item belongs to a different branch.']);
        exit();
    }

    if ($quantity > (int) $item['quantity_on_hand']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Only {$item['quantity_on_hand']} in stock -- quantity used can't exceed available stock."]);
        exit();
    }

    // Supply usage must be tied to one of the staff member's own assigned
    // appointments -- never an arbitrary/unrelated booking.
    $appointmentId = null;
    if ($referenceCode !== '') {
        $stmt = $pdo->prepare('SELECT id FROM appointments WHERE reference_code = ? AND employee_id = ?');
        $stmt->execute([$referenceCode, $employeeId]);
        $appointmentId = $stmt->fetchColumn();
        if (!$appointmentId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'That booking is not assigned to you.']);
            exit();
        }
    }

    $previousStock = (int) $item['quantity_on_hand'];
    $wasLow = $previousStock <= (int) $item['reorder_level'];
    $newStock = $previousStock - $quantity;

    $pdo->prepare('UPDATE inventory SET quantity_on_hand = ? WHERE id = ?')->execute([$newStock, $itemId]);

    $reason = 'Used for service' . ($referenceCode !== '' ? " (booking {$referenceCode})" : '') . ($note !== '' ? " - {$note}" : '');
    $pdo->prepare('
        INSERT INTO inventory_adjustments (inventory_id, appointment_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by)
        VALUES (?, ?, "sub", ?, ?, ?, ?, ?)
    ')->execute([$itemId, $appointmentId ?: null, $quantity, $reason, $previousStock, $newStock, $userId]);

    $logMessage = "{$employee['name']} used {$quantity}x {$item['product_name']}" . ($referenceCode !== '' ? " for booking {$referenceCode}" : '') . '.';
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')->execute([$logMessage]);

    if (!$wasLow && $newStock <= (int) $item['reorder_level']) {
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')
            ->execute(["{$item['product_name']} fell below minimum stock threshold."]);
    }

    echo json_encode(['success' => true, 'message' => 'Supply usage recorded.', 'stock' => $newStock]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('inventory update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while logging usage.']);
}
