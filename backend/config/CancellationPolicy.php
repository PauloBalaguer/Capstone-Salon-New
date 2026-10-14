<?php

require_once __DIR__ . '/CustomerNotifier.php';
require_once __DIR__ . '/PaymentLog.php';
require_once __DIR__ . '/Waitlist.php';
require_once __DIR__ . '/PayMongo.php';

/**
 * Reservation deposit policy (shown to customers in the booking Terms, the
 * booking confirmation, and the cancel dialog): deposits are NON-REFUNDABLE.
 *
 *   - Need a different time? Reschedule instead (backend/config/Reschedule.php):
 *     the booking keeps its deposit. Customers can do it online up to
 *     FREE_CANCEL_HOURS before the appointment; the salon can anytime.
 *   - Cancelling (by the customer or the salon) or a no-show keeps the
 *     deposit -> payment_status 'Forfeited'. Even then, the cancelled
 *     booking can still be rescheduled later, which reinstates it with the
 *     deposit carried over -- so the money is never simply lost.
 *
 * Only money the salon actually holds is affected: a deposit staff verified
 * (Down Payment Verified / Fully Paid), or a PayMongo payment (recorded by
 * PayMongo itself). A self-reported Cash deposit that was never paid has
 * nothing to keep, so its payment status is left alone.
 *
 * Hours are measured in MySQL (NOW() vs appointment_datetime) because PHP's
 * configured timezone doesn't necessarily match the salon's local time.
 */
final class CancellationPolicy
{
    /** Online-reschedule cut-off for customers (see Reschedule::CUSTOMER_CUTOFF_HOURS). */
    public const FREE_CANCEL_HOURS = 24;

    public const BY_CUSTOMER = 'customer';
    public const BY_SALON = 'salon';
    public const NO_SHOW = 'no_show';

    /** Columns apply()/preview() need from the appointments row. */
    public const COLUMNS = 'id, customer_id, reference_code, employee_id, appointment_datetime, status, payment_status, deposit_paid, deposit_method, deposit_amount';

    /** One-paragraph policy summary included in booking emails. */
    public static function summary(): string
    {
        $hours = self::FREE_CANCEL_HOURS;
        return "Deposit policy: reservation deposits are non-refundable. Need a different time? Reschedule instead — from My Appointments "
            . "at least {$hours} hours before your appointment, or by contacting the branch — and your deposit carries over. "
            . "If you cancel or miss your appointment, the deposit is kept, but the booking can still be rescheduled later using that deposit.";
    }

    /** "Booking received" message (in-app + email) with the booking details, payment instructions, and the policy. */
    public static function bookingReceivedMessage(string $reference, array $serviceNames, string $branchName, string $appointmentDateTime,
        float $amountDue, float $balance, bool $payOnline): string
    {
        $when = date('l, F j, Y \a\t g:i A', strtotime($appointmentDateTime));
        $message = "Your booking request {$reference} has been received: " . implode(', ', $serviceNames) . " at {$branchName} on {$when}. "
            . 'Status: Waiting for Confirmation.';
        if ($amountDue > 0) {
            $message .= "\n\nPay now: ₱" . number_format($amountDue, 2)
                . ($payOnline ? ' online via PayMongo (complete it within ' . PayMongo::HOLD_MINUTES . ' minutes to keep your slot).' : ' in cash at the branch.')
                . ($balance > 0 ? ' Balance to pay at the salon: ₱' . number_format($balance, 2) . '.' : '');
        }
        return $message . "\n\n" . self::summary();
    }

    public static function holdsDeposit(array $appointment): bool
    {
        if (in_array($appointment['payment_status'], ['Down Payment Verified', 'Fully Paid'], true)) return true;
        return $appointment['deposit_method'] === 'PayMongo' && (int) $appointment['deposit_paid'] === 1
            && $appointment['payment_status'] === 'Awaiting Verification';
    }

    public static function hoursUntil(PDO $pdo, array $appointment): float
    {
        $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, NOW(), ?) / 60');
        $stmt->execute([$appointment['appointment_datetime']]);
        return (float) $stmt->fetchColumn();
    }

    /** The payment status a cancellation would produce, or null when the deposit isn't affected. Deposits are non-refundable. */
    public static function outcome(PDO $pdo, array $appointment, string $trigger): ?string
    {
        return self::holdsDeposit($appointment) ? 'Forfeited' : null;
    }

    /**
     * Applies the policy after the appointment's status was changed to
     * Cancelled/No-Show: updates the payment status, logs it, notifies the
     * customer, and alerts anyone waitlisted for the freed stylist. Returns a
     * customer-facing sentence about the deposit ('' when unaffected).
     */
    public static function apply(PDO $pdo, array $appointment, string $trigger): string
    {
        $pdo->prepare('UPDATE appointments SET cancelled_at = COALESCE(cancelled_at, NOW()) WHERE id = ?')
            ->execute([$appointment['id']]);

        if ($appointment['employee_id']) {
            Waitlist::notifyOpening($pdo, (int) $appointment['employee_id'], $appointment['appointment_datetime']);
        }

        $outcome = self::outcome($pdo, $appointment, $trigger);
        if ($outcome === null) return '';

        $pdo->prepare('UPDATE appointments SET payment_status = ? WHERE id = ?')->execute([$outcome, $appointment['id']]);
        $amount = (float) $appointment['deposit_amount'];
        $peso = '₱' . number_format($amount, 2);
        $reference = $appointment['reference_code'];

        PaymentLog::record($pdo, 'deposit_forfeited', (int) $appointment['id'], $reference,
            null, $amount, $outcome, ['trigger' => $trigger, 'method' => $appointment['deposit_method']]);

        $reason = match ($trigger) {
            self::NO_SHOW => 'the appointment was missed (no-show)',
            self::BY_SALON => 'the booking was cancelled by the salon',
            default => 'the booking was cancelled',
        };
        CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'PAYMENT_FORFEITED',
            "Your {$peso} reservation deposit for {$reference} is non-refundable and was kept because {$reason}. "
            . 'You can still reschedule this booking — from My Appointments or by contacting the branch — and the deposit will carry over.');
        return "Your {$peso} deposit is non-refundable, but you can still reschedule this booking and use it.";
    }
}
