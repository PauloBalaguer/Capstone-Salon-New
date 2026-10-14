<?php

/**
 * Customer: Reschedule My Home Service
 *
 * POST id=<reference>, date=<Y-m-d>, time=<HH:MM>
 * Moves the logged-in customer's own home service request to a new date and
 * time. Rules live in HomeServiceRequest::reschedule() (shared with the
 * guest Track page): at least RESCHEDULE_CUTOFF_HOURS ahead, at most
 * MAX_ONLINE_RESCHEDULES times, and the assigned team must be free. The
 * quote, reservation fee and team carry over.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/HomeServiceRequest.php';

date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to manage your requests.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$reference = trim($_POST['id'] ?? '');
$date = trim($_POST['date'] ?? '');
$time = trim($_POST['time'] ?? '');
if ($reference === '' || $date === '' || $time === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please choose a new date and time.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT ' . HomeServiceRequest::RESCHEDULE_COLUMNS . '
        FROM home_service_requests h JOIN customers c ON c.id = h.customer_id
        WHERE h.reference_code = ?
          AND (c.user_id = ? OR (h.contact_email IS NOT NULL AND LOWER(h.contact_email) = (SELECT LOWER(email) FROM users WHERE id = ?)))
        LIMIT 1');
    $stmt->execute([$reference, $_SESSION['user_id'], $_SESSION['user_id']]);
    $request = $stmt->fetch();
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Home service request not found.']);
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

    echo json_encode(['success' => true, 'message' => $message, 'date' => $date, 'time' => $time . ':00']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('customer rescheduleHomeService error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while rescheduling.']);
}
