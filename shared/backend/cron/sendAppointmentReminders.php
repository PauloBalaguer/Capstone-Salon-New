<?php
/** CLI: queue reminders for confirmed appointments in the next 24 hours.
 * reminder_sent means queued/handled; Brevo API retries belong to EmailOutbox.
 * No SMS provider is configured, so this job never sends SMS.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/CustomerNotifier.php';
require_once __DIR__ . '/sendPaymentReminders.php';

function sendAppointmentReminderSms(string $phone, string $message): bool { return false; }

function queueAppointmentReminders(PDO $pdo): int
{
    if ($pdo->inTransaction()) throw new LogicException('Run the reminder job outside a transaction.');
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $window = [$now->format('Y-m-d H:i:s'), $now->modify('+24 hours')->format('Y-m-d H:i:s')];
    $stmt = $pdo->prepare("SELECT id FROM appointments WHERE status = 'Confirmed' AND reminder_sent = 0 AND appointment_datetime BETWEEN ? AND ? ORDER BY id");
    $stmt->execute($window); $ids = $stmt->fetchAll(PDO::FETCH_COLUMN); $queued = 0;
    foreach ($ids as $id) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT id, reference_code, customer_id FROM appointments WHERE id = ? AND status = 'Confirmed' AND reminder_sent = 0 AND appointment_datetime BETWEEN ? AND ? FOR UPDATE");
            $stmt->execute([$id, ...$window]); $row = $stmt->fetch();
            if (!$row) { $pdo->commit(); continue; }
            if (!CustomerNotifier::notify($pdo, (int) $row['customer_id'], 'REMINDER', 'Reminder: your confirmed appointment is coming up within 24 hours.', $row['reference_code'])) throw new RuntimeException('Reminder could not be queued.');
            $pdo->prepare('UPDATE appointments SET reminder_sent = 1 WHERE id = ?')->execute([$id]);
            $pdo->commit(); $queued++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Appointment reminder not queued: ' . $e->getMessage());
        }
    }
    return $queued;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    queuePaymentReminders(Database::getInstance());
    echo 'Queued/handled ' . queueAppointmentReminders(Database::getInstance()) . " appointment reminder(s).\n";
}
