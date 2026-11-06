<?php

/**
 * "Remember me" for customers: the browser keeps a random secret in an HttpOnly
 * cookie, so after signing out, typing the same email signs in without the
 * password. No password is stored anywhere. The device stops working after 30
 * days or when the password changes, and customers only: staff, cashier and
 * admin accounts are often on shared computers.
 *
 * Anyone using that browser who knows the email can sign in, like a saved
 * password. Trouble here never blocks a normal login, so failures are only logged.
 *
 * ponytail: one account per browser (a new Remember me replaces the old one),
 * add a device list when customers need several accounts in one browser.
 */
final class TrustedDevice
{
    private const COOKIE = 'LEO_DEVICE';
    private const DAYS = 30;

    /** $user needs id and password, as read from the users table. */
    public static function remember(PDO $pdo, array $user): void
    {
        try {
            self::forget($pdo);
            $pdo->exec('DELETE FROM trusted_devices WHERE expires_at < NOW()');
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO trusted_devices (user_id, token_hash, password_fingerprint, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? DAY)')
                ->execute([$user['id'], hash('sha256', $token), hash('sha256', $user['password']), self::DAYS]);
            self::setCookie($token, time() + self::DAYS * 86400);
        } catch (PDOException $e) {
            error_log('Trusted device not saved: ' . $e->getMessage());
        }
    }

    /** Forgets this browser, if it was remembered. */
    public static function forget(PDO $pdo): void
    {
        $token = self::cookieToken();
        if ($token === '') return;
        try {
            $pdo->prepare('DELETE FROM trusted_devices WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        } catch (PDOException $e) {
            error_log('Trusted device not removed: ' . $e->getMessage());
        }
        self::setCookie('', time() - 42000);
    }

    /** The customer this browser may sign in as with this email, or null. Says nothing about any other email. */
    public static function userFor(PDO $pdo, string $email): ?array
    {
        $token = self::cookieToken();
        if ($token === '' || $email === '') return null;
        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.email, u.password, u.branch_id, u.display_name, r.role_name, c.first_name, c.last_name, d.password_fingerprint
                FROM trusted_devices d
                JOIN users u ON u.id = d.user_id
                JOIN roles r ON r.id = u.role_id
                JOIN customers c ON c.user_id = u.id
                WHERE d.token_hash = ? AND d.expires_at > NOW() AND u.email = ? AND u.is_active = 1 AND r.role_name = 'Customer'
                LIMIT 1
            ");
            $stmt->execute([hash('sha256', $token), $email]);
            $user = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Trusted device not checked: ' . $e->getMessage());
            return null;
        }
        return $user && hash_equals($user['password_fingerprint'], hash('sha256', $user['password'])) ? $user : null;
    }

    private static function cookieToken(): string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        return is_string($token) ? $token : '';
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => session_get_cookie_params()['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
