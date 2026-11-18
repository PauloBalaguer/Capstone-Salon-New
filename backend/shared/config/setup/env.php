<?php

/**
 * Private settings (PayMongo, Brevo and textbee keys) from the environment.
 *
 * Real values live in a .env file OUTSIDE the web root, read once with
 * vlucas/phpdotenv: SALON_SECRETS_DIR/.env, or by default a salon-private
 * folder next to the web root (next to public_html on a host, or next to
 * htdocs: C:\xampp\salon-private\.env on XAMPP). Variables the server already sets (hosting panel, Apache SetEnv, system environment) win
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
    if ($dir !== '') return $dir;

    // Hosted in the web root itself: the folder beside it (a host won't let us write any higher).
    $besideProject = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'salon-private';
    if (is_file($besideProject . DIRECTORY_SEPARATOR . '.env')) return $besideProject;

    return dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . 'salon-private'; // XAMPP: the project sits inside htdocs.
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
    // Local XAMPP runs on the built-in defaults, so these aren't counted as missing either.
    foreach (['DB_HOST', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD', 'APP_URL'] as $key) {
        echo str_pad($key, 26) . (env($key) !== '' ? 'set' : 'not set (local default; required on a live server)') . "\n";
    }
    // Optional salon rule (see .env.example): hidden while unset, not counted as missing.
    echo str_pad('SALON_LATE_GRACE_MINUTES', 26) . (env('SALON_LATE_GRACE_MINUTES') !== '' ? 'set' : 'not set (late-arrival rule hidden)') . "\n";
    // Optional: Cloudinary storage for photos (see setup/cloudinary.php).
    foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $key) {
        echo str_pad($key, 26) . (env($key) !== '' ? 'set' : 'not set (photos stay on this server)') . "\n";
    }
    // Optional: Pusher live updates (see setup/live-updates.php).
    foreach (['PUSHER_APP_ID', 'PUSHER_KEY', 'PUSHER_SECRET', 'PUSHER_CLUSTER'] as $key) {
        echo str_pad($key, 26) . (env($key) !== '' ? 'set' : 'not set (panels check once a minute)') . "\n";
    }
    echo $missing ? "\n{$missing} setting(s) missing; those features stay switched off.\n" : "\nAll settings present.\n";
    exit($missing ? 1 : 0);
}
