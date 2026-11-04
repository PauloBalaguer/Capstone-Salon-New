<?php

/**
 * Staff: ask the branch cashier to mark a no-show, or to reassign/reschedule a
 * late customer. Staff can't do either themselves, because a no-show forfeits
 * the customer's deposit. Only the booking's main stylist can ask, and nothing
 * about the booking or its payment changes here.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/booking/Scheduling.php';
require_once '../../backend/shared/config/booking/ArrivalRequest.php';

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
$kind = trim($_POST['kind'] ?? '');

if ($referenceCode === '' || !in_array($kind, ArrivalRequest::KINDS, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking reference or request.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id, CONCAT(first_name, \' \', last_name) AS name FROM employees WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $employee = $stmt->fetch();
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No staff profile is linked to this account.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT id FROM appointments WHERE reference_code = ?');
    $stmt->execute([$referenceCode]);
    $appointmentId = $stmt->fetchColumn();
    if (!$appointmentId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    $pdo->beginTransaction();
    $appointment = Scheduling::lockAppointment($pdo, (int) $appointmentId);

    if ((int) $appointment['employee_id'] !== (int) $employee['id']) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the main stylist on this booking can send this request.']);
        exit();
    }
    if ($appointment['status'] !== 'Confirmed') {
        throw new RuntimeException("This booking is {$appointment['status']}, so there is nothing for the cashier to review.");
    }

    $minutesLate = Scheduling::minutesLate($pdo, $appointment);
    if ($minutesLate < 0) {
        throw new RuntimeException('The reserved time has not arrived yet.');
    }
    if ($kind === ArrivalRequest::NO_SHOW && !Scheduling::isPastArrivalGrace($pdo, $appointment)) {
        throw new RuntimeException('The customer is still within the grace period.');
    }
    if (in_array($kind, ArrivalRequest::openToday($pdo)[$referenceCode] ?? [], true)) {
        throw new RuntimeException('You already sent this request to the cashier today.');
    }

    ArrivalRequest::send($pdo, $appointment, $employee['name'], $kind, $minutesLate);
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Request sent to the cashier. Nothing changes until they act on it.']);
} catch (PDOException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!($e instanceof PDOException)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
    http_response_code(500);
    error_log('arrival request error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while sending the request.']);
}
