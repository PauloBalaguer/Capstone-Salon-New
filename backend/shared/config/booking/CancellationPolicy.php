<?php

require_once __DIR__ . '/../notifications/CustomerNotifier.php';
require_once __DIR__ . '/../payments/PaymentLog.php';
require_once __DIR__ . '/Waitlist.php';
require_once __DIR__ . '/../payments/PayMongo.php';
require_once __DIR__ . '/../security/AuditLog.php';

/**
 * Reservation deposit policy (shown to customers in the booking Terms, the
 * booking confirmation, and the cancel dialog): deposits are NON-REFUNDABLE.
 *
 * Exception: when the SALON cancels (e.g. the stylist is absent), staff
 * must choose (admin and cashier, staffCancelError()/closeByStaff()): refund
 * the deposit (trigger SALON_REFUND -> 'Refund Due', refunded in Admin >
 * Bookings through PayMongo's refund API or recorded as a manual refund) or
 * reschedule the booking ('Reschedule Requested', which the customer can
 * move without the 24-hour cut-off; deposit carried over). The cashier can
 * also record that the CUSTOMER asked to cancel (deposit kept).
 *
 *   - Need a different time? Reschedule instead (backend/shared/config/booking/Reschedule.php):
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
    /** Salon cancellation where the admin chose to refund the deposit. */
    public const SALON_REFUND = 'salon_refund';
    public const NO_SHOW = 'no_show';

    /** Columns apply()/preview() need from the appointments row. */
    public const COLUMNS = 'id, customer_id, reference_code, employee_id, appointment_datetime, status, payment_status, deposit_paid, deposit_method, deposit_amount';

    /** One-paragraph policy summary included in booking emails. */
    public static function summary(): string
    {
        $hours = self::FREE_CANCEL_HOURS;
        return "Deposit policy: deposits are non-refundable, but they carry over if you reschedule at least {$hours} hours before, or through the branch. "
            . "If you cancel or miss your appointment, you can still reschedule later using the deposit. "
            . "If the salon has to cancel your booking (for example, your stylist is absent), we will either refund your deposit in full or reschedule your booking with the deposit carried over.";
    }

    /** "Booking received" message (in-app + email) with the booking details, payment instructions, and the policy. */
    public static function bookingReceivedMessage(string $reference, array $serviceNames, string $branchName, string $appointmentDateTime,
        float $amountDue, float $balance, bool $payOnline): string
    {
        // The service, branch and time come from the details CustomerNotifier adds.
        $message = "Your booking request {$reference} has been received and is waiting for branch confirmation.";
        if ($amountDue > 0) {
            $message .= "\n\nPay now: ₱" . number_format($amountDue, 2)
                . ($payOnline ? ' online within ' . PayMongo::HOLD_MINUTES . ' minutes to keep your slot.' : ' in cash at the branch.')
                . ($balance > 0 ? "\nBalance at the salon: ₱" . number_format($balance, 2) . '.' : '');
        }
        return $message . "\n\n" . self::summary();
    }

    /** Staff cancellation choices: salon refund, salon reschedule, or the customer asked (cashier only). */
    public const STAFF_CANCEL_ACTIONS = ['refund', 'reschedule', 'customer'];

    /** Why a staff cancellation request is invalid, or null. Every choice needs a reason the customer sees. */
    public static function staffCancelError(string $action, string $reason, bool $allowCustomer): ?string
    {
        $allowed = $allowCustomer ? self::STAFF_CANCEL_ACTIONS : ['refund', 'reschedule'];
        if (!in_array($action, $allowed, true)) {
            return $allowCustomer
                ? 'Choose what happens: refund the deposit, reschedule, or the customer asked to cancel.'
                : 'Choose whether to refund the deposit or reschedule the booking.';
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            return 'Give a reason for the cancellation (3 to 255 characters). The customer will see it.';
        }
        return null;
    }

    /**
     * After staff moved the booking to Cancelled ('refund' / 'customer') or
     * Reschedule Requested ('reschedule'): saves the reason, applies the
     * deposit rule, sends ONE email + SMS to the customer and audits it.
     */
    public static function closeByStaff(PDO $pdo, array $appointment, string $action, string $reason): void
    {
        $reference = $appointment['reference_code'];
        $customerId = (int) $appointment['customer_id'];
        if ($action === 'reschedule') {
            CustomerNotifier::notify($pdo, $customerId, 'RESCHEDULE',
                "We're sorry, the salon can't keep your appointment {$reference} at its current time. Reason: {$reason}."
                . "\n\nPlease choose a new time from My Appointments (or Track your booking), or contact the branch. Any deposit you paid stays with this booking.", $reference);
            AuditLog::record($pdo, 'booking/salon_cancel', 'Cancelled by the salon (rescheduling instead)', $reference, $reason);
            return;
        }
        $pdo->prepare('UPDATE appointments SET cancel_reason = ?, cancelled_by = ? WHERE id = ?')
            ->execute([$reason, $_SESSION['user_id'] ?? null, $appointment['id']]);
        if ($action === 'refund') {
            $refundNote = self::apply($pdo, $appointment, self::SALON_REFUND, $reason);
            CustomerNotifier::notify($pdo, $customerId, 'SALON_CANCELLED',
                "We're sorry, the salon had to cancel your appointment {$reference}. Reason: {$reason}."
                . ($refundNote !== '' ? "\n\n{$refundNote}" : '')
                . "\n\nIf you'd like to book again, we'll be happy to find you a new time.", $reference);
            AuditLog::record($pdo, 'booking/salon_cancel', 'Cancelled by the salon (deposit refunded)', $reference, $reason);
            return;
        }
        // The customer asked at the counter: the non-refundable rule applies (apply() sends the deposit notice).
        CustomerNotifier::notify($pdo, $customerId, 'CANCELLED', "Your appointment {$reference} was cancelled as you requested. Note: {$reason}.", $reference);
        self::apply($pdo, $appointment, self::BY_CUSTOMER);
        AuditLog::record($pdo, 'booking/customer_cancel', 'Cancelled at the customer\'s request', $reference, $reason);
    }

    public static function holdsDeposit(array $appointment): bool
    {
        if (in_array($appointment['payment_status'], ['Down Payment Verified', 'Fully Paid'], true)) return true;
        return PayMongo::isOnline($appointment['deposit_method']) && (int) $appointment['deposit_paid'] === 1
            && $appointment['payment_status'] === 'Awaiting Verification';
    }

    public static function hoursUntil(PDO $pdo, array $appointment): float
    {
        $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, NOW(), ?) / 60');
        $stmt->execute([$appointment['appointment_datetime']]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * The payment status a cancellation would produce, or null when the
     * deposit isn't affected. Deposits are non-refundable, except when the
     * salon cancels and the admin chose a refund.
     */
    public static function outcome(PDO $pdo, array $appointment, string $trigger): ?string
    {
        if (!self::holdsDeposit($appointment)) return null;
        return $trigger === self::SALON_REFUND ? 'Refund Due' : 'Forfeited';
    }

    /** No automatic lateness/no-show threshold: absence is a staff decision. */
    public static function validateTransition(PDO $pdo, array $appointment, string $next): void
    {
        $current = $appointment['status'];
        if ($current === $next) return;
        if (in_array($current, ['Cancelled', 'No-Show'], true)) {
            throw new RuntimeException('This booking is already closed. Use the rescheduling action where eligible; do not change its status directly.');
        }
        if (in_array($current, ['Completed', 'Reviewed'], true) && !($current === 'Completed' && $next === 'Reviewed')) {
            throw new RuntimeException('This service is already completed and cannot be cancelled or restarted.');
        }
        if ($next === 'No-Show') {
            if ($current === 'In Progress') throw new RuntimeException('A customer whose service has started cannot be marked No-Show.');
            $future = $pdo->prepare('SELECT NOW() < ?');
            $future->execute([$appointment['appointment_datetime']]);
            if ($future->fetchColumn()) throw new RuntimeException('A future appointment cannot be marked No-Show.');
        }
        if ($next === 'In Progress' && $current !== 'Confirmed') throw new RuntimeException('Confirm the booking before starting service.');
        if ($next === 'Completed' && $current !== 'In Progress') throw new RuntimeException('Start the service before marking it completed.');
    }

    /**
     * Applies the policy after the appointment's status was changed to
     * Cancelled/No-Show: updates the payment status, logs it, notifies the
     * customer, and alerts anyone waitlisted for the freed stylist. Returns a
     * customer-facing sentence about the deposit ('' when unaffected).
     */
    public static function apply(PDO $pdo, array $appointment, string $trigger, ?string $reason = null): string
    {
        $close = $pdo->prepare("UPDATE appointments SET cancelled_at = NOW() WHERE id = ? AND cancelled_at IS NULL AND status IN ('Cancelled', 'No-Show')");
        $close->execute([$appointment['id']]);
        if ($close->rowCount() !== 1) return ''; // Repeated closure is a no-op.

        if ($appointment['employee_id']) {
            Waitlist::notifyOpening($pdo, (int) $appointment['employee_id'], $appointment['appointment_datetime']);
        }

        $outcome = self::outcome($pdo, $appointment, $trigger);
        if ($outcome === null) return '';

        $payment = $pdo->prepare('UPDATE appointments SET payment_status = ? WHERE id = ? AND payment_status = ?');
        $payment->execute([$outcome, $appointment['id'], $appointment['payment_status']]);
        if ($payment->rowCount() !== 1) return '';
        $amount = (float) $appointment['deposit_amount'];
        $peso = '₱' . number_format($amount, 2);
        $reference = $appointment['reference_code'];

        if ($outcome === 'Refund Due') {
            // Recorded against the payment: amount, reason, admin and (created_at) date.
            PaymentLog::record($pdo, 'refund_due', (int) $appointment['id'], $reference, null, $amount, $outcome,
                ['trigger' => $trigger, 'reason' => $reason, 'by_user_id' => $_SESSION['user_id'] ?? null, 'method' => $appointment['deposit_method']]);
            // The caller sends one cancellation notice that includes this sentence.
            return "Your {$peso} reservation deposit will be refunded in full"
                . (PayMongo::isOnline($appointment['deposit_method']) ? ' to your original GCash, Maya or card payment.' : '. The branch will contact you to return it.');
        }

        PaymentLog::record($pdo, 'deposit_forfeited', (int) $appointment['id'], $reference,
            null, $amount, $outcome, ['trigger' => $trigger, 'method' => $appointment['deposit_method']]);

        $reason = match ($trigger) {
            self::NO_SHOW => 'the appointment was missed (no-show)',
            self::BY_SALON => 'the booking was cancelled by the salon',
            default => 'the booking was cancelled',
        };
        CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'PAYMENT_FORFEITED',
            "Your {$peso} reservation deposit for {$reference} is non-refundable and was kept because {$reason}. "
            . 'You can still reschedule this booking from My Appointments or by contacting the branch and the deposit will carry over.');
        return "Your {$peso} deposit is non-refundable, but you can still reschedule this booking and use it.";
    }
}
