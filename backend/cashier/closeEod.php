<?php

/**
 * Cashier: Close Business Day
 *
 * The Inventory tab's "Close Business Day" button. Never deletes or moves
 * any transactional data -- it snapshots today's settled invoice count and
 * gross revenue for the branch into daily_closures as a permanent audit
 * record (cashier, branch, date/time), and that record is what
 * EodLock::isDateLocked() checks to block further edits to that day's
 * transactions from the normal Cashier endpoints. One closure per branch
 * per business day (see migration 030's unique key) -- closing again the
 * same day is rejected instead of logging a duplicate; only Admin/Owner can
 * reopen (is_reopened = 1) to lift the lock.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/Scheduling.php';

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

// A Cashier's branch is fixed by their account (session), never the client --
// see database/migrations/007_cashier_branch_lock.sql.
$isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';

try {
    $pdo = Database::getInstance();

    if ($isCashier) {
        $sessionBranchId = $_SESSION['branch_id'] ?? null;
        if (!$sessionBranchId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your account is not assigned to a branch.']);
            exit();
        }
        $stmt = $pdo->prepare('SELECT id, branch_name FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionBranchId]);
    } else {
        $branchKey = trim($_POST['branchId'] ?? '');
        if ($branchKey === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A branch is required to close the register.']);
            exit();
        }
        $stmt = $pdo->prepare('SELECT id, branch_name FROM branches WHERE branch_key = ? LIMIT 1');
        $stmt->execute([$branchKey]);
    }
    $branch = $stmt->fetch();
    if (!$branch) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }
    $branchId = (int) $branch['id'];
    $businessDate = $pdo->query('SELECT CURDATE()')->fetchColumn();

    $stmt = $pdo->prepare('SELECT id FROM daily_closures WHERE branch_id = ? AND business_date = ? AND is_reopened = 0 LIMIT 1');
    $stmt->execute([$branchId, $businessDate]);
    if ($stmt->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This business day is already closed. Ask an administrator to reopen it if changes are needed.']);
        exit();
    }

    // Block on critical unfinished records: a Completed service that was
    // never paid, or a payment still mid-processing (status "Pending" --
    // see payments.status) for this branch today.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments a
        WHERE a.branch_id = ? AND a.status = 'Completed'
        AND DATE(a.appointment_datetime) = ?
        AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.id)
    ");
    $stmt->execute([$branchId, $businessDate]);
    $unpaidCompleted = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM payments p
        JOIN appointments a ON a.id = p.appointment_id
        WHERE a.branch_id = ? AND p.status = 'Pending' AND DATE(p.payment_date) = ?
    ");
    $stmt->execute([$branchId, $businessDate]);
    $processingPayments = (int) $stmt->fetchColumn();

    if ($unpaidCompleted > 0 || $processingPayments > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Cannot close: {$unpaidCompleted} completed booking(s) still unpaid and {$processingPayments} payment(s) still processing. Resolve these first.",
        ]);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(p.id) AS invoiceCount, COALESCE(SUM(p.amount), 0) AS grossRevenue
        FROM payments p
        JOIN appointments a ON a.id = p.appointment_id
        WHERE a.branch_id = ? AND p.status = 'Paid' AND DATE(p.payment_date) = ?
    ");
    $stmt->execute([$branchId, $businessDate]);
    $summary = $stmt->fetch();
    $invoiceCount = (int) $summary['invoiceCount'];
    $grossRevenue = (float) $summary['grossRevenue'];

    $pdo->prepare('INSERT INTO daily_closures (branch_id, business_date, closed_by, invoice_count, gross_revenue) VALUES (?, ?, ?, ?, ?)')
        ->execute([$branchId, $businessDate, $_SESSION['user_id'], $invoiceCount, $grossRevenue]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "EOD", ?)')
        ->execute(["{$branch['branch_name']} EOD closed: {$invoiceCount} invoices, PHP " . number_format($grossRevenue, 2) . " gross revenue."]);

    // The business day is over: anyone still on shift (forgot to sign out)
    // is clocked out now, tagged so their hours can be reviewed.
    $clockedOut = Scheduling::clockOutBranchAtEod($pdo, $branchId);
    if ($clockedOut) {
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "ATTENDANCE", ?)')
            ->execute(["{$branch['branch_name']} EOD: clocked out " . implode(', ', $clockedOut) . ' (did not sign out).']);
    }

    echo json_encode([
        'success' => true,
        'message' => 'End-of-day closed and logged.' . ($clockedOut ? ' Clocked out: ' . implode(', ', $clockedOut) . '.' : ''),
        'clockedOut' => $clockedOut,
        'invoiceCount' => $invoiceCount,
        'grossRevenue' => $grossRevenue,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier closeEod error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while closing the day.']);
}
