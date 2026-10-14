<?php

/**
 * Staff-facing appointment status update. Unlike
 * backend/admin/updateBookingStatus.php (any status, any booking, admin-only),
 * this only lets a Staff account move a booking through the two steps they're
 * actually responsible for on the shop floor -- starting a service and
 * finishing it -- and only for an appointment assigned to *them*.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/CustomerNotifier.php';

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

$referenceCode = trim($_POST['id'] ?? '');
$status = trim($_POST['status'] ?? '');

// Each target status is only reachable from a specific set of current
// statuses, so a client can't skip the "start service" step or re-complete
// an already-finished booking.
$allowedTransitions = [
    'In Progress' => ['Confirmed'],
    'Completed' => ['In Progress'],
];

if ($referenceCode === '' || !isset($allowedTransitions[$status])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking reference or status.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT id, CONCAT(first_name, \' \', last_name) AS name FROM employees WHERE user_id = ?');
    $stmt->execute([$userId]);
    $employee = $stmt->fetch();
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No staff profile is linked to this account.']);
        exit();
    }
    $employeeId = (int) $employee['id'];

    $stmt = $pdo->prepare('SELECT id, customer_id, employee_id, status, DATE(appointment_datetime) > CURDATE() AS is_future FROM appointments WHERE reference_code = ?');
    $stmt->execute([$referenceCode]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    if ((int) $appointment['employee_id'] !== $employeeId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'That booking is not assigned to you.']);
        exit();
    }

    if (!in_array($appointment['status'], $allowedTransitions[$status], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => "Booking can't move from \"{$appointment['status']}\" to \"{$status}\"."]);
        exit();
    }

    // A service can only start on its appointment day -- starting a future
    // booking early made the stylist show "With a client" days ahead.
    if ($status === 'In Progress' && (int) $appointment['is_future']) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This appointment is on a later date -- you can start it on the day itself.']);
        exit();
    }

    $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $appointment['id']]);

    if ($status === 'Completed') {
        CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'COMPLETED', "Your appointment {$referenceCode} is now Completed. Thank you for choosing us!");
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["{$employee['name']} marked {$referenceCode} as {$status}."]);

    echo json_encode(['success' => true, 'message' => "Booking marked as {$status}.", 'status' => $status]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('appointment update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the booking.']);
}
