<?php
namespace BrevoMailTest;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

// Test-only namespace isolates all cURL calls. Never load private credentials.
// --queue is reserved for tests/run_isolated.php's disposable MySQL schema.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$calls = [];
$errors = [];
$status = 201;
$response = '{"messageId":"<mock@example.invalid>"}';
$errno = 0;
$throwTransport = false;
$closed = 0;
$checks = 0;

function curl_init($url) { return (object)['url' => $url, 'options' => []]; }
function curl_setopt_array($handle, $options) { $handle->options = $options; return true; }
function curl_exec($handle) {
    $GLOBALS['calls'][] = $handle;
    if ($GLOBALS['throwTransport']) throw new \RuntimeException('Sensitive transport details');
    return $GLOBALS['response'];
}
function curl_errno($handle) { return $GLOBALS['errno']; }
function curl_getinfo($handle, $option) { return $GLOBALS['status']; }
function curl_close($handle) { $GLOBALS['closed']++; }
function error_log($message) { $GLOBALS['errors'][] = $message; return true; }
function function_exists($name) {
    // Mocks also work when the isolated runner disables real network functions.
    return \function_exists(__NAMESPACE__ . '\\' . $name) || \function_exists($name);
}
function check($condition, $label): void {
    if (!$condition) throw new \RuntimeException('FAIL ' . $label);
    $GLOBALS['checks']++;
    echo "PASS $label\n";
}
function configure($value): void {
    file_put_contents($GLOBALS['configPath'], '<?php return ' . var_export($value, true) . ';');
}
function accepted(): void {
    $GLOBALS['status'] = 201;
    $GLOBALS['response'] = '{"messageId":"<mock@example.invalid>"}';
    $GLOBALS['errno'] = 0;
    $GLOBALS['throwTransport'] = false;
}
function lastPayload(): array {
    $call = $GLOBALS['calls'][array_key_last($GLOBALS['calls'])];
    return json_decode($call->options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR);
}

// In-memory fallback verifies queue transitions without MySQL or application data.
// Only MySQL syntax/functions are adapted; the production queue executes unchanged.
final class QueueFixture extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        $this->sqliteCreateFunction('IF', fn($condition, $yes, $no) => $condition ? $yes : $no);
        $this->sqliteCreateFunction('DATABASE', fn() => 'test_fixture');
        $this->sqliteCreateFunction('CONCAT', fn(...$args) => implode('', $args));
        $this->sqliteCreateFunction('GET_LOCK', fn(...$args) => 1);
        $this->sqliteCreateFunction('RELEASE_LOCK', fn(...$args) => 1);
        $this->exec("CREATE TABLE email_outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT, to_email TEXT, subject TEXT, body TEXT,
            reference_code TEXT, status TEXT DEFAULT 'Pending', attempts INTEGER DEFAULT 0,
            sent_at TEXT, last_error TEXT
        )");
        $this->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, notify_email INTEGER)');
        $this->exec('CREATE TABLE appointments (customer_id INTEGER, reference_code TEXT, appointment_datetime TEXT, status TEXT)');
        $this->exec('CREATE TABLE home_service_requests (customer_id INTEGER, reference_code TEXT)');
    }
    public function prepare(string $query, array $options = []): \PDOStatement|false {
        return parent::prepare(str_replace(
            ['<=>', ' FOR UPDATE', 'attempts + 1 >= ?'],
            ['IS', '', 'attempts + 1 >= CAST(? AS INTEGER)'],
            $query
        ), $options);
    }
}

