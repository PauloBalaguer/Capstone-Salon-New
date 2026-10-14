<?php

/**
 * Cashier: Reschedule Appointment
 *
 * GET  ?id=<reference>&date=<Y-m-d>  -> that day's slots for this booking
 * POST id, date, time[, reason]      -> moves the booking there
 *
 * Rules live in backend/config/Reschedule.php (shared with the customer
 * portal). A "Reschedule Requested" booking goes back to Confirmed if its
 * payment is already verified, else Pending; the optional reason is
 * included in the customer's notification.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/EodLock.php';
require_once '../config/Reschedule.php';

// php.ini on this XAMPP install is Europe/Berlin; "no slots in the past"
// must compare against the salon's local time.
date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Cashier', 'Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a cashier.']);
    exit();
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;
$referenceCode = trim($input['id'] ?? '');
$date = trim($input['date'] ?? '');
$time = trim($input['time'] ?? '');
// Optional: the stylist the booking should move to ('' = keep current if free, else any qualified).
$staffId = (int) ($input['staffId'] ?? 0) ?: null;
$reason = mb_substr(trim($input['reason'] ?? ''), 0, 200);

if ($referenceCode === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ($isPost && $time === '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A booking, date' . ($isPost ? ', and time' : '') . ' are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $isCashier = ($_SESSION['user_role'] ?? '') === 'Cashier';

    // A Cashier can only touch their own branch's bookings.
    $appointment = Reschedule::load($pdo, $referenceCode, $isCashier ? (int) ($_SESSION['branch_id'] ?? 0) : null);
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }
    if ($blocked = Reschedule::blockedReason($appointment, false)) {
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

    if ($isCashier) {
        foreach ([substr($appointment['appointment_datetime'], 0, 10), $date] as $day) {
            if (EodLock::isDateLocked($pdo, (int) $appointment['branch_id'], $day)) {
                http_response_code(423);
                echo json_encode(['success' => false, 'message' => 'The business day ' . $day . ' is closed. Ask an administrator to reopen it to make changes.']);
                exit();
            }
        }
    }

    try {
        $result = Reschedule::apply($pdo, $appointment, $date, $time, false, $reason, $staffId);
    } catch (RuntimeException $e) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => "Booking moved to {$result['when']}" . ($result['stylist'] !== '' ? " with {$result['stylist']}" : '') . '.',
        'status' => $result['status'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('cashier rescheduleAppointment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while rescheduling.']);
}
