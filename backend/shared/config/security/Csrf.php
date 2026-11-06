<?php

/**
 * Per-session CSRF token for portal API calls.
 *
 * The session cookie is already SameSite=Strict (config/setup/session.php);
 * this adds a second check for state-changing requests. The token is handed
 * to the page by a GET endpoint (e.g. backend/staff/getDashboardData.php) and
 * sent back on every POST as the X-CSRF-Token header (or a csrfToken field).
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    /** The current session's token, created on first use. */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function isValid(): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrfToken'] ?? '');
        return is_string($expected) && $expected !== '' && is_string($sent) && hash_equals($expected, $sent);
    }

    /** Stops the request with 403 unless it carries this session's token. */
    public static function requireValid(): void
    {
        if (self::isValid()) {
            return;
        }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'csrfExpired' => true,
            'message' => 'Your session security check failed. Please refresh the page and try again.',
        ]);
        exit();
    }
}
