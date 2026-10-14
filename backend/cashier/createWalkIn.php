<?php

/**
 * Cashier: New Walk-In Booking
 *
 * Unlike admin/createBooking.php (which leaves staff unassigned for later
 * dispatch), a walk-in checks in with a stylist already picked from the
 * "Assign Onsite Stylist" dropdown, and can select more than one service
 * (checkboxes) -- so this inserts one appointment_services row per service
 * and sums their prices for total_price (never trusted from the client).
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

$clientName = trim($_POST['clientName'] ?? '');
$clientPhone = trim($_POST['clientPhone'] ?? '');
$stylistId = (int) ($_POST['stylistId'] ?? 0);
$serviceIds = $_POST['serviceIds'] ?? [];
if (!is_array($serviceIds)) {
    $serviceIds = [$serviceIds];
}
$serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds))));

if ($clientName === '' || $clientPhone === '' || $stylistId <= 0 || empty($serviceIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide client name, contact number, at least one service, and a stylist.']);
    exit();
}

if (!preg_match('/^[0-9]{11}$/', $clientPhone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Contact number must be exactly 11 digits.']);
    exit();
}

[$firstName, $lastName] = array_pad(explode(' ', $clientName, 2), 2, '');

// A Cashier's branch is fixed by their account (session), never the client --
// see database/migrations/007_cashier_branch_lock.sql. Admin has no
// branch lock and still picks one explicitly via branchId.
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
        $stmt = $pdo->prepare('SELECT id, branch_key, branch_name FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionBranchId]);
    } else {
        $branchKey = trim($_POST['branchId'] ?? '');
        if ($branchKey === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A branch is required.']);
            exit();
        }
        $stmt = $pdo->prepare('SELECT id, branch_key, branch_name FROM branches WHERE branch_key = ? LIMIT 1');
        $stmt->execute([$branchKey]);
    }
    $branch = $stmt->fetch();
    if (!$branch) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }
    $branchId = (int) $branch['id'];

    $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE id = ? AND branch_id = ? AND is_active = 1');
    $stmt->execute([$stylistId, $branchId]);
    $stylist = $stmt->fetch();
    if (!$stylist) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Selected stylist is not available at this branch.']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare("SELECT id, service_name, price, duration_minutes FROM services WHERE branch_id = ? AND is_active = 1 AND id IN ($placeholders)");
    $stmt->execute(array_merge([$branchId], $serviceIds));
    $services = $stmt->fetchAll();
    if (count($services) !== count($serviceIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'One or more selected services are invalid for this branch.']);
        exit();
    }
    $totalPrice = array_sum(array_map(fn($s) => (float) $s['price'], $services));

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT service_id) FROM staff_services WHERE employee_id = ? AND service_id IN ($placeholders)");
    $stmt->execute(array_merge([$stylistId], $serviceIds));
    if ((int) $stmt->fetchColumn() !== count($serviceIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Selected stylist does not offer all of the selected services.']);
        exit();
    }

    $durationMinutes = array_sum(array_map(fn($s) => (int) $s['duration_minutes'], $services)) ?: Scheduling::DEFAULT_DURATION_MINUTES;
    // MySQL's own clock, not PHP's -- they can run in different timezones in
    // this environment, and appointment_datetime is always stamped via
    // MySQL's NOW() (see the INSERT below), so this is the only "now" value
    // guaranteed to agree with it for the conflict/hours checks that follow.
    $now = $pdo->query('SELECT NOW()')->fetchColumn();

    if (Scheduling::isOutsideOperatingHours($now, $durationMinutes, $branch['branch_key'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This branch is currently outside its operating hours.']);
        exit();
    }

    // Same duration-aware overlap check assignStaff.php uses -- a stylist
    // can't be walked in for a new client while already mid-service (or
    // scheduled) with someone else at this exact moment.
    if (Scheduling::staffHasConflict($pdo, $stylistId, $now, $durationMinutes)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Selected stylist already has an overlapping booking right now. Choose another stylist.']);
        exit();
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM customers WHERE phone_number = ?');
    $stmt->execute([$clientPhone]);
    $customerId = $stmt->fetchColumn();
    if ($customerId) {
        // The cashier retypes the client's name at every walk-in check-in, so
        // treat it as the source of truth and keep the customer record in
        // sync -- otherwise a reused phone number keeps showing whoever
        // first checked in with it, regardless of what's typed now.
        $pdo->prepare('UPDATE customers SET first_name = ?, last_name = ? WHERE id = ?')
            ->execute([$firstName, $lastName, $customerId]);
    } else {
        $ins = $pdo->prepare('INSERT INTO customers (first_name, last_name, phone_number) VALUES (?, ?, ?)');
        $ins->execute([$firstName, $lastName, $clientPhone]);
        $customerId = $pdo->lastInsertId();
    }

    $referenceCode = ''; // Generated atomically by the database insert trigger.

    // Walk-ins are trivially "locked" -- the client is physically present, so
    // there's no future-slot conflict risk. Marked paid-at-checkout for
    // consistency with the deposit_paid flag scheduled reservations use.
    // No deposit actually changes hands here (amount 0) -- the client pays
    // in full at checkout via cashier/payment.php, which flips
    // payment_status to 'Fully Paid' -- so payment_status starts at
    // 'Payment Required', not a verified state.
    $ins = $pdo->prepare('
        INSERT INTO appointments (reference_code, customer_id, employee_id, branch_id, appointment_datetime, total_price, status, payment_status, reminder_sent, notes, deposit_paid, deposit_amount, deposit_method, deposit_recorded_by, deposit_recorded_at)
        VALUES (?, ?, ?, ?, NOW(), ?, "Confirmed", "Payment Required", 1, ?, 1, 0, "Walk-in", ?, NOW())
    ');
    $ins->execute([$referenceCode, $customerId, $stylistId, $branchId, $totalPrice, "Walk-in checked in by cashier for {$clientName}, Phone: {$clientPhone}", $_SESSION['user_id']]);
    $appointmentId = $pdo->lastInsertId();
    $refStmt = $pdo->prepare('SELECT reference_code FROM appointments WHERE id = ?');
    $refStmt->execute([$appointmentId]);
    $referenceCode = $refStmt->fetchColumn();

    $insService = $pdo->prepare('INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)');
    foreach ($services as $service) {
        $insService->execute([$appointmentId, $service['id']]);
    }

    $stylistName = trim($stylist['first_name'] . ' ' . $stylist['last_name']);
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["Walk-in {$referenceCode} ({$clientName}) checked in at {$branch['branch_name']}, assigned to {$stylistName}."]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Walk-in added to the queue.', 'reference' => $referenceCode]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('cashier createWalkIn error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while creating the walk-in booking.']);
}
