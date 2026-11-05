<?php

/**
 * Cashier: Payment Records
 *
 * frontend/cashier/payment.html (Payment Records) lists every payment the
 * branch has received in a date range, newest first. Two kinds of rows:
 *   - "Checkout"    : a payments row written by payment.php (the final bill).
 *   - "Reservation" : a reservation/deposit payment a cashier verified
 *                     (appointments.deposit_recorded_by is set).
 * Read-only. Taking payments happens from the Cashier Panel's checkout popup.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']);
    exit();
}

$isDate = fn($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
$to = $isDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -29 days'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

// A Cashier only sees their own branch -- see database/migrations/007_cashier_branch_lock.sql.
$branchSql = '';
$branchParams = [];
if (($_SESSION['user_role'] ?? '') === 'Cashier') {
    $branchSql = ' AND a.branch_id = ?';
    $branchParams[] = $_SESSION['branch_id'] ?? 0;
}

try {
    $pdo = Database::getInstance();

    $services = "(SELECT GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ')
                  FROM appointment_services aps JOIN services s ON s.id = aps.service_id
                  WHERE aps.appointment_id = a.id)";

    $checkoutSql = "
        SELECT
            'Checkout' AS type,
            p.id AS paymentId,
            DATE_FORMAT(p.payment_date, '%Y-%m-%d %H:%i:%s') AS paidAt,
            a.reference_code AS reference,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            {$services} AS serviceName,
            CASE WHEN e.id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name) ELSE NULL END AS stylistName,
            p.payment_method AS method,
            p.amount AS amount,
            p.tip_amount AS tip,
            p.status AS status,
            p.processed_by AS recordedBy,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ce.first_name, ''), ' ', COALESCE(ce.last_name, ''))), ''), u.display_name) AS cashierName
        FROM payments p
        JOIN appointments a ON a.id = p.appointment_id
        JOIN customers c ON c.id = a.customer_id
        LEFT JOIN employees e ON e.id = a.employee_id
        LEFT JOIN users u ON u.id = p.processed_by
        LEFT JOIN employees ce ON ce.user_id = p.processed_by
        WHERE DATE(p.payment_date) BETWEEN ? AND ?{$branchSql}
    ";

    $reservationSql = "
        SELECT
            'Reservation' AS type,
            NULL AS paymentId,
            DATE_FORMAT(a.deposit_recorded_at, '%Y-%m-%d %H:%i:%s') AS paidAt,
            a.reference_code AS reference,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            {$services} AS serviceName,
            CASE WHEN e.id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name) ELSE NULL END AS stylistName,
            a.deposit_method AS method,
            a.deposit_amount AS amount,
            0 AS tip,
            a.payment_status AS status,
            a.deposit_recorded_by AS recordedBy,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ce.first_name, ''), ' ', COALESCE(ce.last_name, ''))), ''), u.display_name) AS cashierName
        FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        LEFT JOIN employees e ON e.id = a.employee_id
        LEFT JOIN users u ON u.id = a.deposit_recorded_by
        LEFT JOIN employees ce ON ce.user_id = a.deposit_recorded_by
        WHERE a.deposit_recorded_by IS NOT NULL AND a.deposit_paid = 1 AND a.deposit_amount > 0
          AND a.deposit_recorded_at IS NOT NULL AND DATE(a.deposit_recorded_at) BETWEEN ? AND ?{$branchSql}
    ";

    $stmt = $pdo->prepare("({$checkoutSql}) UNION ALL ({$reservationSql}) ORDER BY paidAt DESC LIMIT 1000");
    $stmt->execute(array_merge([$from, $to], $branchParams, [$from, $to], $branchParams));

    $me = (int) ($_SESSION['user_id'] ?? 0);
    $records = array_map(function ($row) use ($me) {
        return [
            'type' => $row['type'],
            'paymentId' => $row['paymentId'] !== null ? (string) $row['paymentId'] : null,
            'invoiceId' => $row['paymentId'] !== null ? 'INV-' . str_pad((string) $row['paymentId'], 6, '0', STR_PAD_LEFT) : null,
            'paidAt' => $row['paidAt'],
            'reference' => $row['reference'],
            'clientName' => $row['clientName'],
            'serviceName' => $row['serviceName'],
            'stylistName' => $row['stylistName'],
            'method' => $row['method'],
            'amount' => (float) $row['amount'],
            'tip' => (float) $row['tip'],
            'status' => $row['status'],
            'cashierName' => $row['cashierName'] ?: null,
            // True when the logged-in cashier recorded it (Profile page "today at your desk").
            'mine' => $me > 0 && (int) $row['recordedBy'] === $me,
        ];
    }, $stmt->fetchAll());

    echo json_encode(['success' => true, 'from' => $from, 'to' => $to, 'records' => $records]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier payment records error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading payment records.']);
}
