<?php
require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/RateLimiter.php';
require_once '../config/mail.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Limit: 5 registration attempts per IP address per hour.
$ip_address = RateLimiter::getIpAddress();
$register_limiter = new RateLimiter($ip_address, 'register', 5, 3600); // 5 attempts, 3600 seconds (1 hour)

if ($register_limiter->isExceeded()) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false,
        'message' => 'Too many registration attempts from this location. Please try again in an hour.'
    ]);
    exit();
}

$errors = [];
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$contact_number = trim($_POST['contact_number'] ?? ''); // Assuming 'contact_number' from the form
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($first_name)) $errors[] = 'First name is required.';
if (empty($last_name)) $errors[] = 'Last name is required.';

if (empty($email)) {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email format.';
}

if (empty($contact_number)) $errors[] = 'Contact number is required.';

if (empty($password)) {
    $errors[] = 'Password is required.';
} elseif (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}

if ($password !== $confirm_password) {
    $errors[] = 'Passwords do not match.';
}

if (!empty($errors)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors]);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone_number = ?");
    $stmt->execute([$contact_number]);
    if ($stmt->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['success' => false, 'message' => 'An account with this contact number already exists.']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = 'Customer' LIMIT 1");
    $stmt->execute();
    $role = $stmt->fetch();

    if (!$role) {
        throw new Exception("Default 'Customer' role not found in the database.");
    }
    $customer_role_id = $role['id'];

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Generate a 6-digit OTP that expires in 10 minutes. The account stays
    // inactive (is_active = 0) until the OTP is verified, so login.php can
    // gate access on it.
    $otp_code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_expires = new DateTime('now + 10 minutes');
    $otp_expiry = $otp_expires->format('Y-m-d H:i:s');

    $pdo->beginTransaction();

    $sql_user = "INSERT INTO users (role_id, email, password, is_active, otp_code, otp_expiry) VALUES (?, ?, ?, 0, ?, ?)";
    $stmt_user = $pdo->prepare($sql_user);
    $stmt_user->execute([$customer_role_id, $email, $hashed_password, $otp_code, $otp_expiry]);

    $new_user_id = $pdo->lastInsertId();

    $sql_customer = "INSERT INTO customers (user_id, first_name, last_name, phone_number) VALUES (?, ?, ?, ?)";
    $stmt_customer = $pdo->prepare($sql_customer);
    $stmt_customer->execute([$new_user_id, $first_name, $last_name, $contact_number]);

    $pdo->commit();
    $register_limiter->record();

    $email_sent = sendOtpEmail($email, $otp_code);

    $response = [
        'success' => true,
        'message' => 'Registration successful! Please check your email for a verification code.',
        'email' => $email,
    ];

    // Local demos intentionally fall back to logs when SMTP credentials are
    // absent. Surface that simulated code only to requests from this machine
    // so the verification flow remains usable without weakening production.
    if (!$email_sent && in_array($ip_address, ['127.0.0.1', '::1'], true)) {
        $response['verification_code'] = $otp_code;
        $response['message'] = 'Registration successful! Your local verification code is ' . $otp_code . '.';
    }

    http_response_code(201); // Created
    echo json_encode($response);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Registration Database Error: ' . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);

} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'A server configuration error occurred.']);
}

?>
