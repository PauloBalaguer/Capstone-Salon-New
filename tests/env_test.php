<?php
/**
 * Self-check for backend/shared/config/setup/env.php and the three integrations
 * that read it. Offline: fake keys in a throwaway .env, no network, no database.
 *
 *   C:\xampp\php\php.exe tests\env_test.php
 *
 * Each scenario runs in its own PHP process because env() reads the file once.
 * Prints one line per check; exits 1 on any failure.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
$root = dirname(__DIR__);

// ---- Child: one scenario ----------------------------------------------------
if (isset($argv[1])) {
    $log = getenv('ENV_TEST_LOG');
    ini_set('error_log', $log);
    require_once $root . '/backend/shared/config/payments/PayMongo.php'; // Pulls in env.php, mail.php and sms.php.
    $failures = 0;
    $check = function (string $name, bool $ok) use (&$failures, $argv) { echo ($ok ? 'PASS ' : 'FAIL ') . "[{$argv[1]}] {$name}\n"; $failures += $ok ? 0 : 1; };

    if ($argv[1] === 'configured') {
        $check('value read from the private .env', env('PAYMONGO_SECRET_KEY') === 'sk_test_fromfile' && env('BREVO_SENDER_NAME') === 'Leo Mejillano Salon & Beauty');
        $check('server environment variable wins over the file', env('TEXTBEE_DEVICE_ID') === 'from-server');
        $check('unset key -> empty string or the default', env('NOT_A_SETTING') === '' && env('NOT_A_SETTING', 'x') === 'x');
        $check('file values are not copied into getenv / $_SERVER / $_ENV', getenv('PAYMONGO_SECRET_KEY') === false
            && !isset($_SERVER['PAYMONGO_SECRET_KEY']) && !isset($_ENV['PAYMONGO_SECRET_KEY']));
        $check('PayMongo detects its keys', PayMongo::isConfigured());
        $body = json_encode(['data' => ['attributes' => ['livemode' => false]]]);
        $sig = hash_hmac('sha256', '1700000000.' . $body, 'whsk_test_fromfile');
        $check('webhook signature verified with PAYMONGO_WEBHOOK_SECRET', PayMongo::verifyWebhookSignature($body, "t=1700000000,te={$sig},li=")
            && !PayMongo::verifyWebhookSignature($body, 't=1700000000,te=' . str_repeat('0', 64) . ',li='));
    } elseif ($argv[1] === 'missing') {
        $check('PayMongo switched off without keys', !PayMongo::isConfigured() && PayMongo::payUrls('tok', 'REF', 500, 'guest') === null
            && !PayMongo::verifyWebhookSignature('{}', 't=1,te=x,li=x'));
        $check('Brevo email refused without keys (no request sent)', sendBrevoEmail('guest@example.com', 'Subject', 'Body') === false);
        $check('SMS refused without keys (no request sent)', sendSms('09171234567', 'Hello') === false);
        $logged = (string) @file_get_contents($log);
        $check('missing settings logged generically', str_contains($logged, 'Brevo email not sent: sender configuration is incomplete.')
            && str_contains($logged, 'SMS not sent: API key or device ID is missing or invalid.'));
    } elseif ($argv[1] === 'broken') {
        $check('malformed .env -> settings empty, nothing crashes', env('PAYMONGO_SECRET_KEY') === '' && !PayMongo::isConfigured());
        $logged = (string) @file_get_contents($log);
        $check('syntax error logged without quoting the value', str_contains($logged, 'syntax error') && !str_contains($logged, 'LEAKME'));
    }
    exit($failures ? 1 : 0);
}

// ---- Parent: build each scenario's .env and run it -------------------------
if (!is_file($root . '/vendor/autoload.php')) exit("Run \"composer install\" first.\n");
$tmp = sys_get_temp_dir() . '/salon_env_test_' . bin2hex(random_bytes(4));
$scenarios = [
    'configured' => "PAYMONGO_SECRET_KEY=sk_test_fromfile\nPAYMONGO_WEBHOOK_SECRET=whsk_test_fromfile\nTEXTBEE_DEVICE_ID=from-file\n"
        . "BREVO_SENDER_NAME=\"Leo Mejillano Salon & Beauty\"\n",
    'missing' => null, // No .env at all.
    'broken' => "PAYMONGO_SECRET_KEY=LEAKME with spaces\n",
];
$failed = 0;
foreach ($scenarios as $name => $contents) {
    $dir = "{$tmp}/{$name}";
    mkdir($dir, 0700, true);
    if ($contents !== null) file_put_contents("{$dir}/.env", $contents);
    putenv("SALON_SECRETS_DIR={$dir}");
    putenv("ENV_TEST_LOG={$dir}/php_error.log");
    putenv($name === 'configured' ? 'TEXTBEE_DEVICE_ID=from-server' : 'TEXTBEE_DEVICE_ID');
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $name, $code);
    $failed += $code ? 1 : 0;
    @unlink("{$dir}/.env");
    @unlink("{$dir}/php_error.log");
    rmdir($dir);
}
rmdir($tmp);
echo $failed ? "\nSome checks failed.\n" : "\nAll checks passed.\n";
exit($failed ? 1 : 0);
