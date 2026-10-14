<?php

/**
 * This is the authentication endpoint for our system. When a user submits the
 * login form, the data is sent here. This script is responsible for:
 * 1. Validating the user's input (email and password).
 * 2. Securely querying the database to find a matching user.
 * 3. Verifying the provided password against the securely hashed password in the database.
 * 4. If authentication is successful, it creates a secure user session.
 * 5. It returns a JSON response to the frontend, indicating success (with a redirect URL)
 *    or failure (with an error message).
 */

// We need the session configuration to manage the user's login state and
// the database connection to query for user data.
require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/RateLimiter.php';
require_once '../config/Scheduling.php';

sendCorsHeaders();
AuditLog::captureRequest();

header('Content-Type: application/json');

// Ensure this script is only accessed via a POST request for security.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Limit: 5 failed login attempts per IP address within 1 minute.
$ip_address = RateLimiter::getIpAddress();
$login_limiter = new RateLimiter($ip_address, 'login_fail', 5, 60); // 5 attempts, 60 seconds (1 min)

if ($login_limiter->isExceeded()) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'success' => false,
        'message' => 'Too many failed login attempts. Please try again in 1 minute.'
    ]);
    exit();
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit();
}


try {
    $pdo = Database::getInstance();

    // This query is designed to get all necessary user info in one go.
    // It joins the users, roles, employees, and customers tables.
    // COALESCE is used to pick the name from whichever profile table (employee or customer) has a match.
    $sql = "
        SELECT
            u.id,
            u.email,
            u.password,
            u.branch_id,
            u.is_active,
            u.display_name,
            r.role_name,
            COALESCE(e.first_name, c.first_name) as first_name,
            COALESCE(e.last_name, c.last_name) as last_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN employees e ON u.id = e.user_id
        LEFT JOIN customers c ON u.id = c.user_id
        WHERE u.email = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Use password_verify to securely check the password against the stored hash.
    // If the user is not found OR the password does not match, send a generic error.
    // This prevents attackers from knowing whether an email address is registered.
    if (!$user || !password_verify($password, $user['password'])) {
        $login_limiter->record();

        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit();
    }

    // Block login until the account's email OTP has been verified
    // (see backend/auth/verifyOtp.php). Staff/admin-created accounts are
    // inserted with is_active = 1 by default, so this only affects
    // self-registered customers who haven't verified yet.
    if (!$user['is_active']) {
        http_response_code(403); // Forbidden
        echo json_encode([
            'success' => false,
            'message' => 'Please verify your email before logging in.',
            'needs_verification' => true,
            'email' => $user['email']
        ]);
        exit();
    }

    // On successful login, clear any previous failed attempts from this IP.
    $login_limiter->clear();

    // Move from the temporary login session into this role's own session
    // cookie, so other accounts logged in other tabs stay logged in.
    switchSessionPortal(getRolePortal($user['role_name']));

    // Regenerate the session ID to prevent session fixation attacks.
    regenerateSession();

    // Attendance is automatic, tied to the authenticated session itself
    // rather than a manual clock button, which staff could forget to press
    // or misuse. Time Out isn't tied to logout/browser-close (unreliable) --
    // Scheduling::autoCloseAttendance() settles any shift that's past the
    // branch's closing time first (e.g. a forgotten previous-day session),
    // then a new record is only started if today doesn't already have an
    // open one, so re-logging in the same day never creates duplicates.
    if ($user['role_name'] === 'Staff') {
        $stmt = $pdo->prepare('SELECT id, branch_id FROM employees WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $employeeRow = $stmt->fetch();
        if ($employeeRow) {
            $employeeId = (int) $employeeRow['id'];
            Scheduling::autoCloseAttendance($pdo, $employeeId, $employeeRow['branch_id']);

            $stmt = $pdo->prepare('SELECT id FROM attendance WHERE employee_id = ? AND DATE(clock_in_time) = CURDATE() AND clock_out_time IS NULL LIMIT 1');
            $stmt->execute([$employeeId]);
            if (!$stmt->fetchColumn()) {
                $pdo->prepare('INSERT INTO attendance (employee_id, clock_in_time) VALUES (?, NOW())')->execute([$employeeId]);
            }
        }
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role_name'];
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $_SESSION['user_name'] = $fullName !== '' ? $fullName : ($user['display_name'] ?? '');
    $_SESSION['branch_id'] = $user['branch_id'] !== null ? (int) $user['branch_id'] : null;

    // The frontend expects a redirect URL, relative to pages/login/login.html
    // (that's the page the browser is on when it follows this redirect).
    // Each role has its own dashboard, so route based on the session role.
    $redirect_url = getRoleDashboardUrl($user['role_name']);

    http_response_code(200); // OK
    echo json_encode([
        'success' => true,
        'message' => 'Login successful! Redirecting...',
        'redirect' => $redirect_url
    ]);

} catch (PDOException $e) {
    // Catch any database errors and return a generic server error message.
    http_response_code(500); // Internal Server Error
    // In production, you would log the detailed error: error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>