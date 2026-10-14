<?php

/**
 * Cashier: Receipt Lookup
 *
 * pages/cashier/receipt.html calls this with the reference code (and,
 * ideally, the payment id it was just handed by payment.php) to render a
 * printable itemized receipt.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']);
    exit();
}

$referenceCode = trim($_GET['reference'] ?? '');
$paymentId = (int) ($_GET['paymentId'] ?? 0);

if ($referenceCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A booking reference is required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $sql = "
        SELECT
            p.id AS paymentId,
            p.amount,
            p.tip_amount AS tipAmount,
            p.cash_received AS cashReceived,
            p.change_given AS changeGiven,
            p.payment_method AS paymentMethod,
            p.status,
            DATE_FORMAT(p.payment_date, '%Y-%m-%d %h:%i %p') AS paymentDate,
            a.reference_code AS reference,
            br.branch_name AS branchName,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            CASE WHEN e.id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name) ELSE NULL END AS stylistName,
            u.display_name AS cashierDisplayName,
            COALESCE(ce.first_name, NULL) AS cashierFirstName,
            COALESCE(ce.last_name, NULL) AS cashierLastName,
            a.total_price AS serviceCharges,
            CASE WHEN a.deposit_paid = 1 AND a.deposit_recorded_by IS NOT NULL THEN a.deposit_amount ELSE 0 END AS reservationReceived
        FROM payments p
        JOIN appointments a ON a.id = p.appointment_id
        JOIN customers c ON c.id = a.customer_id
        LEFT JOIN branches br ON br.id = a.branch_id
        LEFT JOIN employees e ON e.id = a.employee_id
        LEFT JOIN users u ON u.id = p.processed_by
        LEFT JOIN employees ce ON ce.user_id = p.processed_by
        WHERE a.reference_code = ?
    ";
    $params = [$referenceCode];

    // A Cashier can only look up receipts for their own branch -- see
    // database/migrations/007_cashier_branch_lock.sql.
    if (($_SESSION['user_role'] ?? '') === 'Cashier') {
        $sql .= ' AND a.branch_id = ?';
        $params[] = $_SESSION['branch_id'] ?? 0;
    }

    if ($paymentId > 0) {
        $sql .= ' AND p.id = ?';
        $params[] = $paymentId;
    }
    $sql .= ' ORDER BY p.id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payment = $stmt->fetch();
    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No receipt found for this booking.']);
        exit();
    }

    $cashierName = trim(($payment['cashierFirstName'] ?? '') . ' ' . ($payment['cashierLastName'] ?? ''));
    if ($cashierName === '') {
        $cashierName = $payment['cashierDisplayName'] ?: 'Cashier';
    }

    $stmt = $pdo->prepare('SELECT item_type AS itemType, item_name AS itemName, quantity, unit_price AS unitPrice, line_total AS lineTotal FROM payment_items WHERE payment_id = ? ORDER BY id');
    $stmt->execute([$payment['paymentId']]);
    $items = array_map(function ($row) {
        $row['quantity'] = (int) $row['quantity'];
        $row['unitPrice'] = (float) $row['unitPrice'];
        $row['lineTotal'] = (float) $row['lineTotal'];
        return $row;
    }, $stmt->fetchAll());

    $subtotal = array_reduce($items, fn($sum, $item) => $sum + $item['lineTotal'], 0.0);

    echo json_encode([
        'success' => true,
        'receipt' => [
            'paymentId' => (string) $payment['paymentId'],
            'invoiceId' => 'INV-' . str_pad((string) $payment['paymentId'], 6, '0', STR_PAD_LEFT),
            'reference' => $payment['reference'],
            'date' => $payment['paymentDate'],
            'branchName' => $payment['branchName'],
            'clientName' => $payment['clientName'],
            'stylistName' => $payment['stylistName'],
            'cashierName' => $cashierName,
            'items' => $items,
            'subtotal' => $subtotal,
            'serviceCharges' => (float) $payment['serviceCharges'],
            'reservationReceived' => (float) $payment['reservationReceived'],
            'tip' => (float) $payment['tipAmount'],
            'cashReceived' => $payment['cashReceived'] !== null ? (float) $payment['cashReceived'] : null,
            'changeGiven' => $payment['changeGiven'] !== null ? (float) $payment['changeGiven'] : null,
            'total' => (float) $payment['amount'],
            'paymentMethod' => $payment['paymentMethod'],
            'status' => $payment['status'],
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier receipt error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading the receipt.']);
}
