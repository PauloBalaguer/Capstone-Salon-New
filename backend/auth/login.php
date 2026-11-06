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
require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/security/RateLimiter.php';
require_once '../../backend/shared/config/security/GuestAccountClaim.php';
require_once '../../backend/shared/config/security/TrustedDevice.php';
require_once '../../backend/shared/config/booking/Scheduling.php';

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
        // Email done, but they left before the texted code: back to that step.
        $claim = GuestAccountClaim::forUser($pdo, (int) $user['id']);
        if ($claim && (int) $claim['email_verified']) {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Please confirm your mobile number to finish signing up.', 'email' => $user['email']]
                + GuestAccountClaim::startPhoneStep($pdo, $claim));
            exit();
        }

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

    signInUser($user);

    // "Remember me" recognises a customer's browser after they sign out.
    if ($user['role_name'] === 'Customer') {
        if (!empty($_POST['remember'])) {
            TrustedDevice::remember($pdo, $user);
        } else {
            TrustedDevice::forget($pdo);
        }
    }

    // Staff attendance is manual: the Staff Portal's Shift In button starts
    // a shift (backend/staff/shiftAction.php). Logging in only settles a
    // shift left open past the branch's closing time (e.g. a forgotten
    // Shift Out yesterday); a shift that is still valid stays open, so the
    // portal shows On Shift again after logging back in.
    if ($user['role_name'] === 'Staff') {
        $stmt = $pdo->prepare('SELECT id, branch_id FROM employees WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $employeeRow = $stmt->fetch();
        if ($employeeRow) {
            Scheduling::autoCloseAttendance($pdo, (int) $employeeRow['id'], $employeeRow['branch_id']);
        }
    }

    // The frontend expects a redirect URL, relative to frontend/auth/login.html
    // (that's the page the browser is on when it follows this redirect).
    // Each role has its own dashboard, so route based on the session role.
    $redirect_url = getRoleDashboardUrl($user['role_name']);

    http_response_code(200); // OK
    echo json_encode([
        'success' => true,
        'message' => 'Login successful! Redirecting...',
        'redirect' => $redirect_url,
        'portal' => getRolePortal($user['role_name'])
    ]);

} catch (PDOException $e) {
    // Catch any database errors and return a generic server error message.
    http_response_code(500); // Internal Server Error
    // In production, you would log the detailed error: error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>