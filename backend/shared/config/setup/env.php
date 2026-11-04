<?php

/**
 * Private settings (PayMongo, Brevo and textbee keys) from the environment.
 *
 * Real values live in a .env file OUTSIDE the web root, read once with
 * vlucas/phpdotenv: SALON_SECRETS_DIR/.env, or by default a salon-private
 * folder next to htdocs (C:\xampp\salon-private\.env on XAMPP). Variables the
 * server already sets (hosting panel, Apache SetEnv, system environment) win
 * over the file. The file is parsed into a private array and never copied into
 * $_SERVER / $_ENV / putenv(), so phpinfo() and request dumps can't show it.
 * A missing value comes back as '' and that integration switches itself off.
 *
 * Check the setup without printing any value:
 *   C:\xampp\php\php.exe backend\shared\config\setup\env.php
 */

/** Folder holding the private .env. */
function envSecretsDir(): string
{
    $dir = getenv('SALON_SECRETS_DIR') ?: ($_SERVER['SALON_SECRETS_DIR'] ?? '');
    return $dir !== '' ? $dir : dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . 'salon-private';
}

/** The setting named $key, or $default when it is unset or empty. */
function env(string $key, string $default = ''): string
{
    static $file = null;
    $server = getenv($key);
    if ($server === false) $server = $_SERVER[$key] ?? $_ENV[$key] ?? '';
    if (is_string($server) && $server !== '') return $server;

    if ($file === null) {
        $file = [];
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            error_log('Environment not loaded: run "composer install" in the project folder.');
        } else {
            require_once $autoload;
            try {
                $file = Dotenv\Dotenv::createArrayBacked(envSecretsDir())->safeLoad();
            } catch (Throwable $e) {
                // The parser's message can quote the offending value, so never log it.
                error_log('Environment not loaded: the private .env file has a syntax error.');
            }
        }
    }
    return (string) ($file[$key] ?? '') !== '' ? (string) $file[$key] : $default;
}

// Error details stay out of web responses unless APP_DEBUG=true; they still reach the PHP error log.
if (PHP_SAPI !== 'cli') ini_set('display_errors', env('APP_DEBUG') === 'true' ? '1' : '0');

// Setup check: prints set / MISSING per key, never a value. Exits 1 if anything required is missing.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $path = envSecretsDir() . DIRECTORY_SEPARATOR . '.env';
    echo 'Composer packages: ' . (is_file(dirname(__DIR__, 4) . '/vendor/autoload.php') ? 'installed' : 'MISSING (run composer install)') . "\n";
    echo "Private file: {$path} (" . (is_file($path) ? 'found' : 'not found') . ")\n\n";
    $missing = 0;
    foreach (['PAYMONGO_SECRET_KEY', 'PAYMONGO_WEBHOOK_SECRET', 'BREVO_API_KEY', 'BREVO_SENDER_EMAIL', 'TEXTBEE_API_KEY', 'TEXTBEE_DEVICE_ID'] as $key) {
        $set = env($key) !== '';
        $missing += $set ? 0 : 1;
        echo str_pad($key, 26) . ($set ? 'set' : 'MISSING') . "\n";
    }
    // Optional salon rule (see .env.example): hidden while unset, not counted as missing.
    echo str_pad('SALON_LATE_GRACE_MINUTES', 26) . (env('SALON_LATE_GRACE_MINUTES') !== '' ? 'set' : 'not set (late-arrival rule hidden)') . "\n";
    echo $missing ? "\n{$missing} setting(s) missing; those features stay switched off.\n" : "\nAll settings present.\n";
    exit($missing ? 1 : 0);
}
