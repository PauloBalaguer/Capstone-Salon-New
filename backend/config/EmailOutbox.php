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
        $exists = $pdo->prepare('SELECT id FROM email_outbox WHERE to_email = ? AND subject = ? AND body = ? AND reference_code <=> ? LIMIT 1 FOR UPDATE');
        $subject = mb_substr($subject, 0, 255);
        $exists->execute([$to, $subject, $body, $reference]);
        if ($exists->fetchColumn()) return;
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
        if ($pdo->inTransaction()) return 0;
        $locked = false;
        try {
            // Connection-scoped mutex also covers the SMTP send, so two workers
            // cannot deliver the same pending row. No schema change is needed.
            $locked = (int) $pdo->query("SELECT GET_LOCK(CONCAT('salon-mail-', DATABASE()), 0)")->fetchColumn() === 1;
            if (!$locked) return 0;
            $limit = max(1, min(500, $limit));
            $stmt = $pdo->prepare("SELECT id, to_email, subject, body, reference_code FROM email_outbox WHERE status = 'Pending' AND attempts < ? ORDER BY id LIMIT " . $limit);
            $stmt->execute([self::MAX_ATTEMPTS]);
            $groups = [];
            foreach ($stmt->fetchAll() as $row) {
                if ($row['reference_code']) {
                    $preference = $pdo->prepare('SELECT c.notify_email FROM customers c WHERE c.id IN (SELECT customer_id FROM appointments WHERE reference_code = ?) OR c.id IN (SELECT customer_id FROM home_service_requests WHERE reference_code = ?) LIMIT 1');
                    $preference->execute([$row['reference_code'],$row['reference_code']]);
                    $emailEnabled = $preference->fetchColumn();
                    if ($emailEnabled !== false && (int) $emailEnabled === 0) {
                        $pdo->prepare("UPDATE email_outbox SET status = 'Failed', last_error = 'Email notifications disabled by customer' WHERE id = ?")->execute([$row['id']]);
                        continue;
                    }
                }
                if (str_starts_with($row['subject'], 'Appointment reminder') && $row['reference_code']) {
                    $current = $pdo->prepare("SELECT appointment_datetime FROM appointments WHERE reference_code = ? AND status = 'Confirmed'");
                    $current->execute([$row['reference_code']]);
                    $when = $current->fetchColumn();
                    if (!$when || new DateTimeImmutable($when,new DateTimeZone('Asia/Manila')) <= new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')) || !str_contains($row['body'], 'Scheduled time: ' . substr($when, 0, 16))) {
                        $pdo->prepare("UPDATE email_outbox SET status = 'Failed', last_error = 'Reminder superseded by a schedule or status change' WHERE id = ?")->execute([$row['id']]);
                        continue;
                    }
                }
                $groups[strtolower($row['to_email'])][] = $row;
            }

            $sent = 0;
            foreach ($groups as $rows) {
                $ids = array_column($rows, 'id');
                $subject = count($rows) === 1 ? $rows[0]['subject'] : 'Updates on your Leo Mejillano booking';
                $body = implode("\n\n", array_column($rows, 'body'));
                $marks = implode(',', array_fill(0, count($ids), '?'));

                try { $delivered = sendNotificationEmail($rows[0]['to_email'], $subject, $body); }
                catch (Throwable $e) { error_log('Email delivery failed: ' . $e->getMessage()); $delivered = false; }
                if ($delivered) {
                    $pdo->prepare("UPDATE email_outbox SET status = 'Sent', attempts = attempts + 1, sent_at = NOW(), last_error = NULL WHERE id IN ($marks)")
                        ->execute($ids);
                    $sent++;
                } else {
                    $pdo->prepare("UPDATE email_outbox SET status = IF(attempts + 1 >= ?, 'Failed', 'Pending'),
                                   attempts = attempts + 1, last_error = 'SMTP send failed or is not configured (see PHP error log)' WHERE id IN ($marks)")
                        ->execute([self::MAX_ATTEMPTS, ...$ids]);
                }
            }
            return $sent;
        } catch (Throwable $e) {
            error_log('EmailOutbox flush error: ' . $e->getMessage());
            return 0;
        } finally {
            if ($locked) {
                try { $pdo->query("SELECT RELEASE_LOCK(CONCAT('salon-mail-', DATABASE()))"); }
                catch (Throwable $e) { error_log('Email worker lock release failed.'); }
            }
        }
    }
}
