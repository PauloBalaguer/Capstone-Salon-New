<?php

/**
 * Live updates through Pusher Channels (optional).
 *
 * Turned on by four settings, copied from the Pusher dashboard (App Keys)
 * into Vercel's environment variables (or the private .env on XAMPP):
 *   PUSHER_APP_ID, PUSHER_KEY, PUSHER_SECRET, PUSHER_CLUSTER
 *
 * A page that saves something (a booking, payment, status change, staff
 * assignment, Shift In/Out...) calls LiveUpdates::afterThisRequest() once.
 * When the request ends, one small "changed" message goes to the public
 * channel "salon-live". Every open panel listens there
 * (function/shared/live-sync.js) and then asks getSyncVersion.php whether
 * its own branch really changed, and only then reloads its data.
 *
 * The message carries no customer data, only a timestamp, so a public
 * channel is safe: the real data still comes from each panel's own
 * endpoint with its own login and role checks.
 *
 * When the settings are missing, or Pusher can't be reached, nothing
 * happens and the panels keep their normal once-a-minute check, so saving
 * never fails because of Pusher.
 */

require_once __DIR__ . '/env.php';

final class LiveUpdates
{
    public const CHANNEL = 'salon-live';
    public const EVENT = 'changed';

    private static bool $queued = false;

    /** @return array{id: string, key: string, secret: string, cluster: string}|null */
    public static function credentials(): ?array
    {
        $id = env('PUSHER_APP_ID');
        $key = env('PUSHER_KEY');
        $secret = env('PUSHER_SECRET');
        $cluster = env('PUSHER_CLUSTER');
        if ($id === '' || $key === '' || $secret === '' || $cluster === '') return null;
        if (!preg_match('/^[a-z0-9-]{1,20}$/', $cluster)) return null;
        return ['id' => $id, 'key' => $key, 'secret' => $secret, 'cluster' => $cluster];
    }

    /** The public half (key + cluster) for the browser, or null when off. */
    public static function publicConfig(): ?array
    {
        $c = self::credentials();
        return $c === null ? null : ['key' => $c['key'], 'cluster' => $c['cluster']];
    }

    /**
     * Sends one "changed" message when this request ends, if it was a POST
     * that didn't fail with an HTTP error. Safe to call more than once.
     */
    public static function afterThisRequest(): void
    {
        if (self::$queued) return;
        self::$queued = true;
        register_shutdown_function(static function () {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
            $code = http_response_code();
            if (is_int($code) && $code >= 400) return;
            self::send();
        });
    }

    /** Sends the "changed" message now. Returns true when Pusher accepted it. */
    public static function send(array $data = []): bool
    {
        $c = self::credentials();
        if ($c === null || !function_exists('curl_init')) return false;

        try {
            $body = json_encode([
                'name' => self::EVENT,
                'channels' => [self::CHANNEL],
                'data' => json_encode(['at' => time()] + $data),
            ]);
            $path = '/apps/' . $c['id'] . '/events';
            $query = [
                'auth_key' => $c['key'],
                'auth_timestamp' => (string) time(),
                'auth_version' => '1.0',
                'body_md5' => md5($body),
            ];
            ksort($query);
            $queryString = http_build_query($query);
            $query['auth_signature'] = hash_hmac('sha256', "POST\n{$path}\n{$queryString}", $c['secret']);

            $ch = curl_init('https://api-' . $c['cluster'] . '.pusher.com' . $path . '?' . http_build_query($query));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($status !== 200) {
                error_log('Pusher live update not sent: ' . ($error !== '' ? $error : 'HTTP ' . $status));
                return false;
            }
            return true;
        } catch (Throwable $e) {
            error_log('Pusher live update not sent: ' . $e->getMessage());
            return false;
        }
    }
}
