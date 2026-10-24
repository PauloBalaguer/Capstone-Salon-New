<?php

/**
 * Cashier: Process Payment & Issue Receipt
 *
 * The checkout terminal (frontend/cashier/payment.html) posts here with the
 * booking reference, chosen payment method, tip, and any retail products
 * added to the cart. Service pricing always comes from the appointment's
 * own total_price + its appointment_services rows (never trusted from the
 * client); product pricing/stock is re-checked against the inventory table.
 * On success this writes one payments row + one payment_items row per
 * service/product (for receipt.php's itemized breakdown), deducts sold
 * product stock (logged the same way manual adjustments are), and marks
 * the appointment Completed.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/notifications/CustomerNotifier.php';
require_once '../../backend/shared/config/security/EodLock.php';
require_once '../../backend/shared/config/booking/Scheduling.php';
require_once '../../backend/shared/config/helpers/LoyaltyTier.php';
require_once '../../backend/shared/config/payments/ReservationPayment.php';

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

require_once __DIR__ . '/../../backend/shared/config/booking/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'tip' => ['money', false, 'an amount with at most two decimal places'],
    'cashReceived' => ['money', false, 'an amount with at most two decimal places'],
]);

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
$productErrors = [];
if (!is_array($decoded) || array_values($decoded) !== $decoded || count($decoded) > 100) {
    $productErrors['products'] = 'Please select valid retail products and whole-number quantities.';
} else {
    $seenProducts = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry) || BookingValidation::errors($entry, [
            'id' => ['id', true, 'a product selection'],
            'qty' => ['quantity', true, 'a whole-number product quantity'],
        ])) {
            $productErrors['products'] = 'Please select valid retail products and positive whole-number quantities.';
            break;
        }
        $productId = (int) ($entry['id'] ?? 0);
        $qty = (int) ($entry['qty'] ?? 0);
        if (isset($seenProducts[$productId])) {
            $productErrors['products'] = 'Please combine quantities for the same product into one line.';
            break;
        }
        $seenProducts[$productId] = true;
        if ($productId > 0 && $qty > 0) {
            $products[] = ['id' => $productId, 'qty' => $qty];
        }
    }
}
if ($productErrors) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => reset($productErrors), 'errors' => $productErrors]);
    exit();
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

    $pdo->beginTransaction();
    $appointment = Scheduling::lockAppointment($pdo, $appointmentId);

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

    $stmt = $pdo->prepare('SELECT id FROM payments WHERE appointment_id = ? FOR UPDATE');
    $stmt->execute([$appointmentId]);
    if ($stmt->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This booking has already been paid.']);
        exit();
    }
    if (!in_array($appointment['status'], ['Confirmed', 'In Progress', 'Completed'], true)) {
        throw new RuntimeException('Confirm this booking before cashier checkout.');
    }
    if ($appointment['deposit_paid'] && (float) $appointment['deposit_amount'] > 0 && $appointment['deposit_recorded_by'] === null) {
        throw new RuntimeException('Verify the reservation payment before collecting the remaining balance.');
    }
    if ($appointment['paymongo_checkout_id'] && !$appointment['deposit_paid']) {
        throw new RuntimeException('The online reservation payment is still pending. Check its status before checkout.');
    }

    $stmt = $pdo->prepare('
        SELECT s.id, s.service_name AS name, s.price, s.loyalty_multiplier AS loyaltyMultiplier
        FROM appointment_services aps
        JOIN services s ON s.id = aps.service_id
        WHERE aps.appointment_id = ?
        ORDER BY aps.id
    ');
    $stmt->execute([$appointmentId]);
    $services = $stmt->fetchAll();

    $productLines = [];
    if (!empty($products)) {
        $ids = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, product_name AS name, branch_id, quantity_on_hand AS stock, sale_price AS price FROM inventory WHERE id IN ($placeholders) AND is_active = 1 ORDER BY id FOR UPDATE"); // archived items can't be sold
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
    $total = round($remainingBalance + $productsTotal + $tip, 2);

    $changeGiven = null;
    if ($paymentMethod === 'Cash') {
        if ($cashReceived < $total - 0.001) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Cash received is less than the amount due.']);
            exit();
        }
        $changeGiven = round($cashReceived - $total, 2);
    }

    $pdo->prepare('
        INSERT INTO payments (appointment_id, amount, tip_amount, cash_received, change_given, payment_method, status, processed_by)
        VALUES (?, ?, ?, ?, ?, ?, "Paid", ?)
    ')->execute([$appointmentId, $total, $tip, $cashReceived, $changeGiven, $paymentMethod, $_SESSION['user_id']]);
    $paymentId = $pdo->lastInsertId();

    $insItem = $pdo->prepare('
        INSERT INTO payment_items (payment_id, item_type, item_name, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $receiptPrices = ReservationPayment::receiptServicePrices($services, $servicesTotal);
    if (!$services) {
        $insItem->execute([$paymentId, 'Service', 'Booked salon services', 1, $servicesTotal, $servicesTotal]);
    }
    foreach ($services as $index => $service) {
        $price = $receiptPrices[$index];
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
        if ($previousStock < $product['qty']) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Insufficient stock for {$product['name']}. Please reduce the quantity."]);
            exit();
        }
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
    $pointsEarned = LoyaltyTier::earnedPoints($servicesTotal, $services);
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
} catch (PDOException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!($e instanceof PDOException) || in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e instanceof PDOException
            ? 'This checkout changed or was already processed. Refresh and check the receipt before trying again.' : $e->getMessage()]);
        exit();
    }
    http_response_code(500);
    error_log('cashier payment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while processing payment.']);
}
