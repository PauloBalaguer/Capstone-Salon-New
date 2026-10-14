<?php

/**
 * Append-only log of online payment events (payment_transactions), so every
 * PayMongo checkout, payment, expiry, webhook delivery and deposit
 * forfeit/refund decision can be traced later from Admin -> Reports ->
 * Online Payment Log. Never throws: a logging failure must not break a
 * booking or a webhook response.
 */
final class PaymentLog
{
    public static function record(
        PDO $pdo,
        string $event,
        ?int $appointmentId = null,
        ?string $reference = null,
        ?string $externalId = null,
        ?float $amount = null,
        ?string $status = null,
        $details = null
    ): void {
        try {
            if ($details !== null && !is_string($details)) {
                $details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (is_string($details) && strlen($details) > 8000) {
                $details = substr($details, 0, 8000) . '…';
            }
            $pdo->prepare('
                INSERT INTO payment_transactions (appointment_id, reference_code, provider, event, external_id, amount, status, details)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$appointmentId, $reference, 'PayMongo', $event, $externalId, $amount, $status, $details]);
        } catch (Throwable $e) {
            error_log('PaymentLog error (' . $event . '): ' . $e->getMessage());
        }
    }
}