$configPath = tempnam(sys_get_temp_dir(), 'brevo-test-');
if ($configPath === false) throw new \RuntimeException('Cannot create test fixture');
unlink($configPath); // First test exercises an absent configuration file.
$config = [
    'api_key' => 'TEST_PLACEHOLDER',
    'sender_email' => 'sender@example.invalid',
    'sender_name' => 'Leo Mejillano Salon & Beauty',
    'api_url' => 'https://api.brevo.com/v3/smtp/email',
];
try {
    $source = file_get_contents(__DIR__ . '/../backend/config/mail.php');
    $source = str_replace("__DIR__ . '/brevo_credentials.php'", var_export($configPath, true), $source, $replaced);
    check($replaced === 1, 'only test credentials can be loaded');
    eval('namespace ' . __NAMESPACE__ . '; use \Throwable; ' . substr($source, 5));

    check(!sendOtpEmail('recipient@example.invalid', '012345') && !$calls, 'missing configuration fails without a request');
    foreach ([null, [], array_merge($config, ['api_key' => '']), array_merge($config, ['api_key' => []]),
        array_merge($config, ['sender_email' => 'invalid']), array_merge($config, ['sender_name' => "Bad\r\nName"]),
        array_merge($config, ['api_key' => "Bad\r\nHeader"]), array_merge($config, ['sender_name' => ' ']),
        array_merge($config, ['api_url' => 'http://api.brevo.com/v3/smtp/email']),
        array_merge($config, ['api_url' => 'https://example.invalid/v3/smtp/email'])] as $index => $invalid) {
        configure($invalid);
        check(!sendOtpEmail('recipient@example.invalid', '012345') && !$calls, 'invalid configuration rejected ' . $index);
    }
    file_put_contents($configPath, '<?php return [;');
    check(!sendOtpEmail('recipient@example.invalid', '012345'), 'malformed configuration fails safely');
    configure($config);
    foreach (['', 'bad@', "recipient@example.invalid\r\nBcc:other@example.invalid"] as $invalid) {
        check(!sendOtpEmail($invalid, '012345') && !$calls, 'invalid recipient never reaches API');
    }

    $otpHelpers = [
        'sendOtpEmail' => 'Your verification code',
        'sendPasswordResetOtpEmail' => 'Your password reset code',
        'sendPasswordChangeOtpEmail' => 'Confirm your password change',
        'sendBookingOtpEmail' => 'Your booking verification code',
    ];
    foreach ($otpHelpers as $name => $subject) {
        $helper = __NAMESPACE__ . '\\' . $name;
        check($helper('recipient@example.invalid', '012345'), $name . ' accepts successful API response');
        $payload = lastPayload();
        check($payload['subject'] === $subject && str_contains($payload['textContent'], '012345')
            && str_contains($payload['htmlContent'], '012345') && str_contains($payload['textContent'], '10 minutes'),
            $name . ' preserves code, expiry copy and subject');
        $status = 401;
        check(!$helper('recipient@example.invalid', '012345'), $name . ' propagates rejection');
        accepted();
    }
    $options = $calls[0]->options;
    check($calls[0]->url === $config['api_url'] && $options[CURLOPT_POST] === true
        && in_array('api-key: TEST_PLACEHOLDER', $options[CURLOPT_HTTPHEADER], true)
        && in_array('Content-Type: application/json', $options[CURLOPT_HTTPHEADER], true),
        'POST JSON with API key header to the official endpoint');
    check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2
        && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS && $options[CURLOPT_FOLLOWLOCATION] === false
        && $options[CURLOPT_CONNECTTIMEOUT] === 5 && $options[CURLOPT_TIMEOUT] === 15,
        'HTTPS verification, no redirects, 5-second connect and 15-second request timeouts');
    check(lastPayload()['sender'] === ['email' => $config['sender_email'], 'name' => $config['sender_name']]
        && lastPayload()['to'] === [['email' => 'recipient@example.invalid']], 'sender and recipient JSON mapping');

    foreach (['Staff', 'Cashier'] as $role) {
        check(sendAccountSetupEmail('recipient@example.invalid', '<Test>', $role, 'https://example.invalid/setup?token=fixture&x=1'),
            $role . ' account setup accepted');
        $payload = lastPayload();
        check(str_contains($payload['textContent'], $role) && str_contains($payload['htmlContent'], '&lt;Test&gt;')
            && str_contains($payload['htmlContent'], '&amp;x=1') && str_contains($payload['textContent'], '24 hours'),
            $role . ' setup link, HTML escaping and expiry copy preserved');
    }
    check(sendAppointmentReminderEmail('recipient@example.invalid', 'Test', 'Hair', 'Test Branch', '2026-11-01 10:00:00', 'TEST-REF'),
        'direct appointment reminder accepted');
    check(str_contains(lastPayload()['textContent'], 'TEST-REF') && !isset(lastPayload()['htmlContent']),
        'direct reminder remains a text message');
    foreach (['Booking confirmation', 'Cancellation', 'Rescheduling', 'Payment received', 'Home service quote', 'Customer update'] as $subject) {
        check(sendNotificationEmail('recipient@example.invalid', $subject, "Test <details>\n\nPrice: ₱500"), $subject . ' accepted');
        $payload = lastPayload();
        check($payload['subject'] === $subject && $payload['textContent'] === "Test <details>\n\nPrice: ₱500"
            && str_contains($payload['htmlContent'], '&lt;details&gt;'), $subject . ' content and UTF-8 preserved');
    }

    foreach ([401 => 'invalid API key', 403 => 'forbidden', 400 => 'API validation failure',
        429 => 'rate limiting', 500 => 'server failure', 503 => 'service unavailable',
        302 => 'redirect', 200 => 'unexpected success status', 202 => 'unexpected asynchronous status'] as $code => $label) {
        $status = $code;
        $response = '{"code":"error","message":"TEST_PLACEHOLDER recipient@example.invalid 012345"}';
        check(!sendNotificationEmail('recipient@example.invalid', 'Test', 'Body'), $label . ' is not delivery');
    }
    accepted();
    foreach (['', 'not JSON', '{}', '{"messageId":""}', '{"messageId":123}', '{"messageIds":["mock"]}'] as $invalid) {
        $response = $invalid;
        check(!sendNotificationEmail('recipient@example.invalid', 'Test', 'Body'), '201 without valid messageId rejected');
    }
    accepted();
    $response = false;
    foreach ([28 => 'network timeout', 6 => 'DNS failure', 60 => 'TLS certificate failure'] as $code => $label) {
        $errno = $code;
        check(!sendNotificationEmail('recipient@example.invalid', 'Test', 'Body'), $label . ' fails safely');
    }
    accepted();
    $throwTransport = true;
    check(!sendNotificationEmail('recipient@example.invalid', 'Test', 'Body'), 'transport exception fails safely');
    accepted();
    $before = count($calls);
    check(!sendNotificationEmail('recipient@example.invalid', 'Test', "\xB1") && count($calls) === $before,
        'invalid UTF-8 fails before sending');
    check($closed === count($calls), 'all request handles closed after success and failure');
    check(!preg_match('/TEST_PLACEHOLDER|recipient@example|012345|Sensitive transport details/', implode("\n", $errors)),
        'diagnostics never disclose API key, recipient, OTP or raw response');

    if (in_array('--queue', $argv, true)) {
        require __DIR__ . '/../backend/config/database.php';
        $pdo = \Database::getInstance();
        if (!preg_match('/^leo_defense_test_[a-f0-9]{12}$/D', (string)$pdo->query('SELECT DATABASE()')->fetchColumn())) {
            throw new \RuntimeException('Queue tests require the disposable database runner');
        }
        $ddl = $pdo->query('SHOW CREATE TABLE email_outbox')->fetch(PDO::FETCH_NUM)[1];
        $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl));
    } else {
        $pdo = new QueueFixture();
    }
    $queueSource = file_get_contents(__DIR__ . '/../backend/config/EmailOutbox.php');
    $queueSource = preg_replace('/^require_once .*;\R/m', '', $queueSource);
    eval('namespace ' . __NAMESPACE__ . '; use \PDO; use \Throwable; use \DateTimeImmutable; use \DateTimeZone; ' . substr($queueSource, 5));
    // eval() declares the isolated class at runtime, so resolve its name dynamically.
    // Importing the production class here would bypass the mocked mail transport.
    $outboxClass = __NAMESPACE__ . '\\EmailOutbox';
    $hook = new \ReflectionProperty($outboxClass, 'hookRegistered');
    $hook->setAccessible(true);
    $hook->setValue(null, true); // No shutdown sends, including on test failure.
    foreach ([401 => 'unauthorized', 429 => 'rate limit', 500 => 'server failure', 0 => 'timeout'] as $code => $label) {
        $pdo->exec('DELETE FROM email_outbox'); // In-memory or connection-local temporary table only.
        $outboxClass::queue($pdo, 'recipient@example.invalid', 'Queue test', $label);
        $outboxClass::queue($pdo, 'recipient@example.invalid', 'Queue test', $label);
        check((int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 1, 'queue duplicate prevention: ' . $label);
        $status = $code; $errno = $code === 0 ? 28 : 0; $response = $code === 0 ? false : '{}';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            check($outboxClass::flush($pdo) === 0, 'queue rejects ' . $label . ' attempt ' . $attempt);
            $row = $pdo->query('SELECT * FROM email_outbox')->fetch();
            check((int)$row['attempts'] === $attempt && $row['status'] === ($attempt === 3 ? 'Failed' : 'Pending')
                && $row['sent_at'] === null, 'queue retry state: ' . $label . ' attempt ' . $attempt);
        }
        $before = count($calls);
        check($outboxClass::flush($pdo) === 0 && count($calls) === $before, 'exhausted retries are not resent: ' . $label);
    }
    $pdo->exec('DELETE FROM email_outbox');
    $outboxClass::queue($pdo, 'recipient@example.invalid', 'Recover', 'Recovery test');
    $status = 429; $errno = 0; $response = '{}';
    $outboxClass::flush($pdo);
    accepted();
    check($outboxClass::flush($pdo) === 1, 'pending retry recovers after API acceptance');
    $row = $pdo->query('SELECT * FROM email_outbox')->fetch();
    check($row['status'] === 'Sent' && (int)$row['attempts'] === 2 && $row['sent_at'] !== null && $row['last_error'] === null,
        'Sent is recorded only on API acceptance');
    $before = count($calls);
    check($outboxClass::flush($pdo) === 0 && count($calls) === $before, 'Sent rows are never retried');
    $pdo->exec('DELETE FROM email_outbox');
    $pdo->beginTransaction();
    $outboxClass::queue($pdo, 'recipient@example.invalid', 'Uncommitted', 'Rollback fixture');
    $before = count($calls);
    check($outboxClass::flush($pdo) === 0 && count($calls) === $before, 'uncommitted queue never sends');
    $pdo->rollBack();
    check((int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 0, 'queue insert rolls back with transaction');
    $outboxClass::queue($pdo, 'recipient@example.invalid', 'First update', 'First body');
    $outboxClass::queue($pdo, 'recipient@example.invalid', 'Second update', 'Second body');
    check($outboxClass::flush($pdo) === 1 && lastPayload()['textContent'] === "First body\n\nSecond body",
        'recipient batching preserved');
    if ($pdo instanceof QueueFixture) {
        $pdo->exec("INSERT INTO customers VALUES (1, 0)");
        $pdo->exec("INSERT INTO appointments VALUES (1, 'TEST-REF', '2030-01-01 10:00:00', 'Confirmed')");
        $outboxClass::queue($pdo, 'recipient@example.invalid', 'Preference test', 'Opted out', 'TEST-REF');
        $before = count($calls);
        check($outboxClass::flush($pdo) === 0 && count($calls) === $before, 'queued mail respects later email opt-out');
        $pdo->exec('UPDATE customers SET notify_email = 1');
        $outboxClass::queue($pdo, 'recipient@example.invalid', 'Appointment reminder (TEST-REF)', 'Scheduled time: 2029-01-01 10:00', 'TEST-REF');
        check($outboxClass::flush($pdo) === 0 && count($calls) === $before, 'superseded appointment reminder is suppressed');
    }
    echo "PASS $checks Brevo checks; no external network or private credentials used\n";
} finally {
    if (is_file($configPath)) unlink($configPath);
}
