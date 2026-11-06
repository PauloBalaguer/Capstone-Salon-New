<?php

/**
 * Staff-facing "log supplies used" endpoint. Always deducts stock (a staff
 * member consumes supplies during a service, they don't restock), and is
 * scoped to their own branch's inventory only. Admin's arbitrary +/- stock
 * adjustment (backend/admin/adjustStock.php) is a separate, admin-only tool.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/Csrf.php';
require_once '../../backend/shared/config/helpers/StaffMetrics.php';

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

Csrf::requireValid();

$itemId = (int) ($_POST['id'] ?? 0);
$quantity = (int) ($_POST['quantity'] ?? 0);
$note = trim($_POST['note'] ?? '');
$referenceCode = trim($_POST['referenceCode'] ?? '');

if ($itemId <= 0 || $quantity <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid quantity used.']);
    exit();
}

if ($referenceCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Supplies must be logged against one of your bookings.']);
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

    // Locked until commit, so two logs at the same moment can't both read the
    // same stock. Leaving early ends the request, which rolls this back.
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT product_name, branch_id, quantity_on_hand, reorder_level FROM inventory WHERE id = ? AND is_active = 1 FOR UPDATE'); // archived items can't be logged
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
    $stmt = $pdo->prepare('SELECT a.id, a.status, DATE(a.appointment_datetime) >= CURDATE() - INTERVAL 7 DAY AS is_recent FROM appointments a WHERE a.reference_code = ? AND ' . StaffMetrics::worksOnSql('a', '?'));
    $stmt->execute([$referenceCode, $employeeId, $employeeId]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'That booking is not assigned to you.']);
        exit();
    }
    $appointmentId = $appointment['id'];
    if (!in_array($appointment['status'], ['In Progress', 'Completed', 'Reviewed'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Start the service before logging supplies for this booking.']);
        exit();
    }
    // Same window as canLogSupplies() on the page; a running service is always open.
    if ($appointment['status'] !== 'In Progress' && !(int) $appointment['is_recent']) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Supplies can only be logged within 7 days of the service.']);
        exit();
    }

    $previousStock = (int) $item['quantity_on_hand'];
    $wasLow = $previousStock <= (int) $item['reorder_level'];
    $newStock = $previousStock - $quantity;

    $pdo->prepare('UPDATE inventory SET quantity_on_hand = ? WHERE id = ?')->execute([$newStock, $itemId]);

    $reason = "Used for service (booking {$referenceCode})" . ($note !== '' ? " - {$note}" : '');
    $pdo->prepare('
        INSERT INTO inventory_adjustments (inventory_id, appointment_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by)
        VALUES (?, ?, "sub", ?, ?, ?, ?, ?)
    ')->execute([$itemId, $appointmentId, $quantity, $reason, $previousStock, $newStock, $userId]);

    $logMessage = "{$employee['name']} used {$quantity}x {$item['product_name']} for booking {$referenceCode}.";
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')->execute([$logMessage]);

    if (!$wasLow && $newStock <= (int) $item['reorder_level']) {
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "INVENTORY", ?)')
            ->execute(["{$item['product_name']} fell below minimum stock threshold."]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Supply usage recorded.', 'stock' => $newStock]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('inventory update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while logging usage.']);
}
