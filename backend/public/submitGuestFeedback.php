<?php

/**
 * Public: Submit Guest Feedback
 *
 * No login required. Lets a guest rate a 'Completed' salon appointment or
 * home & event service request from the "Track your booking" flow,
 * verified by reference code + the mobile number on file (same ownership
 * check as trackBooking.php). The `feedback` table only has one rating
 * column, so the optional stylist rating is folded into the comment text
 * rather than given its own column.
 */

require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/RateLimiter.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Limit: 10 feedback submissions per IP address per hour.
$ip_address = RateLimiter::getIpAddress();
$feedback_limiter = new RateLimiter($ip_address, 'submit_guest_feedback', 10, 3600);

if ($feedback_limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'You have submitted too many requests. Please wait an hour before trying again.'
    ]);
    exit();
}

$reference = strtoupper(trim($_POST['reference'] ?? ''));
$phone = trim($_POST['phone'] ?? '');
// Home services are verified with the request's email (see trackBooking.php).
$email = trim($_POST['email'] ?? '');
$rating = (int) ($_POST['rating'] ?? 0);
$staffRating = (int) ($_POST['staff_rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

if ($reference === '' || ($phone === '' && $email === '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A booking reference and mobile number (or email for home services) are required.']);
    exit();
}
if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5.']);
    exit();
}

$fullComment = $comment;
if ($staffRating >= 1 && $staffRating <= 5) {
    $fullComment = "Stylist rating: {$staffRating}/5." . ($comment !== '' ? " {$comment}" : '');
}

try {
    $feedback_limiter->record();

    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT a.id, a.customer_id FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        WHERE a.reference_code = ? AND c.phone_number = ? AND a.status = 'Completed'
        LIMIT 1
    ");
    $stmt->execute([$reference, $phone]);
    $appt = $stmt->fetch();

    if ($appt) {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO feedback (appointment_id, customer_id, rating, comments, is_public, created_at) VALUES (?, ?, ?, ?, 1, NOW())');
        $ins->execute([$appt['id'], $appt['customer_id'], $rating, $fullComment]);

        $pdo->prepare("UPDATE appointments SET status = 'Reviewed' WHERE id = ?")->execute([$appt['id']]);
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Thank you — your rating has been sent to the branch.']);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT h.id, h.customer_id FROM home_service_requests h
        JOIN customers c ON c.id = h.customer_id
        WHERE h.reference_code = ? AND h.status = 'Completed'
          AND ((? <> '' AND LOWER(h.contact_email) = LOWER(?)) OR (? <> '' AND c.phone_number = ?))
        LIMIT 1
    ");
    $stmt->execute([$reference, $email, $email, $phone, $phone]);
    $hs = $stmt->fetch();

    if ($hs) {
        $ins = $pdo->prepare('INSERT INTO feedback (home_service_request_id, customer_id, rating, comments, is_public, created_at) VALUES (?, ?, ?, ?, 1, NOW())');
        $ins->execute([$hs['id'], $hs['customer_id'], $rating, $fullComment]);

        echo json_encode(['success' => true, 'message' => 'Thank you — your rating has been sent to the branch.']);
        exit();
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No completed booking found for that reference and mobile number, or feedback was already submitted.']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Feedback has already been submitted for this booking.']);
        exit();
    }
    http_response_code(500);
    error_log('submitGuestFeedback error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving your feedback.']);
}
