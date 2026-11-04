<?php
/**
 * Runs one real backend endpoint in this CLI process against a test
 * database, the way Apache would run it for a browser request. Used by the
 * tests through run_endpoint() in harness.php -- not meant to be run by hand.
 *
 * Reads a JSON spec on stdin:
 *   db        test database name
 *   script    endpoint path from the project root, e.g. backend/customer/submitBooking.php
 *   method    'POST' (default) or 'GET'
 *   post/get  request fields
 *   session   $_SESSION contents (user_id, user_role, branch_id) -- stands in for a login
 *   env       extra settings, e.g. {"SALON_LATE_GRACE_MINUTES": "15"}
 *   paymongo  mock PayMongo API base URL; omitted = PayMongo not configured
 *   startAt   unix time (float) to wait for after connecting, so parallel runs start together
 * and prints the endpoint's own output (its JSON response).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

$spec = json_decode(stream_get_contents(STDIN), true);
$root = dirname(__DIR__, 2);

// No private .env: Brevo, textbee and PayMongo have no keys, so nothing is ever sent.
putenv('SALON_SECRETS_DIR=' . dirname(__DIR__) . DIRECTORY_SEPARATOR . 'no-secrets');
foreach ($spec['env'] ?? [] as $key => $value) putenv("{$key}={$value}");

$_SERVER['REQUEST_METHOD'] = $spec['method'] ?? 'POST';
$_SERVER['SCRIPT_NAME'] = '/Capstone-Salon/' . $spec['script'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_POST = $spec['post'] ?? [];
$_GET = $spec['get'] ?? [];
$_REQUEST = $_GET + $_POST;

// The "logged-in" session. Started here (no cookie, temp folder) so session.php leaves it alone.
ini_set('session.use_cookies', '0');
session_save_path(sys_get_temp_dir());
session_start();
$_SESSION = $spec['session'] ?? [];
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });

require_once $root . '/backend/shared/config/setup/database.php';
(new ReflectionProperty(Database::class, 'db_name'))->setValue(null, $spec['db']);
require_once $root . '/backend/shared/config/notifications/EmailOutbox.php';
// Queued emails stay in email_outbox instead of being sent after the request.
(new ReflectionProperty(EmailOutbox::class, 'hookRegistered'))->setValue(null, true);
if (!empty($spec['paymongo'])) {
    require_once $root . '/backend/shared/config/payments/PayMongo.php';
    (new ReflectionProperty(PayMongo::class, 'credentials'))->setValue(null, ['secret_key' => 'sk_test_mock', 'api_base' => $spec['paymongo']]);
}

Database::getInstance(); // connect before the start line, so parallel runs race on the booking itself
while (!empty($spec['startAt']) && microtime(true) < $spec['startAt']) usleep(200);

chdir(dirname($root . '/' . $spec['script'])); // endpoints include '../../backend/...' relative to their folder
require $root . '/' . $spec['script'];
