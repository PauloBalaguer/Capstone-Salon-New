<?php

/**
 * Cashier: Direct Queue Assignment
 *
 * The Staff tab's "Assign Selected Staff Member" action -- reassigns which
 * stylist is handling an active ticket. Mirrors admin/assignStaff.php.
 */

require_once '../../shared/backend/config/setup/cors.php';
require_once '../../shared/backend/config/setup/session.php';
require_once '../../shared/backend/config/setup/database.php';
require_once '../../shared/backend/config/security/AuditLog.php';
require_once '../../shared/backend/config/booking/Scheduling.php';
require_once '../../shared/backend/config/notifications/StaffNotifier.php';

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

require_once __DIR__ . '/../../shared/backend/config/booking/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'id' => ['text', true, 'id'],
    'staffId' => ['id', true, 'a staff or branch selection'],
]);

$referenceCode = trim($_POST['id'] ?? '');
$staffId = (int) ($_POST['staffId'] ?? 0);

if ($referenceCode === '' || $staffId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking reference and staff are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id, branch_id, appointment_datetime FROM appointments WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$referenceCode]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    // A Cashier can only touch their own branch's bookings -- see
    // database/migrations/007_cashier_branch_lock.sql.
    $isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';
    if ($isCashier && (int) $appointment['branch_id'] !== (int) ($_SESSION['branch_id'] ?? 0)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    $pdo->beginTransaction();
    $appointment = Scheduling::lockAppointment($pdo, (int) $appointment['id']);
    Scheduling::validateAppointmentStaff($pdo, $appointment, $appointment['appointment_datetime'], $staffId);
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM employees WHERE id = ?');
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch();

    $pdo->prepare('UPDATE appointments SET employee_id = ? WHERE id = ?')->execute([$staffId, $appointment['id']]);

    $staffName = trim($staff['first_name'] . ' ' . $staff['last_name']);
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["{$staffName} assigned to booking {$referenceCode}."]);
    StaffNotifier::notify($pdo, $staffId, 'APPOINTMENT_ASSIGNED', "You've been assigned to a new appointment: {$referenceCode}.");

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Staff assigned.']);
} catch (PDOException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!($e instanceof PDOException) || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? Scheduling::STAFF_UNAVAILABLE_MESSAGE : $e->getMessage()]);
        exit();
    }
    http_response_code(500);
    error_log('cashier assignStaff error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while assigning staff.']);
}
