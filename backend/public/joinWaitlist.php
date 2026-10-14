<?php

/**
 * Public: Join a stylist's waitlist
 *
 * "Notify me when this stylist is free" for a stylist who's fully booked at
 * the customer's chosen time. Works for guests (name + email required) and
 * logged-in customers (their account is linked, so the alert also shows in
 * their dashboard). When any of that stylist's appointments on that date is
 * cancelled / no-show / released, Waitlist::notifyOpening() emails everyone
 * waiting. Being notified doesn't hold the slot.
 *
 * POST: branch (id or branch_key), employeeId, date (Y-m-d), time (optional
 * label, e.g. "02:00 PM"), name, email, phone (optional).
 */

require_once '../config/cors.php';
require_once '../config/session.php';
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

$branch = trim($_POST['branch'] ?? '');
$employeeId = (int) ($_POST['employeeId'] ?? 0);
$date = trim($_POST['date'] ?? '');
$time = mb_substr(trim($_POST['time'] ?? ''), 0, 20);
$name = mb_substr(trim($_POST['name'] ?? ''), 0, 150);
$email = trim($_POST['email'] ?? '');
$phone = mb_substr(trim($_POST['phone'] ?? ''), 0, 30);

$limiter = new RateLimiter(RateLimiter::getIpAddress(), 'join_waitlist', 10, 3600);
if ($limiter->isExceeded()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many waitlist requests. Please try again later.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    // Logged-in customers are linked to their account; name/email default to their profile.
    $customerId = null;
    if (isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'Customer') {
        $stmt = $pdo->prepare('SELECT c.id, c.first_name, c.last_name, c.phone_number, u.email FROM customers c JOIN users u ON u.id = c.user_id WHERE c.user_id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        if ($customer = $stmt->fetch()) {
            $customerId = (int) $customer['id'];
            $name = $name !== '' ? $name : trim($customer['first_name'] . ' ' . $customer['last_name']);
            $email = $email !== '' ? $email : $customer['email'];
            $phone = $phone !== '' ? $phone : (string) $customer['phone_number'];
        }
    }

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $employeeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please choose a stylist and date, and enter your name and a valid email address.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT ? >= CURDATE()');
    $stmt->execute([$date]);
    if (!(int) $stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please choose today or a future date.']);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT e.id, e.branch_id, CONCAT(e.first_name, ' ', e.last_name) AS name
        FROM employees e JOIN branches b ON b.id = e.branch_id
        WHERE e.id = ? AND e.is_active = 1 AND (b.branch_key = ? OR b.id = ?)
        LIMIT 1
    ");
    $stmt->execute([$employeeId, $branch, ctype_digit($branch) ? (int) $branch : 0]);
    $stylist = $stmt->fetch();
    if (!$stylist) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'That stylist is not available at this branch.']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM stylist_waitlist WHERE employee_id = ? AND desired_date = ? AND email = ? AND status = 'Waiting' LIMIT 1");
    $stmt->execute([$employeeId, $date, $email]);
    if (!$stmt->fetchColumn()) {
        $pdo->prepare('
            INSERT INTO stylist_waitlist (employee_id, branch_id, desired_date, desired_time, customer_id, name, email, phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([$employeeId, $stylist['branch_id'], $date, $time ?: null, $customerId, $name, $email, $phone ?: null]);
        $limiter->record();
    }

    echo json_encode([
        'success' => true,
        'message' => "You're on the waitlist. We'll email {$email} if {$stylist['name']} has an opening on that date.",
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('joinWaitlist error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}
