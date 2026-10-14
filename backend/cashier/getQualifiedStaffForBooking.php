<?php

/**
 * Cashier: Available Qualified Staff for a Confirmed Booking
 *
 * Staff Assignments tab, Step 2 of "Confirmed Booking -> Available Qualified
 * Staff -> Assign Staff". Looks up the booking's own branch/services/date-
 * time server-side (never trusted from the client) and returns only staff
 * who belong to that branch, cover every one of its services (staff_services),
 * are On Shift (open attendance row today -- see Scheduling::autoCloseAttendance),
 * and have no overlapping booking for the full service duration
 * (Scheduling::staffHasConflict, the same check assignStaff.php enforces on
 * write so this list can never suggest someone assignStaff.php would reject).
 * Satisfaction score/tips are intentionally not used to filter or rank.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/Scheduling.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']);
    exit();
}

$referenceCode = trim($_GET['id'] ?? '');
if ($referenceCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A booking reference is required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id, branch_id, employee_id, appointment_datetime, status FROM appointments WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$referenceCode]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    $isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';
    if ($isCashier && (int) $appointment['branch_id'] !== (int) ($_SESSION['branch_id'] ?? 0)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    if ($appointment['status'] !== 'Confirmed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only Confirmed bookings can be assigned staff here.']);
        exit();
    }

    $branchId = (int) $appointment['branch_id'];

    $stmt = $pdo->prepare('SELECT service_id FROM appointment_services WHERE appointment_id = ?');
    $stmt->execute([$appointment['id']]);
    $requiredServiceIds = array_map('intval', array_column($stmt->fetchAll(), 'service_id'));

    $qualified = [];
    $stmt = $pdo->prepare("
        SELECT e.id, CONCAT(e.first_name, ' ', e.last_name) AS name, e.position AS role
        FROM employees e
        WHERE e.branch_id = ? AND e.is_active = 1
        AND EXISTS (SELECT 1 FROM attendance att WHERE att.employee_id = e.id AND DATE(att.clock_in_time) = CURDATE() AND att.clock_out_time IS NULL)
        ORDER BY e.first_name, e.last_name
    ");
    $stmt->execute([$branchId]);
    $onShiftStaff = $stmt->fetchAll();

    if (!empty($requiredServiceIds)) {
        $placeholders = implode(',', array_fill(0, count($requiredServiceIds), '?'));
    }
    $durationMinutes = Scheduling::totalDurationMinutes($pdo, $requiredServiceIds);

    foreach ($onShiftStaff as $staff) {
        if (!empty($requiredServiceIds)) {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT service_id) FROM staff_services WHERE employee_id = ? AND service_id IN ($placeholders)");
            $stmt->execute(array_merge([$staff['id']], $requiredServiceIds));
            if ((int) $stmt->fetchColumn() !== count($requiredServiceIds)) {
                continue; // Doesn't cover every service on this booking.
            }
        }

        if (Scheduling::staffHasConflict($pdo, (int) $staff['id'], $appointment['appointment_datetime'], $durationMinutes, $appointment['id'])) {
            continue; // Already booked somewhere else for this window.
        }

        $qualified[] = [
            'id' => (string) $staff['id'],
            'name' => $staff['name'],
            'role' => $staff['role'] ?: 'Staff',
        ];
    }

    echo json_encode(['success' => true, 'staff' => $qualified]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('cashier getQualifiedStaffForBooking error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading available staff.']);
}
