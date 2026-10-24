<?php

/**
 * This is our central session management file. Its main job is to handle user sessions
 * securely and provide simple functions to check if a user is logged in or has
 * the correct permissions to view a page. We include this file at the top of any
 * page that needs to be protected.
 *
 * Example:
 * <?php
 * require_once '../../shared/backend/config/setup/session.php';
 * requireLogin(); // Ensures the user is logged in before viewing the page.
 * requireRole(['Admin']); // Ensures the user is an Admin.
 * ?>
 */

require_once __DIR__ . '/url.php';

/**
 * Each portal (admin / cashier / staff / customer) gets its own session
 * cookie, so logging into a second account in another tab of the same
 * browser no longer overwrites the first one. Previously there was a single
 * PHPSESSID shared by every page: log in as a Customer in one tab and the
 * Admin tab would be bounced back to the login page on its next refresh.
 *
 * The portal is worked out from the endpoint's own folder where that is
 * unambiguous, otherwise from the page that made the request (Referer),
 * e.g. logout.php called from admin/frontend/*.html.
 */
const SESSION_PORTALS = [
    'admin'    => 'LEO_SESS_ADMIN',
    'cashier'  => 'LEO_SESS_CASHIER',
    'staff'    => 'LEO_SESS_STAFF',
    'customer' => 'LEO_SESS_CUSTOMER',
    // Throwaway session used only while login.php authenticates; the user
    // is then moved into their role's portal session (see switchSessionPortal()).
    'login'    => 'LEO_SESS_LOGIN',
];

function getRolePortal(string $role): string
{
    $portals = ['Admin' => 'admin', 'Cashier' => 'cashier', 'Staff' => 'staff', 'Customer' => 'customer'];
    return $portals[$role] ?? 'customer';
}

function detectSessionPortal(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#/([^/]+)/backend/([^/]+)$#', $scriptName, $m)) {
        if ($m[1] === 'auth' && $m[2] === 'login.php') {
            return 'login';
        }
        $folders = [
            'admin' => 'admin', 'cashier' => 'cashier', 'customer' => 'customer',
            'staff' => 'staff', 'public' => 'customer',
        ];
        if (isset($folders[$m[1]])) {
            return $folders[$m[1]];
        }
    }

    // Shared endpoints (e.g. logout.php): explicit ?portal=, else the calling
    // page, e.g. admin/frontend/dashboard.html -> admin.
    $requested = $_GET['portal'] ?? '';
    if (is_string($requested) && $requested !== 'login' && isset(SESSION_PORTALS[$requested])) {
        return $requested;
    }
    $referer = (string) parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
    if (preg_match('#/(admin|cashier|staff|customer|public)/frontend/#', $referer, $m)) {
        return $m[1] === 'public' ? 'customer' : $m[1];
    }
    return 'customer';
}

// We configure session settings before starting it. This is a security best practice.

if (session_status() === PHP_SESSION_NONE) {
    // Keep development sessions inside the project so the PHP server does not
    // depend on write access to XAMPP's global C:\xampp\tmp directory.
    $sessionPath = dirname(__DIR__, 2) . '/storage/sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0770, true);
    }
    session_save_path($sessionPath);

    // Force cookies to be used for sessions, which is the default but good to be explicit.
    ini_set('session.use_cookies', '1');
    // Prevent session IDs from being passed in URLs.
    ini_set('session.use_only_cookies', '1');
    // Use strict mode. The server will only accept session IDs that it generated.
    // This helps prevent session fixation attacks.
    ini_set('session.use_strict_mode', '1');

    // "Remember me" on the login form (auth/frontend/login.html) extends the
    // session cookie to 30 days instead of expiring it when the browser
    // closes. Safe to read $_POST here for every other page too -- it's
    // just absent/falsy outside of that one login request.
    $rememberMe = !empty($_POST['remember']);

    session_set_cookie_params([
        'lifetime' => $rememberMe ? 60 * 60 * 24 * 30 : 0, // 30 days if "remember me", else until the browser closes.
        'path'     => '/', // Available for the entire domain.
        'domain'   => '',  // Set to your domain in production.
        'secure'   => false, // Should be `true` in production (HTTPS only).
        'httponly' => true, // Prevents JavaScript from accessing the cookie (XSS protection).
        'samesite' => 'Strict' // Prevents the browser from sending the cookie with cross-site requests (CSRF protection).
    ]);

    session_name(SESSION_PORTALS[detectSessionPortal()]);
    session_start();
}

