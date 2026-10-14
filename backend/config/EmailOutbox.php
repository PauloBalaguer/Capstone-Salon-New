<?php

require_once __DIR__ . '/mail.php';

/**
 * Transactional email queue.
 *
 * queue() inserts into email_outbox using the caller's PDO connection, so an
 * email about a booking change is committed or rolled back together with
 * that change -- a booking that fails halfway never emails the customer.
 * The first queue() in a request registers a shutdown hook that sends
 * everything queued during the request once the script finishes; messages
 * to the same recipient are merged into one email so e.g. "payment
 * verified" + "confirmed" arrive together. Anything that fails stays
 * Pending (up to MAX_ATTEMPTS) for backend/cron/sendQueuedEmails.php.
 */
final class EmailOutbox
{
    public const MAX_ATTEMPTS = 3;

    private static ?PDO $pdo = null;
    private static bool $hookRegistered = false;

    public static function queue(PDO $pdo, string $to, string $subject, string $body, ?string $reference = null): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        $pdo->prepare('INSERT INTO email_outbox (to_email, subject, body, reference_code) VALUES (?, ?, ?, ?)')
            ->execute([$to, mb_substr($subject, 0, 255), $body, $reference]);

        self::$pdo = $pdo;
        if (!self::$hookRegistered) {
            self::$hookRegistered = true;
            register_shutdown_function([self::class, 'flushAfterRequest']);
        }
    }

    /** Shutdown hook: sends mail queued in this request (skipped if a transaction was left open, i.e. abandoned). */
    public static function flushAfterRequest(): void
    {
        if (!self::$pdo || self::$pdo->inTransaction()) return;
        ignore_user_abort(true);
        self::flush(self::$pdo, 25);
    }

    /** Sends up to $limit pending emails, merging messages per recipient. Returns the number of emails sent. */
    public static function flush(PDO $pdo, int $limit = 50): int
    {
        try {
            $stmt = $pdo->prepare("SELECT id, to_email, subject, body FROM email_outbox WHERE status = 'Pending' AND attempts < ? ORDER BY id LIMIT " . (int) $limit);
            $stmt->execute([self::MAX_ATTEMPTS]);
            $groups = [];
            foreach ($stmt->fetchAll() as $row) {
                $groups[strtolower($row['to_email'])][] = $row;
            }

            $sent = 0;
            foreach ($groups as $rows) {
                $ids = array_column($rows, 'id');
                $subject = count($rows) === 1 ? $rows[0]['subject'] : 'Updates on your Leo Mejillano booking';
                $body = implode("\n\n", array_column($rows, 'body'));
                $marks = implode(',', array_fill(0, count($ids), '?'));

                if (sendNotificationEmail($rows[0]['to_email'], $subject, $body)) {
                    $pdo->prepare("UPDATE email_outbox SET status = 'Sent', attempts = attempts + 1, sent_at = NOW(), last_error = NULL WHERE id IN ($marks)")
                        ->execute($ids);
                    $sent++;
                } else {
                    $pdo->prepare("UPDATE email_outbox SET attempts = attempts + 1, last_error = 'SMTP send failed (see PHP error log)',
                                   status = IF(attempts + 1 >= ?, 'Failed', 'Pending') WHERE id IN ($marks)")
                        ->execute([self::MAX_ATTEMPTS, ...$ids]);
                }
            }
            return $sent;
        } catch (Throwable $e) {
            error_log('EmailOutbox flush error: ' . $e->getMessage());
            return 0;
        }
    }
}
