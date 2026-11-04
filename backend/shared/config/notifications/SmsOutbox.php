<?php

require_once __DIR__ . '/../setup/sms.php';

/**
 * Transactional SMS queue (textbee.dev), the SMS counterpart of EmailOutbox.
 *
 * queue() inserts into sms_outbox using the caller's PDO connection, so a
 * text about a booking change is committed or rolled back together with
 * that change. Nothing is sent during the request: the gateway is one
 * Android phone (~10-15 texts a minute), so backend/shared/cron/sendQueuedSms.php
 * calls flush() every few minutes to send a small, spaced-out batch.
 * flush() never sends between 9 PM and 7 AM, so non-urgent texts wait until
 * 7 AM. sendNow() is for verification codes and the admin test page: it
 * ignores quiet hours but still respects, and counts toward, the daily cap.
 */
final class SmsOutbox
{
    public const MAX_ATTEMPTS = 3;
    public const DAILY_LIMIT = 50; // textbee free plan: 50 SMS/day, 300/month.
    private const BATCH_SIZE = 10;
    private const QUIET_FROM = 21; // 9 PM
    private const QUIET_UNTIL = 7; // 7 AM

    public static function queue(PDO $pdo, string $phone, string $type, string $message, ?string $reference = null): void
    {
        $to = normalizePhPhone($phone);
        if ($to === null) {
            error_log("SMS not queued ({$type}): customer phone is not a valid Philippine mobile number.");
            return;
        }
        $exists = $pdo->prepare('SELECT id FROM sms_outbox WHERE to_phone = ? AND message = ? AND reference_code <=> ? LIMIT 1 FOR UPDATE');
        $exists->execute([$to, $message, $reference]);
        if ($exists->fetchColumn()) return;
        $pdo->prepare('INSERT INTO sms_outbox (to_phone, type, message, reference_code) VALUES (?, ?, ?, ?)')
            ->execute([$to, $type, $message, $reference]);
    }

    /** Sends up to BATCH_SIZE pending texts, about a second apart. Returns the number sent. */
    public static function flush(PDO $pdo): int
    {
        if ($pdo->inTransaction()) return 0;
        $hour = (int) self::now()->format('G');
        if ($hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL) return 0;
        $locked = false;
        try {
            // A batch can take minutes when textbee is slow; never let two cron runs overlap.
            $locked = (int) $pdo->query("SELECT GET_LOCK(CONCAT('salon-sms-', DATABASE()), 0)")->fetchColumn() === 1;
            if (!$locked) return 0;
            $room = min(self::BATCH_SIZE, self::DAILY_LIMIT - self::sentToday($pdo));
            if ($room <= 0) {
                error_log('SMS daily limit of ' . self::DAILY_LIMIT . ' reached; pending texts will be sent tomorrow.');
                return 0;
            }
            $stmt = $pdo->prepare("SELECT id, to_phone, type, message, reference_code FROM sms_outbox WHERE status = 'Pending' AND attempts < ? ORDER BY id LIMIT " . $room);
            $stmt->execute([self::MAX_ATTEMPTS]);

            $sent = 0;
            foreach ($stmt->fetchAll() as $i => $row) {
                if ($row['type'] === 'REMINDER' && !self::reminderStillCurrent($pdo, $row)) {
                    $pdo->prepare("UPDATE sms_outbox SET status = 'Failed', last_error = 'Reminder superseded by a schedule or status change' WHERE id = ?")->execute([$row['id']]);
                    continue;
                }
                if ($i > 0) sleep(1);
                if (sendSms($row['to_phone'], $row['message'])) {
                    $pdo->prepare("UPDATE sms_outbox SET status = 'Sent', attempts = attempts + 1, sent_at = ?, last_error = NULL WHERE id = ?")
                        ->execute([self::now()->format('Y-m-d H:i:s'), $row['id']]);
                    $sent++;
                } else {
                    $pdo->prepare("UPDATE sms_outbox SET status = IF(attempts + 1 >= ?, 'Failed', 'Pending'),
                                   attempts = attempts + 1, last_error = 'textbee send failed or is not configured (see PHP error log)' WHERE id = ?")
                        ->execute([self::MAX_ATTEMPTS, $row['id']]);
                }
            }
            return $sent;
        } catch (Throwable $e) {
            error_log('SmsOutbox flush error: ' . $e->getMessage());
            return 0;
        } finally {
            if ($locked) {
                try { $pdo->query("SELECT RELEASE_LOCK(CONCAT('salon-sms-', DATABASE()))"); }
                catch (Throwable $e) { error_log('SMS worker lock release failed.'); }
            }
        }
    }

    /**
     * Sends one text right away (verification codes, admin test) and records it
     * so it counts toward the daily cap. $loggedMessage replaces the stored text,
     * e.g. to keep codes out of sms_outbox. Never throws.
     */
    public static function sendNow(PDO $pdo, string $phone, string $type, string $message, ?string $loggedMessage = null): bool
    {
        try {
            $to = normalizePhPhone($phone);
            if ($to === null) {
                error_log("SMS not sent ({$type}): not a valid Philippine mobile number.");
                return false;
            }
            if (self::sentToday($pdo) >= self::DAILY_LIMIT) {
                error_log('SMS daily limit of ' . self::DAILY_LIMIT . " reached; {$type} text not sent.");
                return false;
            }
            $sent = sendSms($to, $message);
            $pdo->prepare('INSERT INTO sms_outbox (to_phone, type, message, status, attempts, last_error, sent_at) VALUES (?, ?, ?, ?, 1, ?, ?)')
                ->execute([$to, $type, $loggedMessage ?? $message, $sent ? 'Sent' : 'Failed',
                    $sent ? null : 'textbee send failed or is not configured (see PHP error log)', $sent ? self::now()->format('Y-m-d H:i:s') : null]);
            return $sent;
        } catch (Throwable $e) {
            error_log('SMS send failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function sentToday(PDO $pdo): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE status = 'Sent' AND sent_at >= ?");
        $stmt->execute([self::now()->format('Y-m-d 00:00:00')]);
        return (int) $stmt->fetchColumn();
    }

    /** A reminder held overnight is dropped if the appointment was cancelled, moved or already passed. */
    private static function reminderStillCurrent(PDO $pdo, array $row): bool
    {
        $stmt = $pdo->prepare("SELECT appointment_datetime FROM appointments WHERE reference_code = ? AND status = 'Confirmed'");
        $stmt->execute([$row['reference_code']]);
        $when = $stmt->fetchColumn();
        return $when && new DateTimeImmutable($when, new DateTimeZone('Asia/Manila')) > self::now()
            && str_contains($row['message'], formatSmsWhen($when));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    }
}
