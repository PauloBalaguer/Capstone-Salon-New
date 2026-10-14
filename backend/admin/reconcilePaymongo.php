<?php

/**
 * Admin: PayMongo reconciliation (Reports -> PayMongo Reconciliation)
 *
 * GET from / to (YYYY-MM-DD, default: last 30 days): pulls the payments
 * PayMongo actually received in that range and matches each to a booking by
 * payment id, falling back to the booking reference stored in the payment's
 * metadata. Every row gets a verdict:
 *   ok        -- recorded here with the same amount and refund state
 *   warning   -- needs a look (paid but not recorded here, refund states
 *                disagree, amount differs, refund still owed)
 *   danger    -- money with no booking, or a booking marked paid online
 *                that PayMongo has no payment for
 * plus totals (gross, PayMongo fees, net, refunded) for the range.
 *
 * POST action=sync reference=<booking>: re-reads that booking's checkout
 * from PayMongo and records the payment if it was missed (e.g. the webhook
 * never arrived and the customer closed the tab).
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/PayMongo.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}
if (!PayMongo::isConfigured()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'PayMongo keys are not set up (backend/config/paymongo_credentials.php).']);
    exit();
}

try {
    $pdo = Database::getInstance();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $reference = trim($_POST['reference'] ?? '');
        $stmt = $pdo->prepare('SELECT id, customer_id, reference_code, status, deposit_paid, paymongo_checkout_id FROM appointments WHERE reference_code = ? LIMIT 1');
        $stmt->execute([$reference]);
        $appointment = $stmt->fetch();
        if (($_POST['action'] ?? '') !== 'sync' || !$appointment) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown booking or action.']);
            exit();
        }
        $paid = PayMongo::syncAppointment($pdo, $appointment);
        echo json_encode([
            'success' => $paid,
            'reference' => $reference,
            'message' => $paid ? "Payment for {$reference} is now recorded." : "PayMongo has no completed payment for {$reference}'s checkout.",
        ]);
        exit();
    }

    // Date range in Manila time (the salon's clock), independent of PHP's timezone setting.
    $tz = new DateTimeZone('Asia/Manila');
    $isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    $from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : (new DateTime('-30 days', $tz))->format('Y-m-d');
    $to = $isDate($_GET['to'] ?? null) ? $_GET['to'] : (new DateTime('now', $tz))->format('Y-m-d');
    $fromTs = (new DateTime($from . ' 00:00:00', $tz))->getTimestamp();
    $toTs = (new DateTime($to . ' 23:59:59', $tz))->getTimestamp();

    [$payments, $truncated] = PayMongo::listPayments($fromTs);
    $payments = array_values(array_filter($payments, fn($p) => ($p['attributes']['created_at'] ?? 0) <= $toTs));

    // Every booking that ever used online payment; small enough to index in memory.
    $rows = $pdo->query("
        SELECT id, reference_code, status, payment_status, deposit_paid, deposit_amount, deposit_method,
               deposit_reference, deposit_recorded_at, reservation_amount_due
        FROM appointments
        WHERE preferred_payment_method = 'PayMongo' OR deposit_method = 'PayMongo' OR paymongo_checkout_id IS NOT NULL
    ")->fetchAll();
    $byPaymentId = [];
    $byReference = [];
    foreach ($rows as $row) {
        if ($row['deposit_reference']) $byPaymentId[$row['deposit_reference']] = $row;
        $byReference[$row['reference_code']] = $row;
    }

    $results = [];
    $seenPaymentIds = [];
    $totals = ['count' => 0, 'gross' => 0, 'fees' => 0, 'net' => 0, 'refunded' => 0, 'issues' => 0];

    foreach ($payments as $payment) {
        $a = $payment['attributes'];
        if (!in_array($a['status'] ?? '', ['paid', 'refunded', 'partially_refunded'], true)) continue; // failed/pending attempts moved no money

        $seenPaymentIds[$payment['id']] = true;
        $reference = $a['metadata']['reference'] ?? (preg_match('/\bLM-[A-Z]+-\d{4}-\d+\b/', (string) ($a['description'] ?? ''), $m) ? $m[0] : null);
        $local = $byPaymentId[$payment['id']] ?? ($reference ? ($byReference[$reference] ?? null) : null);

        $amount = ($a['amount'] ?? 0) / 100;
        $refunded = 0;
        foreach ($a['refunds'] ?? [] as $refund) {
            $r = $refund['attributes'] ?? $refund;
            if (in_array($r['status'] ?? 'succeeded', ['succeeded', 'pending'], true)) $refunded += ($r['amount'] ?? 0) / 100;
        }

        $totals['count']++;
        $totals['gross'] += $amount;
        $totals['fees'] += ($a['fee'] ?? 0) / 100;
        $totals['net'] += ($a['net_amount'] ?? 0) / 100;
        $totals['refunded'] += $refunded;

        [$severity, $issue, $canSync] = ['ok', 'Matched', false];
        $localRefunded = $local && in_array($local['payment_status'], ['Refunded', 'Refund Processing'], true);
        if (!$local) {
            [$severity, $issue] = ['danger', 'Payment has no matching booking in the system'];
        } elseif ((int) $local['deposit_paid'] !== 1) {
            [$severity, $issue, $canSync] = ['warning', 'Paid in PayMongo but not recorded in the system', true];
        } elseif ($local['deposit_reference'] !== $payment['id']) {
            [$severity, $issue] = ['warning', 'Booking has a different payment recorded (' . ($local['deposit_reference'] ?: 'none') . ')'];
        } elseif (abs((float) $local['deposit_amount'] - $amount) > 0.009) {
            [$severity, $issue] = ['warning', 'Amount differs: system has ₱' . number_format((float) $local['deposit_amount'], 2)];
        } elseif ($refunded > 0 && !$localRefunded) {
            [$severity, $issue] = ['warning', 'Refunded in PayMongo but not marked refunded in the system'];
        } elseif ($refunded == 0 && $localRefunded) {
            [$severity, $issue] = ['warning', 'Marked refunded in the system but PayMongo shows no refund'];
        } elseif ($local['payment_status'] === 'Refund Due') {
            [$severity, $issue] = ['warning', 'Refund still owed to the customer'];
        }
        if ($severity !== 'ok') $totals['issues']++;

        $results[] = [
            'paymentId' => $payment['id'],
            'reference' => $local['reference_code'] ?? $reference,
            'paidAt' => (new DateTime('@' . ($a['paid_at'] ?? $a['created_at'])))->setTimezone($tz)->format('Y-m-d H:i'),
            'method' => $a['source']['type'] ?? null,
            'amount' => $amount,
            'fee' => ($a['fee'] ?? 0) / 100,
            'net' => ($a['net_amount'] ?? 0) / 100,
            'refunded' => $refunded,
            'bookingStatus' => $local['status'] ?? null,
            'systemPaymentStatus' => $local['payment_status'] ?? null,
            'systemAmount' => $local && $local['deposit_amount'] !== null ? (float) $local['deposit_amount'] : null,
            'severity' => $severity,
            'issue' => $issue,
            'canSync' => $canSync,
        ];
    }

    // Recorded here as paid online in the range, but PayMongo has no such payment.
    // Skipped when the PayMongo list was cut short, to avoid false alarms.
    if (!$truncated) {
        foreach ($rows as $row) {
            if ((int) $row['deposit_paid'] !== 1 || $row['deposit_method'] !== 'PayMongo' || !$row['deposit_recorded_at']) continue;
            $recorded = (new DateTime($row['deposit_recorded_at'], $tz))->getTimestamp();
            if ($recorded < $fromTs || $recorded > $toTs || isset($seenPaymentIds[$row['deposit_reference']])) continue;
            $totals['issues']++;
            $results[] = [
                'paymentId' => $row['deposit_reference'],
                'reference' => $row['reference_code'],
                'paidAt' => substr($row['deposit_recorded_at'], 0, 16),
                'method' => null,
                'amount' => null, 'fee' => null, 'net' => null, 'refunded' => null,
                'bookingStatus' => $row['status'],
                'systemPaymentStatus' => $row['payment_status'],
                'systemAmount' => (float) $row['deposit_amount'],
                'severity' => 'danger',
                'issue' => 'Marked paid online in the system but not found in PayMongo',
                'canSync' => false,
            ];
        }
    }

    usort($results, fn($x, $y) => strcmp($y['paidAt'], $x['paidAt']));
    foreach (['gross', 'fees', 'net', 'refunded'] as $key) $totals[$key] = round($totals[$key], 2);

    echo json_encode([
        'success' => true,
        'from' => $from,
        'to' => $to,
        'truncated' => $truncated,
        'totals' => $totals,
        'rows' => $results,
    ]);
} catch (RuntimeException $e) {
    error_log('reconcilePaymongo PayMongo error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Could not reach PayMongo: ' . $e->getMessage()]);
} catch (PDOException $e) {
    error_log('reconcilePaymongo error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred while reconciling payments.']);
}
