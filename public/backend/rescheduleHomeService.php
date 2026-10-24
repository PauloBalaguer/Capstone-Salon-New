<?php

/**
 * Public: Reschedule a Home Service (guests)
 *
 * POST reference, email, date=<Y-m-d>, time=<HH:MM>
 * Same proof as Track Your Home Service: the reference plus the email given
 * on the request (home_service_requests.contact_email). The confirmation is
 * emailed there too. Rules live in HomeServiceRequest::reschedule() (shared
 * with the customer account page).
 */

require_once '../../shared/backend/config/setup/cors.php';
require_once '../../shared/backend/config/setup/database.php';
require_once '../../shared/backend/config/security/AuditLog.php';
require_once '../../shared/backend/config/booking/HomeServiceRequest.php';

date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

require_once __DIR__ . '/../../shared/backend/config/booking/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'reference' => ['text', true, 'a booking reference'],
    'date' => ['date', true, 'an appointment date'],
    'time' => ['time', true, 'an appointment time'],
    'email' => ['email', true, 'an email address'],
]);

$reference = strtoupper(trim($_POST['reference'] ?? ''));
$email = trim($_POST['email'] ?? '');
$date = trim($_POST['date'] ?? '');
$time = trim($_POST['time'] ?? '');
if ($reference === '' || $email === '' || $date === '' || $time === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please choose a new date and time.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT ' . HomeServiceRequest::RESCHEDULE_COLUMNS . ', h.contact_email
        FROM home_service_requests h WHERE h.reference_code = ? LIMIT 1');
    $stmt->execute([$reference]);
    $request = $stmt->fetch();
    if (!$request || !$request['contact_email'] || strcasecmp($request['contact_email'], $email) !== 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No request found for that reference and email address.']);
        exit();
    }

    try {
        $pdo->beginTransaction();
        $message = HomeServiceRequest::reschedule($pdo, $request, $date, $time);
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }

    echo json_encode(['success' => true, 'message' => $message]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('public rescheduleHomeService error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while rescheduling.']);
}
