<?php

/**
 * Submit Review API Endpoint
 *
 * Two modes, both scoped to the logged-in customer's own data:
 *  - create: leaves a first-time review on a 'Completed' appointment,
 *            then flips that appointment's status to 'Reviewed'.
 *  - edit:   updates an existing review's rating/comment.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to leave a review.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$mode = trim($_POST['mode'] ?? 'create');
$rating = (int) ($_POST['rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT id FROM customers WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $customerId = $stmt->fetchColumn();
    if (!$customerId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer profile not found.']);
        exit();
    }

    if ($mode === 'edit') {
        $reviewId = (int) ($_POST['reviewId'] ?? 0);
        $stmt = $pdo->prepare('SELECT id FROM feedback WHERE id = ? AND customer_id = ? LIMIT 1');
        $stmt->execute([$reviewId, $customerId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Review not found.']);
            exit();
        }

        $pdo->prepare('UPDATE feedback SET rating = ?, comments = ?, created_at = NOW() WHERE id = ?')
            ->execute([$rating, $comment, $reviewId]);

        $feedbackId = $reviewId;
        $message = 'Your review has been updated!';
    } else {
        $referenceCode = trim($_POST['appointmentId'] ?? '');
        $stmt = $pdo->prepare('
            SELECT a.id FROM appointments a
            JOIN customers c ON c.id = a.customer_id
            WHERE a.reference_code = ? AND c.user_id = ? AND a.status = "Completed"
            LIMIT 1
        ');
        $stmt->execute([$referenceCode, $userId]);
        $appointmentId = $stmt->fetchColumn();
        if (!$appointmentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "You can only review 'Completed' appointments."]);
            exit();
        }

        // One review per booking -- also enforced at the database level by
        // feedback.idx_appointment_id_unique, but checked here first so a
        // duplicate attempt gets a clear message instead of a generic error.
        $stmt = $pdo->prepare('SELECT id FROM feedback WHERE appointment_id = ? LIMIT 1');
        $stmt->execute([$appointmentId]);
        if ($stmt->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'You have already reviewed this booking.']);
            exit();
        }

        $ins = $pdo->prepare('INSERT INTO feedback (appointment_id, customer_id, rating, comments, is_public, created_at) VALUES (?, ?, ?, ?, 1, NOW())');
        $ins->execute([$appointmentId, $customerId, $rating, $comment]);
        $feedbackId = $pdo->lastInsertId();

        $pdo->prepare('UPDATE appointments SET status = "Reviewed" WHERE id = ?')->execute([$appointmentId]);

        $message = 'Thank you for your feedback!';
    }

    // Re-read the full, joined review so the frontend gets back exactly the
    // shape it renders (same as the reviews list in getDashboardData.php).
    $stmt = $pdo->prepare("
        SELECT
            f.id AS id,
            a.reference_code AS appointmentId,
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS serviceName,
            CONCAT(e.first_name, ' ', e.last_name) AS staffName,
            br.branch_name AS branchName,
            f.rating AS rating,
            f.comments AS comment,
            DATE_FORMAT(f.created_at, '%Y-%m-%d') AS date
        FROM feedback f
        JOIN appointments a ON a.id = f.appointment_id
        LEFT JOIN employees e ON e.id = a.employee_id
        LEFT JOIN branches br ON br.id = a.branch_id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services s ON s.id = aps.service_id
        WHERE f.id = ?
        GROUP BY f.id
    ");
    $stmt->execute([$feedbackId]);
    $review = $stmt->fetch();
    $review['id'] = (string) $review['id'];
    $review['rating'] = (int) $review['rating'];

    echo json_encode(['success' => true, 'message' => $message, 'review' => $review, 'mode' => $mode]);
} catch (PDOException $e) {
    // Unique constraint violation (feedback.idx_appointment_id_unique) means
    // two submissions for the same booking raced each other -- give the
    // same friendly message as the pre-check above instead of a generic error.
    if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'idx_appointment_id_unique')) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'You have already reviewed this booking.']);
        exit();
    }
    http_response_code(500);
    error_log('submitReview error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving your review.']);
}
