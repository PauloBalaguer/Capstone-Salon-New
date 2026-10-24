<?php
/** Run every minute alongside the existing appointment reminder / email workers. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
require_once __DIR__ . '/../config/setup/database.php';
require_once __DIR__ . '/../config/payments/PayMongo.php';

function queuePaymentReminders(PDO $pdo): int
{
    if ($pdo->inTransaction()) throw new LogicException('Run payment reminders outside a transaction.');
    $hold = PayMongo::HOLD_MINUTES;
    $start = max(0, $hold - 10);
    $stmt = $pdo->prepare("SELECT id FROM appointments WHERE preferred_payment_method = ? AND deposit_paid = 0 AND status = 'Pending' AND paymongo_checkout_id IS NOT NULL AND created_at <= NOW() - INTERVAL $start MINUTE AND created_at > NOW() - INTERVAL $hold MINUTE ORDER BY id");
    $stmt->execute([PayMongo::METHOD]);
    $queued = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT customer_id, reference_code, DATE_FORMAT(DATE_ADD(created_at, INTERVAL $hold MINUTE), '%h:%i %p') AS deadline FROM appointments WHERE id = ? AND preferred_payment_method = ? AND deposit_paid = 0 AND status = 'Pending' AND created_at <= NOW() - INTERVAL $start MINUTE AND created_at > NOW() - INTERVAL $hold MINUTE FOR UPDATE");
            $stmt->execute([$id, PayMongo::METHOD]);
            $row = $stmt->fetch();
            if ($row) {
                // The same stable message is deduplicated by CustomerNotifier and EmailOutbox.
                $message = "Complete payment for booking {$row['reference_code']} before {$row['deadline']} (PH) to avoid automatic cancellation.";
                if (CustomerNotifier::notify($pdo, (int) $row['customer_id'], 'PAYMENT_REMINDER', $message, $row['reference_code'])) $queued++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Payment reminder not queued: ' . $e->getMessage());
        }
    }
    return $queued;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo 'Handled ' . queuePaymentReminders(Database::getInstance()) . " eligible payment reminder(s).\n";
}
