<?php
require_once '../config/cors.php';
require_once '../config/url.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();

// config/session.php already started the session (with the project's real
// save path/cookie settings) -- a plain session_start() here previously read
// from PHP's default session store instead, so it never saw the real
// session data and this Time Out step silently never ran.

// Attendance time-out is tied to this real, reliable server-side action
// (every "Sign Out" link hits this script) rather than an unload/beforeunload
// event, which browsers can skip entirely (tab killed, laptop closed, crash).
if (($_SESSION['user_role'] ?? null) === 'Staff' && isset($_SESSION['user_id'])) {
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE user_id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $employeeId = $stmt->fetchColumn();
        if ($employeeId) {
            $pdo->prepare('
                UPDATE attendance SET clock_out_time = NOW()
                WHERE employee_id = ? AND DATE(clock_in_time) = CURDATE() AND clock_out_time IS NULL
            ')->execute([$employeeId]);
        }
    } catch (PDOException $e) {
        error_log('logout attendance time-out error: ' . $e->getMessage());
    }
}

// Wipe all session arrays
$_SESSION = array();

// Clear the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session data
session_destroy();

// Every "Sign Out" link in the app is a plain <a href="...logout.php">, i.e.
// a full-page navigation, never a fetch() call — so callers need a redirect
// back to the login page, not a JSON body.
header('Location: ' . getProjectBaseUrl() . '/pages/login/login.html');
exit();