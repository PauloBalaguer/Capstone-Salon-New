<?php

require_once __DIR__ . '/Scheduling.php';
require_once __DIR__ . '/CustomerNotifier.php';
require_once __DIR__ . '/Waitlist.php';
require_once __DIR__ . '/CancellationPolicy.php';

/**
 * Moving a salon appointment to a new date/time -- shared by the cashier
 * (backend/cashier/rescheduleAppointment.php) and the customer
 * (backend/customer/rescheduleAppointment.php) so both see the same open
 * slots and follow the same rules.
 *
 * A slot is open only if it's in the future, inside branch hours for the
 * booking's full duration, the branch isn't at its slot limit, and a
 * stylist qualified for every booked service is free the whole time. The
 * booking itself is excluded from every overlap check, so its own current
 * slot never blocks it. Deposit, services and reference never change.
 */
final class Reschedule
{
    public const STATUSES = ['Pending', 'Confirmed', 'Reschedule Requested'];

    /** Customers must reschedule at least this far ahead -- same cut-off as a free cancellation. */
    public const CUSTOMER_CUTOFF_HOURS = CancellationPolicy::FREE_CANCEL_HOURS;

    /**
     * Loads the booking by reference, scoped to a branch (cashier) or to the
     * customer's own user id. Returns null when not found / not theirs.
     */
    public static function load(PDO $pdo, string $referenceCode, ?int $branchId = null, ?int $customerUserId = null): ?array
    {
        $sql = 'SELECT a.id, a.reference_code, a.customer_id, a.employee_id, a.branch_id, a.appointment_datetime, a.status, a.payment_status,
                       a.total_price, a.deposit_amount, a.deposit_paid, a.deposit_recorded_by,
                       b.branch_key, b.branch_name
                FROM appointments a
                JOIN branches b ON b.id = a.branch_id
                JOIN customers c ON c.id = a.customer_id
                WHERE a.reference_code = ?';
        $params = [$referenceCode];
        if ($branchId !== null) {
            $sql .= ' AND a.branch_id = ?';
            $params[] = $branchId;
        }
        if ($customerUserId !== null) {
            $sql .= ' AND c.user_id = ?';
            $params[] = $customerUserId;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $appointment = $stmt->fetch();
        if (!$appointment) return null;

        $stmt = $pdo->prepare('SELECT service_id FROM appointment_services WHERE appointment_id = ?');
        $stmt->execute([$appointment['id']]);
        $appointment['service_ids'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $appointment['duration_minutes'] = Scheduling::totalDurationMinutes($pdo, $appointment['service_ids']);
        return $appointment;
    }

    /** Payment statuses of a cancelled booking whose deposit the salon still holds (deposits are non-refundable). */
    public const KEPT_DEPOSIT_STATUSES = ['Forfeited', 'Refund Due'];

    /**
     * A cancelled booking whose deposit was kept (see CancellationPolicy)
     * can be rescheduled, which reinstates it with that deposit.
     */
    public static function isReinstatable(array $appointment): bool
    {
        return $appointment['status'] === 'Cancelled'
            && in_array($appointment['payment_status'], self::KEPT_DEPOSIT_STATUSES, true)
            && (float) $appointment['deposit_amount'] > 0;
    }

    /** Why this booking can't be rescheduled by this party right now, or null if it can. */
    public static function blockedReason(array $appointment, bool $byCustomer): ?string
    {
        if (self::isReinstatable($appointment)) {
            return null; // No cut-off: the original date may already have passed.
        }
        if ($appointment['status'] === 'Cancelled') {
            return $appointment['payment_status'] === 'Refunded'
                ? 'This cancelled booking\'s deposit was already refunded, so there\'s nothing to carry over. Please make a new booking instead.'
                : 'This booking was cancelled without a deposit on file. Please make a new booking instead.';
        }
        if (!in_array($appointment['status'], self::STATUSES, true)) {
            return 'Only Pending, Confirmed, Reschedule Requested, or cancelled bookings with a kept deposit can be rescheduled.';
        }
        // The salon asked for the change, so the customer isn't held to the cut-off.
        if ($byCustomer && $appointment['status'] !== 'Reschedule Requested'
            && self::hoursUntil($appointment['appointment_datetime']) < self::CUSTOMER_CUTOFF_HOURS
        ) {
            return 'Appointments can only be rescheduled online at least ' . self::CUSTOMER_CUTOFF_HOURS
                . ' hours ahead. Please contact the branch to change this booking.';
        }
        return null;
    }

    /**
     * Every time slot on $date for this booking. Each is marked available,
     * or carries why not: 'past', 'closed' (would run past closing),
     * 'full' (branch at its slot limit), or 'busy' (the chosen stylist -- or,
     * with no stylist chosen, every qualified stylist -- is still with
     * another client for part of the window). $staffId null = any stylist.
     */
    public static function slots(PDO $pdo, array $appointment, string $date, ?int $staffId = null): array
    {
        return array_map(function ($slot) use ($pdo, $appointment, $date, $staffId) {
            $start = date('Y-m-d H:i:s', strtotime("$date $slot"));
            $reason = self::unavailableReason($pdo, $appointment, $start, $staffId);
            return ['time' => $slot, 'available' => $reason === null, 'reason' => $reason];
        }, Scheduling::timeSlots($appointment['branch_key'], $date));
    }

    public static function isOpen(PDO $pdo, array $appointment, string $startDateTime, ?int $staffId = null): bool
    {
        return self::unavailableReason($pdo, $appointment, $startDateTime, $staffId) === null;
    }

    /** null when this start works for the booking, else 'past' | 'closed' | 'full' | 'busy'. */
    public static function unavailableReason(PDO $pdo, array $appointment, string $startDateTime, ?int $staffId = null): ?string
    {
        if (strtotime($startDateTime) <= time()) return 'past';
        $duration = (int) $appointment['duration_minutes'];
        if (Scheduling::isOutsideOperatingHours($startDateTime, $duration, $appointment['branch_key'])) return 'closed';
        if (Scheduling::branchWindowIsFull($pdo, (int) $appointment['branch_id'], $startDateTime, $duration, (int) $appointment['id'])) return 'full';
        if ($appointment['service_ids'] && self::freeStylist($pdo, $appointment, $startDateTime, $staffId) === null) return 'busy';
        return null;
    }

    /** Active stylists at the booking's branch qualified for every one of its services (current stylist first). */
    public static function qualifiedStylists(PDO $pdo, array $appointment): array
    {
        $serviceIds = $appointment['service_ids'];
        if (!$serviceIds) return [];
        $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = $pdo->prepare("
            SELECT e.id, e.first_name, e.last_name, e.position
            FROM employees e
            WHERE e.branch_id = ? AND e.is_active = 1
              AND (SELECT COUNT(DISTINCT service_id) FROM staff_services WHERE employee_id = e.id AND service_id IN ($placeholders)) = ?
            ORDER BY (e.id = ?) DESC, e.first_name ASC, e.last_name ASC
        ");
        $stmt->execute([$appointment['branch_id'], ...$serviceIds, count($serviceIds), (int) $appointment['employee_id']]);
        return $stmt->fetchAll();
    }

    /**
     * The stylist who'd take the booking at this start: the requested one
     * ($staffId) if qualified and free for the whole window, otherwise --
     * when no stylist was requested -- the first free qualified one
     * (current stylist preferred). null when nobody fits.
     */
    public static function freeStylist(PDO $pdo, array $appointment, string $startDateTime, ?int $staffId = null): ?array
    {
        foreach (self::qualifiedStylists($pdo, $appointment) as $candidate) {
            if ($staffId !== null && (int) $candidate['id'] !== $staffId) continue;
            if (!Scheduling::staffHasConflict($pdo, (int) $candidate['id'], $startDateTime, (int) $appointment['duration_minutes'], (int) $appointment['id'])) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Moves the booking. Throws RuntimeException (message safe to show) when
     * the slot is taken or unchanged. Returns ['when', 'stylist', 'status'].
     */
    public static function apply(PDO $pdo, array $appointment, string $date, string $time, bool $byCustomer, string $reason = '', ?int $staffId = null): array
    {
        $timestamp = strtotime("$date $time");
        if ($timestamp === false) throw new RuntimeException('Invalid date or time.');
        $newDateTime = date('Y-m-d H:i:s', $timestamp);
        $sameStylist = $staffId === null || $staffId === (int) $appointment['employee_id'];
        if ($newDateTime === $appointment['appointment_datetime'] && $sameStylist && !self::isReinstatable($appointment)) {
            throw new RuntimeException('That is already this booking\'s schedule. Pick a different date, time, or stylist.');
        }
        if ($staffId !== null && !in_array($staffId, array_map(fn($s) => (int) $s['id'], self::qualifiedStylists($pdo, $appointment)), true)) {
            throw new RuntimeException('That stylist doesn\'t offer every service in this booking. Please choose another stylist.');
        }

        $pdo->beginTransaction();
        try {
            // Re-checked inside the transaction (countOverlapping locks rows)
            // so a booking made while the picker was open can't be double-booked.
            $blocked = self::unavailableReason($pdo, $appointment, $newDateTime, $staffId);
            if ($blocked !== null) {
                throw new RuntimeException($blocked === 'busy' && $staffId !== null
                    ? 'That stylist is no longer free at that time. Please pick another time or stylist.'
                    : 'That time is no longer available. Please pick another slot.');
            }
            $stylist = $appointment['service_ids'] ? self::freeStylist($pdo, $appointment, $newDateTime, $staffId) : null;
            $employeeId = $stylist ? (int) $stylist['id'] : ($appointment['employee_id'] !== null ? (int) $appointment['employee_id'] : null);

            $reinstating = self::isReinstatable($appointment);
            $status = $appointment['status'];
            $paymentStatus = $appointment['payment_status'];
            if ($reinstating) {
                // Cancelled with a kept deposit: bring the booking back and
                // restore what the deposit covers. A deposit staff verified
                // (or PayMongo confirmed) confirms it; otherwise it goes back
                // to Pending for verification.
                $verified = $appointment['deposit_recorded_by'] !== null;
                $paymentStatus = !$verified ? 'Awaiting Verification'
                    : (round((float) $appointment['deposit_amount'] * 100) >= round((float) $appointment['total_price'] * 100) ? 'Fully Paid' : 'Down Payment Verified');
                $status = $verified ? 'Confirmed' : 'Pending';
            } elseif ($status === 'Reschedule Requested') {
                $status = in_array($appointment['payment_status'], ['Down Payment Verified', 'Fully Paid'], true) ? 'Confirmed' : 'Pending';
            }

            $pdo->prepare('UPDATE appointments SET appointment_datetime = ?, employee_id = ?, status = ?, payment_status = ?, reminder_sent = 0'
                . ($reinstating ? ', cancelled_at = NULL' : '') . ' WHERE id = ?')
                ->execute([$newDateTime, $employeeId, $status, $paymentStatus, $appointment['id']]);
            if ($reinstating) {
                PaymentLog::record($pdo, 'deposit_reinstated', (int) $appointment['id'], $appointment['reference_code'], null,
                    (float) $appointment['deposit_amount'], $paymentStatus, ['by' => $byCustomer ? 'customer' : 'staff', 'new_datetime' => $newDateTime]);
            }

            $when = date('l, F j, Y \a\t g:i A', $timestamp);
            $stylistName = $stylist ? trim($stylist['first_name'] . ' ' . $stylist['last_name']) : '';
            $ref = $appointment['reference_code'];
            $withStylist = $stylistName !== '' ? " with {$stylistName}" : '';

            $customerMessage = $reinstating
                ? "Your cancelled booking {$ref} at {$appointment['branch_name']} has been rescheduled to {$when}{$withStylist}. "
                    . 'Your ₱' . number_format((float) $appointment['deposit_amount'], 2) . ' deposit has been applied to it.'
                : ($byCustomer
                    ? "You moved appointment {$ref} at {$appointment['branch_name']} to {$when}{$withStylist}. Your reservation payment carries over."
                    : "Your appointment {$ref} at {$appointment['branch_name']} has been moved to {$when}{$withStylist}. Your reservation payment carries over.");
            if ($reason !== '') $customerMessage .= " Reason: {$reason}";
            CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'RESCHEDULE', $customerMessage);

            $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
                ->execute(["Booking {$ref} rescheduled " . ($byCustomer ? 'by the customer ' : '') . "to {$newDateTime}{$withStylist}."]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // The old slot just opened up for anyone waiting on that stylist.
        if ($appointment['employee_id'] !== null) {
            Waitlist::notifyOpening($pdo, (int) $appointment['employee_id'], $appointment['appointment_datetime']);
        }

        return ['when' => $when, 'stylist' => $stylistName, 'status' => $status, 'paymentStatus' => $paymentStatus, 'datetime' => $newDateTime];
    }

    private static function hoursUntil(string $dateTime): float
    {
        return (strtotime($dateTime) - time()) / 3600;
    }
}
