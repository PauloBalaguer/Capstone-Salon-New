<?php

/**
 * Is this browser still signed in to a portal? For "Remember me": the login
 * page (function/auth/login.js) asks with ?portal=<the remembered portal> and
 * goes straight to that dashboard if so. Says nothing about who the user is.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';

sendCorsHeaders();
header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(isLoggedIn()
    ? ['loggedIn' => true, 'redirect' => getRoleDashboardUrl((string) ($_SESSION['user_role'] ?? ''))]
    : ['loggedIn' => false]);
