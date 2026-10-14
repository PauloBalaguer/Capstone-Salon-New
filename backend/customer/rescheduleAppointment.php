<?php

/**
 * Customer: Reschedule My Appointment
 *
 * GET  ?id=<reference>&date=<Y-m-d>  -> that day's slots for this booking
 * POST id, date, time                -> moves the booking there
 *
 * Only the customer's own booking. Same rules as the cashier's reschedule
 * (backend/config/Reschedule.php), plus a cut-off: at least
 * Reschedule::CUSTOMER_CUTOFF_HOURS ahead, unless the salon itself asked
 * for the change ("Reschedule Requested"). The deposit carries over.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/EodLock.php';
require_once '../config/Reschedule.php';

date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to manage your appointments.']);
    exit();
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;
$referenceCode = trim($input['id'] ?? '');
$date = trim($input['date'] ?? '');
$time = trim($input['time'] ?? '');
// Optional: the stylist the booking should move to ('' = keep current if free, else any qualified).
$staffId = (int) ($input['staffId'] ?? 0) ?: null;

if ($referenceCode === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ($isPost && $time === '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please choose a date' . ($isPost ? ' and time' : '') . '.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $appointment = Reschedule::load($pdo, $referenceCode, null, (int) $_SESSION['user_id']);
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
        exit();
    }
    if ($blocked = Reschedule::blockedReason($appointment, true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $blocked]);
        exit();
    }

    if (!$isPost) {
        echo json_encode([
            'success' => true,
            'durationMinutes' => $appointment['duration_minutes'],
            'currentStaffId' => $appointment['employee_id'] !== null ? (string) $appointment['employee_id'] : null,
            'stylists' => array_map(fn($s) => [
                'id' => (string) $s['id'],
                'name' => trim($s['first_name'] . ' ' . $s['last_name']),
                'role' => $s['position'],
            ], Reschedule::qualifiedStylists($pdo, $appointment)),
            'slots' => Reschedule::slots($pdo, $appointment, $date, $staffId),
        ]);
        exit();
    }

    if (EodLock::isDateLocked($pdo, (int) $appointment['branch_id'], $date)) {
        http_response_code(423);
        echo json_encode(['success' => false, 'message' => 'That date is no longer open for bookings. Please pick another day.']);
        exit();
    }

    try {
        $result = Reschedule::apply($pdo, $appointment, $date, $time, true, '', $staffId);
    } catch (RuntimeException $e) {
        http_response_code(409);
        echo json_encode(['success' => false, 'conflict' => true, 'message' => $e->getMessage()]);
        exit();
    }

    $ts = strtotime($result['datetime']);
    echo json_encode([
        'success' => true,
        'message' => "Your appointment is now on {$result['when']}.",
        'appointment' => [
            'id' => $referenceCode,
            'date' => date('Y-m-d', $ts),
            'time' => date('h:i A', $ts),
            'status' => $result['status'],
            'paymentStatus' => $result['paymentStatus'],
            'staffName' => $result['stylist'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('customer rescheduleAppointment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while rescheduling.']);
}