/**
 * Called by login.php once the user's role is known: discards the temporary
 * login session and continues in that role's own portal session, leaving any
 * other portal (e.g. a Customer logged in in another tab) untouched.
 */
function switchSessionPortal(string $portal): void
{
    $_SESSION = [];
    session_destroy();
    $params = session_get_cookie_params();
    setcookie(SESSION_PORTALS['login'], '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);

    session_name(SESSION_PORTALS[$portal]);
    session_start();
    // Drop whatever account was previously logged into this portal.
    $_SESSION = [];
}



/**
 * This function is called right after a user logs in. It creates a new session ID
 * and deletes the old one. This is a crucial step to prevent session hijacking
 * or fixation attacks, as any old session ID an attacker might have stolen
 * becomes invalid.
 */
function regenerateSession(): void
{
    session_regenerate_id(true);
}

/**
 * A simple check to see if the user is logged in. It just looks for the 'user_id'
 * we store in the session after a successful login. It returns true or false.
 * This is useful for UI elements, like showing a "Login" or "Logout" button.
 *
 * @return bool True if the user is logged in, false otherwise.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * This function retrieves all the essential details of the currently logged-in user
 * from the session. We use this to display user-specific information, like their
 * name on a dashboard. It returns a structured array of the user's data.
 *
 * @return array An associative array of user details, or an empty array if not logged in.
 */
function getLoggedInUser(): array
{
    if (!isLoggedIn()) {
        return [];
    }

    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? 'Guest', // This is constructed from first/last name on login.
        'email' => $_SESSION['user_email'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
        'branch_id' => $_SESSION['branch_id'] ?? null, // For future use when branches are implemented.
    ];
}

/**
 * Each role lands on its own dashboard after logging in. This is shared by
 * login.php (redirect on successful login) and requireRole() below (redirect
 * when a logged-in user tries to access a page their role can't see).
 *
 * @param string $role One of the role_name values from the `roles` table.
 * @param string $from Where the caller is redirecting *from*, relative to the
 *                      project root: 'login' for auth/frontend/login.html, or
 *                      'root' for a root-relative absolute path (used by
 *                      requireRole(), which can be invoked from any page depth).
 */
function getRoleDashboardUrl(string $role, string $from = 'login'): string
{
    $dashboards = [
        'Customer' => 'customer/frontend/dashboard.html',
        'Admin'    => 'admin/frontend/dashboard.html',
        'Cashier'  => 'cashier/frontend/dashboard.html',
        'Staff'    => 'staff/frontend/dashboard.html',
    ];

    $path = $dashboards[$role] ?? $dashboards['Customer'];

    if ($from === 'root') {
        return getProjectBaseUrl() . '/' . $path;
    }

    // Relative to auth/frontend/login.html: go up to the project root.
    return '../../' . $path;
}


/**
 * This is a "gatekeeper" function. We place it at the top of pages that require a user
 * to be logged in. If they aren't, it immediately stops the script and sends them
 * back to the login page. This prevents any unauthorized access to protected content.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        // Redirect to the login page with an error message.
        header('Location: ' . getProjectBaseUrl() . '/auth/frontend/login.html?error=unauthenticated');
        exit();
    }
}

/**
 * This function is for role-based access control. For example, only an 'Admin'
 * can access the user management page. This function checks if the logged-in user's role
 * is in the list of allowed roles. If not, it redirects them to a safe page, like their
 * dashboard, with an "unauthorized" error.
 *
 * @param array $allowedRoles An array of role names (e.g., ['Admin']).
 */
function requireRole(array $allowedRoles): void
{
    requireLogin();

    $userRole = $_SESSION['user_role'] ?? null;

    if (!$userRole || !in_array($userRole, $allowedRoles, true)) {
        // The user is logged in but does not have the required permissions.
        // Redirect to their own dashboard with an error message.
        header('Location: ' . getRoleDashboardUrl($userRole ?? '', 'root') . '?error=unauthorized');
        exit();
    }
}
