<?php

/**
 * Cashier: Process Payment & Issue Receipt
 *
 * The checkout terminal (pages/cashier/payment.html) posts here with the
 * booking reference, chosen payment method, tip, and any retail products
 * added to the cart. Service pricing always comes from the appointment's
 * own total_price + its appointment_services rows (never trusted from the
 * client); product pricing/stock is re-checked against the inventory table.
 * On success this writes one payments row + one payment_items row per
 * service/product (for receipt.php's itemized breakdown), deducts sold
 * product stock (logged the same way manual adjustments are), and marks
 * the appointment Completed.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/CustomerNotifier.php';
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

$referenceCode = trim($_POST['reference'] ?? '');
$paymentMethod = trim($_POST['paymentMethod'] ?? '');
$tip = (float) ($_POST['tip'] ?? 0);
$allowedMethods = ['Cash', 'GCash', 'Maya'];

if ($referenceCode === '' || !in_array($paymentMethod, $allowedMethods, true) || $tip < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid booking reference and payment method are required.']);
    exit();
}

// Tips are a Cash-only, in-person thing for now -- never folded into a
// GCash/Maya transaction amount.
if ($paymentMethod !== 'Cash') {
    $tip = 0.0;
}

$cashReceived = null;
if ($paymentMethod === 'Cash') {
    $cashReceived = filter_var($_POST['cashReceived'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($cashReceived === false || $cashReceived < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid cash received amount is required.']);
        exit();
    }
}

$products = [];
$productsRaw = trim($_POST['products'] ?? '[]');
$decoded = json_decode($productsRaw, true);
if (is_array($decoded)) {
    foreach ($decoded as $entry) {
        $productId = (int) ($entry['id'] ?? 0);
        $qty = (int) ($entry['qty'] ?? 0);
        if ($productId > 0 && $qty > 0) {
            $products[] = ['id' => $productId, 'qty' => $qty];
        }
    }
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id, total_price, branch_id, customer_id, status, deposit_paid, deposit_amount, deposit_recorded_by, appointment_datetime FROM appointments WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$referenceCode]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }
    $appointmentId = (int) $appointment['id'];
    $appointmentBranchId = (int) $appointment['branch_id'];

    // A Cashier can only check out their own branch's bookings -- see
    // database/migrations/007_cashier_branch_lock.sql.
    $isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';
    if ($isCashier && $appointmentBranchId !== (int) ($_SESSION['branch_id'] ?? 0)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    if (in_array($appointment['status'], ['Cancelled', 'No-Show'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This booking was cancelled and can no longer be paid.']);
        exit();
    }

    $appointmentDate = substr($appointment['appointment_datetime'], 0, 10);
    if ($isCashier && EodLock::isDateLocked($pdo, $appointmentBranchId, $appointmentDate)) {
        http_response_code(423);
        echo json_encode(['success' => false, 'message' => 'This business day is closed. Ask an administrator to reopen it to make changes.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT id FROM payments WHERE appointment_id = ?');
    $stmt->execute([$appointmentId]);
    if ($stmt->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This booking has already been paid.']);
        exit();
    }

    $stmt = $pdo->prepare('
        SELECT s.id, s.service_name AS name, s.price, s.loyalty_multiplier AS loyaltyMultiplier
        FROM appointment_services aps
        JOIN services s ON s.id = aps.service_id
        WHERE aps.appointment_id = ?
    ');
    $stmt->execute([$appointmentId]);
    $services = $stmt->fetchAll();

    $productLines = [];
    if (!empty($products)) {
        $ids = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, product_name AS name, branch_id, quantity_on_hand AS stock, sale_price AS price FROM inventory WHERE id IN ($placeholders) AND is_active = 1"); // archived items can't be sold
        $stmt->execute($ids);
        $inventoryById = [];
        foreach ($stmt->fetchAll() as $row) {
            $inventoryById[(int) $row['id']] = $row;
        }
        foreach ($products as $entry) {
            $product = $inventoryById[$entry['id']] ?? null;
            // Products must belong to the same branch as the booking being
            // checked out -- otherwise stock would deduct from the wrong branch.
            if (!$product || (int) $product['branch_id'] !== $appointmentBranchId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'One or more retail products are invalid.']);
                exit();
            }
            if ($product['price'] === null || (float) $product['price'] <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "{$product['name']} is a salon supply and isn't for sale."]);
                exit();
            }
            if ((int) $product['stock'] < $entry['qty']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Insufficient stock for {$product['name']}."]);
                exit();
            }
            $productLines[] = [
                'id' => $entry['id'],
                'name' => $product['name'],
                'qty' => $entry['qty'],
                'price' => (float) $product['price'],
            ];
        }
    }

    $servicesTotal = (float) $appointment['total_price'];
    $productsTotal = array_reduce($productLines, fn($sum, $p) => $sum + ($p['qty'] * $p['price']), 0.0);

    // A verified reservation deposit (see backend/cashier|admin
    // updateStatus.php's "Confirmed" branch) already charged the customer
    // part of the service total -- only the remaining balance is due now,
    // not the full service price again.
    $reservationReceived = ($appointment['deposit_paid'] && $appointment['deposit_recorded_by'] !== null)
        ? (float) $appointment['deposit_amount'] : 0.0;
    $remainingBalance = max(0.0, $servicesTotal - $reservationReceived);
    $total = $remainingBalance + $productsTotal + $tip;

    $changeGiven = null;
    if ($paymentMethod === 'Cash') {
        if ($cashReceived < $total - 0.001) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Cash received is less than the amount due.']);
            exit();
        }
        $changeGiven = round($cashReceived - $total, 2);
    }

    $pdo->beginTransaction();

    $pdo->prepare('
        INSERT INTO payments (appointment_id, amount, tip_amount, cash_received, change_given, payment_method, status, processed_by)
        VALUES (?, ?, ?, ?, ?, ?, "Paid", ?)
    ')->execute([$appointmentId, $total, $tip, $cashReceived, $changeGiven, $paymentMethod, $_SESSION['user_id']]);
    $paymentId = $pdo->lastInsertId();

    $insItem = $pdo->prepare('
        INSERT INTO payment_items (payment_id, item_type, item_name, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    foreach ($services as $service) {
        $price = (float) $service['price'];
        $insItem->execute([$paymentId, 'Service', $service['name'], 1, $price, $price]);
    }

    $adjInsert = $pdo->prepare('
        INSERT INTO inventory_adjustments (inventory_id, adjustment_type, quantity, reason, previous_stock, new_stock, adjusted_by)
        VALUES (?, "sub", ?, "Retail sale (checkout)", ?, ?, ?)
    ');
    foreach ($productLines as $product) {
        $lineTotal = $product['qty'] * $product['price'];
        $insItem->execute([$paymentId, 'Product', $product['name'], $product['qty'], $product['price'], $lineTotal]);

        $stmt = $pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE id = ? FOR UPDATE');
        $stmt->execute([$product['id']]);
        $previousStock = (int) $stmt->fetchColumn();
        $newStock = max(0, $previousStock - $product['qty']);
        $pdo->prepare('UPDATE inventory SET quantity_on_hand = ? WHERE id = ?')->execute([$newStock, $product['id']]);
        $adjInsert->execute([$product['id'], $product['qty'], $previousStock, $newStock, $_SESSION['user_id']]);
    }

    $pdo->prepare("UPDATE appointments SET status = 'Completed', payment_status = 'Fully Paid' WHERE id = ?")->execute([$appointmentId]);

    // Loyalty points are earned on completed services only (not tip/products),
    // at a deliberately modest rate so redeem-then-reearn can't net-positive
    // against the PHP0.10/point redemption rate in submitBooking.php. Each
    // service can carry its own loyalty_multiplier (customer scoring --
    // e.g. a bridal package can be weighted to earn more than a basic
    // trim), applied here as a price-weighted average across the
    // appointment's services so the total still tracks what was actually
    // paid (servicesTotal, which may already reflect a promo discount).
    define('LOYALTY_EARN_RATE', 20); // 1 point per PHP20 of services (at 1.0x weight)
    $weightedServiceValue = array_reduce($services, fn($sum, $s) => $sum + ((float) $s['price'] * (float) $s['loyaltyMultiplier']), 0.0);
    $baseServiceValue = array_reduce($services, fn($sum, $s) => $sum + (float) $s['price'], 0.0);
    $scoringMultiplier = $baseServiceValue > 0 ? ($weightedServiceValue / $baseServiceValue) : 1.0;
    $pointsEarned = (int) floor(($servicesTotal * $scoringMultiplier) / LOYALTY_EARN_RATE);
    if ($pointsEarned > 0) {
        $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?')
            ->execute([$pointsEarned, $appointment['customer_id']]);
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
        ->execute(["Booking {$referenceCode} paid in full (PHP " . number_format($total, 2) . " via {$paymentMethod})."]);

    CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'PAYMENT_VERIFIED', "Your payment for {$referenceCode} has been verified. Status: Paid in Full.");
    CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'COMPLETED', "Your appointment {$referenceCode} is now Completed. Thank you for choosing us!");

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment processed.',
        'reference' => $referenceCode,
        'paymentId' => (string) $paymentId,
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('cashier payment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while processing payment.']);
}
