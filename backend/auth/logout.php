<?php
require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/url.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';

sendCorsHeaders();
AuditLog::captureRequest();

// config/setup/session.php already started the session with the real save
// path/cookie settings, so don't call session_start() again here.

// Signing out ends the session only. A staff member's shift is a separate
// action (Shift Out in the Staff Portal, backend/staff/shiftAction.php), so an
// active shift stays open and shows again after the next login.

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
// a full-page navigation, never a fetch() call so callers need a redirect
// back to the login page, not a JSON body.
header('Location: ' . getProjectBaseUrl() . '/frontend/auth/login.html');
exit();