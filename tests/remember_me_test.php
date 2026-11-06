<?php
/**
 * "Remember me": the login page asks backend/auth/currentSession.php whether
 * this browser is still signed in, and sessions last as long as the cookie.
 *
 * Reads nothing from the database and changes nothing, so no copy is needed.
 * Prints one line per check, exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$testDb = DEV_DATABASE; // endpoint.php connects, but this endpoint runs no queries
$ask = fn(array $session) => run_endpoint(['script' => 'backend/auth/currentSession.php', 'method' => 'GET', 'get' => ['portal' => 'staff'], 'session' => $session]);

check('not signed in -> stays on the login page', ($ask([])['loggedIn'] ?? true) === false && !isset($ask([])['redirect']));
$r = $ask(['user_id' => 1, 'user_role' => 'Staff', 'user_email' => 'private@example.test']);
check('signed in -> goes to that role\'s dashboard', ($r['loggedIn'] ?? false) === true && ($r['redirect'] ?? '') === '../../frontend/staff/dashboard.html');
check('the answer says nothing about who is signed in', array_keys($r) === ['loggedIn', 'redirect']);

$root = dirname(__DIR__);
check('sessions last as long as the 30-day cookie', str_contains(file_get_contents("{$root}/backend/shared/config/setup/session.php"), "ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30))"));
$loginJs = file_get_contents("{$root}/function/auth/login.js");
check('the page never keeps the email or password in its own storage', !preg_match('/(localStorage|sessionStorage)\.setItem\([^;]*(password|email)/i', $loginJs));
check('the page no longer asks the browser to save the login', !str_contains($loginJs, 'PasswordCredential'));

finish();
