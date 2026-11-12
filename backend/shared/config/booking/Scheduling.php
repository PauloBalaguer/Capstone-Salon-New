<?php

require_once __DIR__ . '/../notifications/StaffNotifier.php';
require_once __DIR__ . '/../setup/env.php';

/**
 * Duration-aware slot/staff conflict checks shared by every booking write
 * path (submitBooking, submitGuestBooking, admin/cashier assignStaff) and
 * the public slot picker (getAvailableSlots).
 *
 * An appointment's real length is the sum of its linked services'
 * duration_minutes, not a flat 30-minute grid cell. Two appointments
 * conflict when their [start, start+duration) windows overlap, not only
 * when they share the exact same start time.
 */
class Scheduling
{
    const DEFAULT_DURATION_MINUTES = 30;
    // Bookable start times are offered every this many minutes (timeSlots()).
    const SLOT_INTERVAL_MINUTES = 30;
    const SLOT_UNAVAILABLE_MESSAGE = 'Selected time slot is unavailable. Please choose another schedule.';

    public static function cleaningBufferMinutes(): int
    {
        $value = env('SALON_CLEANING_BUFFER_MINUTES');
        return ctype_digit($value) ? (int) $value : 15;
    }

    /** Persistent rows serialize writes even when no appointments exist yet. */
    private static function lockResource(PDO $pdo, string $table, int $id): void
    {
        if (!$pdo->inTransaction()) return;
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $stmt->fetchColumn();
    }

    const STAFF_UNAVAILABLE_MESSAGE = 'This staff member is unavailable at the selected time. Please choose another schedule or staff member.';

    /** Caller owns the transaction. Lock branch before appointment, as booking creation does. */
    public static function lockAppointment(PDO $pdo, int $id): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Scheduling write requires a transaction.');
        $stmt = $pdo->prepare('SELECT branch_id FROM appointments WHERE id = ?');
        $stmt->execute([$id]);
        self::lockResource($pdo, 'branches', (int) $stmt->fetchColumn());
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $appointment = $stmt->fetch();
        if (!$appointment) throw new RuntimeException('Booking not found.');
        return $appointment;
    }

    /** Validate the proposed main stylist and every explicit per-service assignment. */
    public static function validateAppointmentStaff(PDO $pdo, array $appointment, string $start, ?int $mainStaffId = null, bool $replaceMainServices = false): void
    {
        $mainStaffId = $mainStaffId ?? (int) $appointment['employee_id'];
        $stmt = $pdo->prepare('SELECT aps.service_id, aps.employee_id, s.duration_minutes
            FROM appointment_services aps JOIN services s ON s.id = aps.service_id
            WHERE aps.appointment_id = ? ORDER BY aps.id' . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $stmt->execute([$appointment['id']]);
        $rows = $stmt->fetchAll();
        if ($replaceMainServices) {
            foreach ($rows as &$row) {
                if ($row['employee_id'] !== null && (int) $row['employee_id'] === (int) $appointment['employee_id']) $row['employee_id'] = $mainStaffId;
            }
            unset($row);
        }
        $qualified = $pdo->prepare('SELECT e.id FROM employees e WHERE e.id = ? AND e.branch_id = ? AND e.is_active = 1
            AND (? = 0 OR EXISTS (SELECT 1 FROM staff_services ss WHERE ss.employee_id = e.id AND ss.service_id = ?))');
        $qualified->execute([$mainStaffId, $appointment['branch_id'], 0, 0]);
        if (!$qualified->fetchColumn()) throw new RuntimeException('Please choose an active staff member at this branch.');
        if ($replaceMainServices && $rows) {
            $qualified->execute([$mainStaffId, $appointment['branch_id'], $rows[0]['service_id'], $rows[0]['service_id']]);
            if (!$qualified->fetchColumn()) throw new RuntimeException('Selected staff member cannot perform the assigned service at this branch.');
        }
        // A replacement main stylist must be qualified and available too,
        // even when explicit service rows continue to name other stylists.
        if (!$replaceMainServices && $mainStaffId !== (int) $appointment['employee_id'] && $rows) {
            $qualified->execute([$mainStaffId, $appointment['branch_id'], $rows[0]['service_id'], $rows[0]['service_id']]);
            if (!$qualified->fetchColumn()) throw new RuntimeException('Selected staff member cannot perform the assigned service at this branch.');
            $duration = array_sum(array_map(fn($row) => (int) $row['duration_minutes'] > 0 ? (int) $row['duration_minutes'] : self::DEFAULT_DURATION_MINUTES, $rows));
            if (self::staffScheduleBlock($pdo, $mainStaffId, $start, $duration) !== null
                || self::staffHasConflict($pdo, $mainStaffId, $start, $duration, (int) $appointment['id'])) {
                throw new RuntimeException(self::STAFF_UNAVAILABLE_MESSAGE);
            }
        }
        $cursor = new DateTime($start);
        if (!$rows) $rows = [['service_id' => 0, 'employee_id' => null, 'duration_minutes' => self::DEFAULT_DURATION_MINUTES]];
        foreach ($rows as $row) {
            $employeeId = $row['employee_id'] !== null ? (int) $row['employee_id'] : $mainStaffId;
            $qualified->execute([$employeeId, $appointment['branch_id'], $row['service_id'], $row['service_id']]);
            if (!$qualified->fetchColumn()) throw new RuntimeException('Selected staff member cannot perform the assigned service at this branch.');
            $minutes = (int) $row['duration_minutes'] > 0 ? (int) $row['duration_minutes'] : self::DEFAULT_DURATION_MINUTES;
            // Schedule, leave and holidays for the requested time (not live
            // shift status), then conflicts incl. confirmed home visits.
            if (self::staffScheduleBlock($pdo, $employeeId, $cursor->format('Y-m-d H:i:s'), $minutes) !== null
                || self::staffHasConflict($pdo, $employeeId, $cursor->format('Y-m-d H:i:s'), $minutes, (int) $appointment['id'])) {
                throw new RuntimeException(self::STAFF_UNAVAILABLE_MESSAGE);
            }
            $cursor->modify("+{$minutes} minutes");
        }
    }

    /**
     * SALON_LATE_GRACE_MINUTES in the private .env (see .env.example); null
     * when unset. Informational only; never an automatic penalty.
     */
    public static function arrivalGraceMinutes(): ?int
    {
        $value = env('SALON_LATE_GRACE_MINUTES');
        return ctype_digit($value) ? (int) $value : null;
    }

    /** Whole minutes since the reserved start (negative before it), by MySQL's clock. */
    public static function minutesLate(PDO $pdo, array $appointment): int
    {
        $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, ?, NOW())');
        $stmt->execute([$appointment['appointment_datetime']]);
        return (int) $stmt->fetchColumn();
    }

    /** With no grace period configured, any time after the reserved start counts as past it. */
    public static function isPastArrivalGrace(PDO $pdo, array $appointment): bool
    {
        return self::minutesLate($pdo, $appointment) > (self::arrivalGraceMinutes() ?? 0);
    }

    /**
     * The late-arrival rule shown to customers (Terms & Conditions, booking
     * confirmation screens and emails), or null when no grace period is set
     * so the rule stays hidden.
     */
    public static function lateArrivalRule(): ?string
    {
        $grace = self::arrivalGraceMinutes();
        if ($grace === null) return null;
        return "Please arrive on time. Arriving up to {$grace} minutes after your appointment time is within our grace period. "
            . 'If you arrive later, staff will check whether your full service still fits the schedule; you may be served by another '
            . 'available stylist or asked to reschedule. Lateness never cancels your booking automatically.';
    }

    /** Review actual service windows without moving or cancelling any appointment. */
    public static function arrivalReview(PDO $pdo, array $appointment, ?string $now = null): array
    {
        $now = $now ?? $pdo->query('SELECT NOW()')->fetchColumn();
        $start = max($now, $appointment['appointment_datetime']);
        $stmt = $pdo->prepare('SELECT branch_key FROM branches WHERE id = ?');
        $stmt->execute([$appointment['branch_id']]);
        $branchKey = (string) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT service_id FROM appointment_services WHERE appointment_id = ? ORDER BY id');
        $stmt->execute([$appointment['id']]);
        $duration = self::totalDurationMinutes($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN));
        $reason = null;
        if ($appointment['status'] !== 'Confirmed') $reason = 'Confirm the booking before reviewing its arrival.';
        elseif (substr($now, 0, 10) !== substr($appointment['appointment_datetime'], 0, 10)) $reason = 'This booking is for another day.';
        elseif ($now < $appointment['appointment_datetime']) $reason = 'Too early. Check in at the reserved time.';
        elseif (self::isOutsideOperatingHours($start, $duration, $branchKey)) $reason = 'It would end after closing time.';
        elseif (self::branchWindowIsFull($pdo, (int) $appointment['branch_id'], $start, $duration, (int) $appointment['id'])) $reason = 'The branch is fully booked for this time.';
        $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE branch_id = ? AND is_active = 1 ORDER BY (id = ?) DESC, id');
        $stmt->execute([$appointment['branch_id'], (int) $appointment['employee_id']]);
        $staff = [];
        foreach ($stmt->fetchAll() as $employee) {
            $available = $reason === null;
            if ($available) {
                try { self::validateAppointmentStaff($pdo, $appointment, $start, (int) $employee['id'], true); }
                catch (RuntimeException $e) { if ($e instanceof PDOException) throw $e; $available = false; }
            }
            $staff[] = ['id' => (string) $employee['id'], 'name' => trim($employee['first_name'] . ' ' . $employee['last_name']), 'available' => $available];
        }
        $isLate = $now > $appointment['appointment_datetime'];
        $minutesLate = max(0, (int) floor((strtotime($now) - strtotime($appointment['appointment_datetime'])) / 60));
        $grace = self::arrivalGraceMinutes();
        return ['scheduledStart' => $appointment['appointment_datetime'], 'actualStart' => $start,
            'isLate' => $isLate, 'minutesLate' => $minutesLate, 'graceMinutes' => $grace,
            // Shown to staff only; the decision (start, reassign, reschedule, no-show) stays theirs.
            'graceStatus' => !$isLate ? 'on_time' : ($grace === null ? 'unset' : ($minutesLate <= $grace ? 'within' : 'beyond')),
            'durationMinutes' => $duration,
            'paymentStatus' => $appointment['payment_status'], 'staff' => $staff, 'reason' => $reason];
    }

    /**
     * Tells each stylist on a booking (main and per-service) that it is
     * confirmed, whether or not they are on duty now: type, branch, their
     * own service(s), date and time. In-portal only. notifyOnce() makes a
     * retried confirmation harmless, and a failed notification never undoes
     * the booking (StaffNotifier swallows its own errors).
     */
    public static function notifyStaffOfConfirmation(PDO $pdo, array $appointment, ?array $onlyIds = null): void
    {
        $stmt = $pdo->prepare('SELECT aps.employee_id, s.service_name FROM appointment_services aps JOIN services s ON s.id = aps.service_id
            WHERE aps.appointment_id = ? ORDER BY aps.id');
        $stmt->execute([$appointment['id']]);
        $servicesBy = [];
        foreach ($stmt->fetchAll() as $row) {
            $who = $row['employee_id'] !== null ? (int) $row['employee_id'] : (int) $appointment['employee_id'];
            if ($who) $servicesBy[$who][] = $row['service_name'];
        }
        if ($appointment['employee_id'] && !isset($servicesBy[(int) $appointment['employee_id']])) $servicesBy[(int) $appointment['employee_id']] = [];
        $stmt = $pdo->prepare('SELECT branch_name FROM branches WHERE id = ?');
        $stmt->execute([$appointment['branch_id'] ?? 0]);
        $branch = $stmt->fetchColumn() ?: 'the salon';
        $when = date('D, M j, Y \a\t g:i A', strtotime($appointment['appointment_datetime']));
        foreach ($servicesBy as $id => $services) {
            if ($onlyIds !== null && !in_array($id, $onlyIds, true)) continue;
            $what = $services ? implode(', ', array_unique($services)) : 'salon service';
            StaffNotifier::notifyOnce($pdo, $id, 'APPOINTMENT_ASSIGNED',
                "Salon appointment {$appointment['reference_code']} is confirmed and assigned to you: {$what} at {$branch}, {$when}.");
        }
    }

    /**
     * After the main stylist of a salon booking changes (Admin or Cashier
     * reassignment): one message to the newly assigned stylist and one to
     * the stylist taken off it. Other stylists on the booking are not
     * affected and hear nothing. No change = no messages.
     * $appointment must already hold the new employee_id.
     */
    public static function notifyStaffOfReassignment(PDO $pdo, array $appointment, ?int $oldStaffId, int $newStaffId): void
    {
        if ($oldStaffId === $newStaffId) return;
        if ($appointment['status'] === 'Confirmed') {
            self::notifyStaffOfConfirmation($pdo, $appointment, [$newStaffId]);
        } else {
            StaffNotifier::notifyOnce($pdo, $newStaffId, 'APPOINTMENT_ASSIGNED', "You've been assigned to appointment {$appointment['reference_code']} on "
                . date('D, M j, Y \a\t g:i A', strtotime($appointment['appointment_datetime'])) . '.');
        }
        if ($oldStaffId) {
            StaffNotifier::notifyOnce($pdo, $oldStaffId, 'SCHEDULE_CHANGED', "You're no longer assigned to appointment {$appointment['reference_code']} on "
                . date('D, M j, Y \a\t g:i A', strtotime($appointment['appointment_datetime'])) . '; that time is free again.');
        }
    }

    /**
     * After a salon booking moves (Reschedule::apply): every stylist working
     * on it at the new time gets one message with the old and new time, and
     * a main stylist replaced by the move is told they're off it. The old and
     * new times are in the text, so a later move is never mistaken for a repeat.
     */
    public static function notifyStaffOfReschedule(PDO $pdo, array $appointment, string $oldDateTime, ?int $oldMainId): void
    {
        $stmt = $pdo->prepare('SELECT employee_id FROM appointment_services WHERE appointment_id = ? AND employee_id IS NOT NULL');
        $stmt->execute([$appointment['id']]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($appointment['employee_id']) $ids[] = (int) $appointment['employee_id'];
        $ids = array_values(array_unique($ids));
        $stmt = $pdo->prepare('SELECT branch_name FROM branches WHERE id = ?');
        $stmt->execute([$appointment['branch_id'] ?? 0]);
        $branch = $stmt->fetchColumn() ?: 'the salon';
        $old = date('D, M j g:i A', strtotime($oldDateTime));
        $new = date('D, M j, Y \a\t g:i A', strtotime($appointment['appointment_datetime']));
        foreach ($ids as $id) {
            StaffNotifier::notifyOnce($pdo, $id, 'SCHEDULE_CHANGED',
                "Salon appointment {$appointment['reference_code']} at {$branch} ({$appointment['status']}) moved from {$old} to {$new}.");
        }
        if ($oldMainId && !in_array($oldMainId, $ids, true)) {
            StaffNotifier::notifyOnce($pdo, $oldMainId, 'SCHEDULE_CHANGED',
                "You're no longer assigned to appointment {$appointment['reference_code']} ({$old}); it was rescheduled with another stylist.");
        }
    }

    /** Called under the same branch/appointment transaction as status changes. */
    public static function startAppointment(PDO $pdo, array $appointment, ?int $staffId, bool $arrivalConfirmed): string
    {
        if (!$arrivalConfirmed) throw new RuntimeException('Review the actual arrival time and available staff before starting service.');
        $review = self::arrivalReview($pdo, $appointment);
        if ($review['reason']) throw new RuntimeException($review['reason']);
        $staffId = $staffId ?: (int) $appointment['employee_id'];
        self::validateAppointmentStaff($pdo, $appointment, $review['actualStart'], $staffId, true);
        if ($staffId !== (int) $appointment['employee_id']) {
            // Explicit approval replaces only the original main stylist's services.
            // Other per-service stylists remain assigned and were also validated.
            $pdo->prepare('UPDATE appointment_services SET employee_id = ? WHERE appointment_id = ? AND employee_id = ?')
                ->execute([$staffId, $appointment['id'], $appointment['employee_id']]);
            StaffNotifier::notify($pdo, $staffId, 'APPOINTMENT_ASSIGNED', 'You are handling the reviewed arrival for ' . $appointment['reference_code'] . '.');
            if ($appointment['employee_id']) StaffNotifier::notify($pdo, (int) $appointment['employee_id'], 'SCHEDULE_CHANGED', 'The arrival for ' . $appointment['reference_code'] . ' was reassigned after schedule review.');
        }
        $note = 'Arrival reviewed: reserved ' . $appointment['appointment_datetime'] . ', service start ' . $review['actualStart']
            . ', staff ' . $appointment['employee_id'] . ' -> ' . $staffId . '; payment unchanged.';
        $pdo->prepare('UPDATE appointments SET appointment_datetime = ?, employee_id = ?, notes = CONCAT_WS(CHAR(10), NULLIF(notes, ?), ?) WHERE id = ?')
            ->execute([$review['actualStart'], $staffId, '', $note, $appointment['id']]);
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
            ->execute([$appointment['reference_code'] . ': ' . $note]);
        return $note;
    }
    // Shortest bookable service. Enforced when services are saved
    // (backend/admin/saveService.php) so no service is too short to perform properly.
    const MIN_SERVICE_MINUTES = 20;
    // How many concurrent appointments a branch can hold in the same
    // overlapping time window -- configured per branch (not one flat global
    // limit) so a smaller branch can be capped differently from a bigger
    // one. Same default (2) as before this became per-branch.
    // Fallback defaults only, used if a branch row is somehow missing its
    // configured hours/capacity. The real, admin-editable source of truth is
    // branches.opening_time/closing_time/sunday_*_time/slot_limit (see
    // Platform Settings -> Operating Hours / Booking Capacity, and migration
    // 033_services_reports_customers_settings.sql).
    const BOOKING_SLOT_LIMITS = [
        'daraga' => 2,
        'yashano' => 2,
        'cabangan' => 2,
    ];
    const BRANCH_HOURS = [
        'daraga' => ['08:00:00', '20:00:00'],
        'yashano' => ['09:30:00', '20:00:00'],
        'cabangan' => ['08:00:00', '20:00:00'],
    ];

    private static ?array $branchConfigCache = null;

    /** Lazily loaded once per request via the shared Database singleton -- no PDO param needed by every caller. */
    private static function branchConfig(string $branchKey): ?array
    {
        if (self::$branchConfigCache === null) {
            $pdo = Database::getInstance();
            // SELECT * so a column added by a later migration (offers_home_service) is optional.
            $stmt = $pdo->query('SELECT * FROM branches');
            self::$branchConfigCache = [];
            foreach ($stmt->fetchAll() as $row) {
                self::$branchConfigCache[$row['branch_key']] = $row;
            }
        }
        return self::$branchConfigCache[$branchKey] ?? null;
    }

    /** branch_key for a branch id, or null. */
    public static function branchKeyForId(PDO $pdo, ?int $branchId): ?string
    {
        if (!$branchId) return null;
        $stmt = $pdo->prepare('SELECT branch_key FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$branchId]);
        $key = $stmt->fetchColumn();
        return $key === false ? null : (string) $key;
    }

    /** Whether this branch accepts home service requests (migration 062; true before it runs). */
    public static function branchOffersHomeService(string $branchKey): bool
    {
        $row = self::branchConfig($branchKey);
        return $row !== null && (!array_key_exists('offers_home_service', $row) || (int) $row['offers_home_service'] === 1);
    }

    private static array $holidayCache = [];

    /**
     * The branch_holidays row for this branch and date (migration 062), or
     * null. Holiday types: 'closed' (Closed all day), 'special_hours'
     * (open_time..close_time), 'open' (Open as usual).
     */
    public static function branchHoliday(string $branchKey, string $dateYmd): ?array
    {
        $cacheKey = $branchKey . '|' . $dateYmd;
        if (!array_key_exists($cacheKey, self::$holidayCache)) {
            $row = null;
            $config = self::branchConfig($branchKey);
            if ($config) {
                try {
                    $stmt = Database::getInstance()->prepare('SELECT holiday_type, open_time, close_time, name FROM branch_holidays WHERE branch_id = ? AND holiday_date = ? LIMIT 1');
                    $stmt->execute([$config['id'], $dateYmd]);
                    $row = $stmt->fetch() ?: null;
                } catch (PDOException $e) {
                    $row = null; // Migration 062 not applied yet: no holidays.
                }
            }
            self::$holidayCache[$cacheKey] = $row;
        }
        return self::$holidayCache[$cacheKey];
    }

    /** Closed all day for a branch holiday. */
    public static function isBranchClosed(string $branchKey, string $dateYmd): bool
    {
        $holiday = self::branchHoliday($branchKey, $dateYmd);
        return $holiday !== null && ($holiday['holiday_type'] === 'closed'
            || ($holiday['holiday_type'] === 'special_hours' && (!$holiday['open_time'] || !$holiday['close_time'])));
    }

    /**
     * Optionally pass the booking's date ($dateYmd, 'Y-m-d') to apply that
     * branch's distinct Sunday hours, if configured (sunday_opening_time/
     * sunday_closing_time both set) -- otherwise the regular daily hours
     * apply every day including Sunday, same as before this was configurable.
     *
     * With a date, a branch holiday (migration 062) wins: special hours
     * replace the day's hours, and a closed day returns 00:00-00:00 so no
     * slot fits (callers that need to say "closed" use isBranchClosed()).
     */
    public static function operatingHours(string $branchKey, ?string $dateYmd = null): array
    {
        if ($dateYmd !== null && ($holiday = self::branchHoliday($branchKey, $dateYmd))) {
            if (self::isBranchClosed($branchKey, $dateYmd)) return ['00:00:00', '00:00:00'];
            if ($holiday['holiday_type'] === 'special_hours') return [$holiday['open_time'], $holiday['close_time']];
        }
        return self::regularHours($branchKey, $dateYmd);
    }

    /** The branch's normal weekly hours for that weekday, ignoring holidays. */
    public static function regularHours(string $branchKey, ?string $dateYmd = null): array
    {
        $row = self::branchConfig($branchKey);
        if (!$row) {
            return self::BRANCH_HOURS[$branchKey] ?? ['09:00:00', '20:00:00'];
        }
        if ($dateYmd !== null && (int) date('N', strtotime($dateYmd)) === 7
            && $row['sunday_opening_time'] && $row['sunday_closing_time']
        ) {
            return [$row['sunday_opening_time'], $row['sunday_closing_time']];
        }
        return [$row['opening_time'], $row['closing_time']];
    }

    /** This branch's configured concurrent-booking capacity. */
    public static function slotLimitForKey(string $branchKey): int
    {
        $row = self::branchConfig($branchKey);
        return $row ? (int) $row['slot_limit'] : (self::BOOKING_SLOT_LIMITS[$branchKey] ?? 2);
    }

    /** Same, looked up by numeric branch id (what most callers have on hand). */
    public static function slotLimit(PDO $pdo, int $branchId): int
    {
        $stmt = $pdo->prepare('SELECT branch_key FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$branchId]);
        $branchKey = $stmt->fetchColumn();
        return $branchKey ? self::slotLimitForKey($branchKey) : 2;
    }

    /** Build 30-minute booking starts from opening until the final half-hour before closing. */
    public static function timeSlots(string $branchKey, ?string $dateYmd = null): array
    {
        [$openingTime, $closingTime] = self::operatingHours($branchKey, $dateYmd);
        $cursor = new DateTime('2000-01-01 ' . $openingTime);
        $closing = new DateTime('2000-01-01 ' . $closingTime);
        $slots = [];
        while ($cursor < $closing) {
            $slots[] = $cursor->format('h:i A');
            $cursor->modify('+' . self::SLOT_INTERVAL_MINUTES . ' minutes');
        }
        return $slots;
    }

    /** Total minutes for a set of service IDs. Falls back to 30 min/service if duration_minutes isn't set. */
    public static function totalDurationMinutes(PDO $pdo, array $serviceIds): int
    {
        if (empty($serviceIds)) {
            return self::DEFAULT_DURATION_MINUTES;
        }
        $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM services WHERE id IN ($placeholders)");
        $stmt->execute($serviceIds);
        $total = (int) $stmt->fetchColumn();
        return $total > 0 ? $total : self::DEFAULT_DURATION_MINUTES * count($serviceIds);
    }

    /** True if a booking starts before opening or finishes after this branch closes. */
    public static function isOutsideOperatingHours(string $startDateTime, int $durationMinutes, string $branchKey): bool
    {
        $start = new DateTime($startDateTime);
        $end = (clone $start)->modify("+{$durationMinutes} minutes");
        [$openingTime, $closingTime] = self::operatingHours($branchKey, $start->format('Y-m-d'));
        $opening = new DateTime($start->format('Y-m-d') . ' ' . $openingTime);
        $closing = new DateTime($start->format('Y-m-d') . ' ' . $closingTime);
        return $start < $opening || $end > $closing;
    }

    /**
     * Counts non-cancelled appointments whose [start, start+duration) window
     * overlaps [$startDateTime, $startDateTime+$durationMinutes), optionally
     * narrowed to one branch and/or one staff member. Uses a locking read
     * (FOR UPDATE) so concurrent bookings for the same day/branch/staff are
     * serialized -- call within a transaction.
     */
    public static function countOverlapping(
        PDO $pdo,
        ?int $branchId,
        ?int $employeeId,
        string $startDateTime,
        int $durationMinutes,
        ?int $excludeAppointmentId = null
    ): int {
        $newStart = new DateTime($startDateTime);
        $newEnd = (clone $newStart)->modify("+{$durationMinutes} minutes");

        $sql = "SELECT id, appointment_datetime FROM appointments WHERE status NOT IN ('Cancelled', 'No-Show') AND DATE(appointment_datetime) = ?";
        $params = [$newStart->format('Y-m-d')];
        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        if ($employeeId !== null) {
            $sql .= ' AND employee_id = ?';
            $params[] = $employeeId;
        }
        if ($excludeAppointmentId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeAppointmentId;
        }
        $sql .= ' FOR UPDATE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();
        if (empty($candidates)) {
            return 0;
        }

        $ids = array_column($candidates, 'id');
        $placeholders = $ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL';
        $stmt = $pdo->prepare("
            SELECT aps.appointment_id, SUM(s.duration_minutes) AS total
            FROM appointment_services aps
            JOIN services s ON s.id = aps.service_id
            WHERE aps.appointment_id IN ($placeholders)
            GROUP BY aps.appointment_id
        ");
        $stmt->execute($ids);
        $durations = [];
        foreach ($stmt->fetchAll() as $row) {
            $durations[$row['appointment_id']] = (int) $row['total'];
        }

        $overlapping = 0;
        foreach ($candidates as $candidate) {
            $duration = $durations[$candidate['id']] ?? 0;
            if ($duration <= 0) {
                $duration = self::DEFAULT_DURATION_MINUTES;
            }
            $existingStart = new DateTime($candidate['appointment_datetime']);
            $existingEnd = (clone $existingStart)->modify("+{$duration} minutes");
            if ($existingStart < $newEnd && $newStart < $existingEnd) {
                $overlapping++;
            }
        }

        return $overlapping;
    }

    /** True if this branch is already at its own configured slot limit for concurrent appointments during this window. */
    public static function branchWindowIsFull(
        PDO $pdo,
        int $branchId,
        string $startDateTime,
        int $durationMinutes,
        ?int $excludeAppointmentId = null
    ): bool {
        self::lockResource($pdo, 'branches', $branchId);
        $start = new DateTime($startDateTime);
        $end = (clone $start)->modify("+{$durationMinutes} minutes");
        $stmt = $pdo->prepare("SELECT a.id, a.appointment_datetime,
            COALESCE(NULLIF((SELECT SUM(s.duration_minutes) FROM appointment_services aps
                JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = a.id), 0), 30) AS minutes
            FROM appointments a WHERE a.branch_id = ? AND a.status NOT IN ('Cancelled', 'No-Show')
            AND a.appointment_datetime < ?" . ($excludeAppointmentId !== null ? ' AND a.id != ?' : '') . ' FOR UPDATE');
        $params = [$branchId, $end->format('Y-m-d H:i:s')];
        if ($excludeAppointmentId !== null) $params[] = $excludeAppointmentId;
        $stmt->execute($params);
        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $a = new DateTime($row['appointment_datetime']);
            $b = (clone $a)->modify('+' . (int) $row['minutes'] . ' minutes');
            if ($b <= $start) continue;
            $events[] = [max($a->getTimestamp(), $start->getTimestamp()), 1];
            $events[] = [min($b->getTimestamp(), $end->getTimestamp()), -1];
        }
        sort($events); // Ends precede starts at the same instant.
        $active = 0;
        $limit = self::slotLimit($pdo, $branchId);
        foreach ($events as $event) {
            $active += $event[1];
            if ($active >= $limit) return true;
        }
        return false;
    }

    // Fallback service length of a home service/event job when its wedding
    // package or event type has no service_minutes set. The real values are
    // admin-edited (wedding_packages / event_type_durations, migration 054).
    const HOME_SERVICE_DURATION_MINUTES = 120;

    private static array $eventDurationCache = [];

    /**
     * Travel, setup and service minutes for one home service/event request.
     * A wedding uses its package's times; any other event uses its event
     * type's row, or the 'Other' row for a typed-in event. Unset values fall
     * back to the old flat job: 0 travel, 0 setup, 120 service.
     */
    public static function homeServiceDurations(PDO $pdo, array $request): array
    {
        $isWedding = ($request['event_type'] ?? '') === 'Wedding';
        $key = $isWedding ? 'Package ' . ($request['wedding_package'] ?? '') : (string) ($request['event_type'] ?? '');
        $cacheKey = ($isWedding ? 'package:' : 'event:') . $key;
        if (!isset(self::$eventDurationCache[$cacheKey])) {
            // SELECT * so return_travel_minutes (migration 062) is optional.
            $stmt = $pdo->prepare($isWedding
                ? 'SELECT * FROM wedding_packages WHERE package_name = ?'
                : "SELECT * FROM event_type_durations WHERE event_type IN (?, 'Other') ORDER BY event_type = 'Other' LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch() ?: [];
            $travel = (int) ($row['travel_minutes'] ?? 0);
            self::$eventDurationCache[$cacheKey] = [
                'travel' => $travel,
                'setup' => (int) ($row['setup_minutes'] ?? 0),
                'service' => (int) ($row['service_minutes'] ?? 0) ?: self::HOME_SERVICE_DURATION_MINUTES,
                // Blank return travel = same as the outbound travel.
                'return' => isset($row['return_travel_minutes']) && $row['return_travel_minutes'] !== null ? (int) $row['return_travel_minutes'] : $travel,
            ];
        }
        return self::$eventDurationCache[$cacheKey];
    }

    /**
     * The staff time one event blocks. The preferred time is when the
     * service starts at the venue, so the team leaves (travel) and sets up
     * before it, and travels back (return) after it; the cleaning buffer
     * follows the return. 'minutes' is the occupied time WITHOUT the buffer
     * (staffConflictUntil() adds the buffer once itself); 'end' includes it,
     * for comparing against other windows that also include theirs.
     * 'occupiedEnd' is when the team is back at the salon.
     *
     * Example: 30 travel, 0 setup, 60 service, 30 return, 2:00 PM start ->
     * start 1:30 PM, occupiedEnd 3:30 PM, end 3:30 PM + buffer.
     */
    public static function homeServiceWindow(PDO $pdo, array $request): array
    {
        $durations = self::homeServiceDurations($pdo, $request);
        $lead = $durations['travel'] + $durations['setup'];
        $start = (new DateTime($request['preferred_date'] . ' ' . ($request['preferred_time'] ?: '09:00:00')))->modify("-{$lead} minutes");
        $minutes = $lead + $durations['service'] + $durations['return'];
        return $durations + [
            'start' => $start,
            'occupiedEnd' => (clone $start)->modify("+{$minutes} minutes"),
            'end' => (clone $start)->modify('+' . ($minutes + self::cleaningBufferMinutes()) . ' minutes'),
            'minutes' => $minutes,
        ];
    }

    /** Home service statuses that reserve the assigned team's time. */
    const HOME_SERVICE_RESERVING_STATUSES = ['Confirmed', 'Staff Assigned', 'Service in Progress'];

    /**
     * True if this staff member is already busy -- either with a salon
     * appointment or another home service job -- during this event's full
     * window (travel + setup + service + buffer). Checked against both
     * tables since a stylist sent off-site is the same person who could
     * otherwise be booked in-branch.
     */
    public static function homeServiceStaffHasConflict(
        PDO $pdo,
        int $employeeId,
        array $request,
        ?int $excludeRequestId = null
    ): bool {
        $window = self::homeServiceWindow($pdo, $request);
        return self::staffHasConflict($pdo, $employeeId, $window['start']->format('Y-m-d H:i:s'), $window['minutes'], null, $excludeRequestId);
    }

    /** True if this specific staff member is busy with another client at any point in this window. */
    public static function staffHasConflict(
        PDO $pdo,
        int $employeeId,
        string $startDateTime,
        int $durationMinutes,
        ?int $excludeAppointmentId = null,
        ?int $excludeHomeServiceId = null
    ): bool {
        return self::staffConflictUntil($pdo, $employeeId, $startDateTime, $durationMinutes, $excludeAppointmentId, $excludeHomeServiceId) !== null;
    }

    /**
     * When this staff member is busy during the window, the end of the
     * latest overlapping busy period (DateTime) -- i.e. "busy until"; null
     * when they're free for the whole window.
     */
    /**
     * True when the employee is scheduled to work for the whole window
     * [$start, $start + $minutes). Uses staff_schedules (set by Admin); an
     * employee with no row for that weekday falls back to branch hours,
     * which booking already enforces, so they count as scheduled.
     */
    public static function staffIsScheduled(PDO $pdo, int $employeeId, string $start, int $minutes): bool
    {
        return self::staffScheduleBlock($pdo, $employeeId, $start, $minutes) === null;
    }

    /** Reasons staffScheduleBlock()/staffAvailability() return, with the text shown to people. */
    const BLOCK_MESSAGES = [
        'leave' => 'On leave',
        'day_off' => 'Day off',
        'branch_closed' => 'Branch closed (holiday)',
        'outside_hours' => 'Unavailable at this time',
        'not_on_duty' => 'Not on duty right now',
        'past' => 'This time has passed',
    ];

    /** The staff_leave row covering $dateYmd for this employee (migration 062), or null. */
    public static function staffLeaveOn(PDO $pdo, int $employeeId, string $dateYmd): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT id, start_date, end_date, reason FROM staff_leave WHERE employee_id = ? AND ? BETWEEN start_date AND end_date LIMIT 1');
            $stmt->execute([$employeeId, $dateYmd]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            return null; // Migration 062 not applied yet.
        }
    }

    /**
     * Why the employee is NOT scheduled for the whole window
     * [$start, $start + $minutes), or null when they are. Only the requested
     * time matters, never whether they are clocked in now:
     *   leave          -- a staff_leave row covers the date
     *   day_off        -- their staff_schedules row marks it a rest day
     *   branch_closed  -- their branch is closed all day for a holiday
     *   outside_hours  -- the window does not fit their own hours
     *                     (staff_schedules), or -- with no row for that
     *                     weekday, or on a holiday -- their branch's
     *                     effective hours for that date.
     * An explicit day off or leave blocks even when the branch is open.
     */
    public static function staffScheduleBlock(PDO $pdo, int $employeeId, string $start, int $minutes): ?string
    {
        $begin = new DateTime($start);
        $finish = (clone $begin)->modify("+{$minutes} minutes");
        $date = $begin->format('Y-m-d');
        if (self::staffLeaveOn($pdo, $employeeId, $date)) return 'leave';
        $stmt = $pdo->prepare('SELECT start_time, end_time, is_rest_day FROM staff_schedules WHERE employee_id = ? AND day_of_week = ?');
        $stmt->execute([$employeeId, (int) $begin->format('N')]);
        $row = $stmt->fetch();
        if ($row && ($row['is_rest_day'] || !$row['start_time'] || !$row['end_time'])) return 'day_off';
        if ($finish->format('Y-m-d') !== $date && $finish->format('H:i:s') !== '00:00:00') return 'outside_hours';
        $endTime = $finish->format('Y-m-d') !== $date ? '24:00:00' : $finish->format('H:i:s');
        if ($row && ($begin->format('H:i:s') < $row['start_time'] || $endTime > $row['end_time'])) return 'outside_hours';

        $stmt = $pdo->prepare('SELECT b.branch_key FROM employees e JOIN branches b ON b.id = e.branch_id WHERE e.id = ?');
        $stmt->execute([$employeeId]);
        $branchKey = $stmt->fetchColumn();
        if ($branchKey) {
            if (self::isBranchClosed($branchKey, $date)) return 'branch_closed';
            // Branch hours apply as the fallback (no staff row) and on a holiday.
            if (!$row || self::branchHoliday($branchKey, $date)) {
                [$open, $close] = self::operatingHours($branchKey, $date);
                if ($begin->format('H:i:s') < $open || $endTime > $close) return 'outside_hours';
            }
        }
        return null;
    }

    /**
     * Can this stylist take a booking in [$start, $start + $minutes)?
     *
     * Advance bookings (anything not starting right now -- tomorrow, or later
     * today) only need the stylist to be scheduled at the requested time
     * (staffScheduleBlock: schedule, leave, holidays). Someone off shift now
     * can be booked for a future shift.
     *
     * $immediate (a walk-in or a service starting now) additionally needs the
     * stylist on duty right now: clocked in, not on a break, not away on a
     * home visit. Overlapping clients are checked separately (staffHasConflict).
     */
    public static function staffCanTakeBooking(PDO $pdo, int $employeeId, string $start, int $minutes, bool $immediate = false): bool
    {
        return self::staffBookingBlock($pdo, $employeeId, $start, $minutes, $immediate) === null;
    }

    /** staffCanTakeBooking() with the reason code (BLOCK_MESSAGES key) when it can't. */
    public static function staffBookingBlock(PDO $pdo, int $employeeId, string $start, int $minutes, bool $immediate = false): ?string
    {
        if ($block = self::staffScheduleBlock($pdo, $employeeId, $start, $minutes)) return $block;
        if (!$immediate) return null;
        require_once __DIR__ . '/../helpers/StaffShift.php';
        return in_array(StaffShift::liveStatus($pdo, $employeeId), [StaffShift::STATUS_AVAILABLE, StaffShift::STATUS_BUSY], true)
            ? null : 'not_on_duty';
    }

    /**
     * Full advance-booking check for one stylist and window, with a message
     * for the booking forms: schedule/leave/holiday, then conflicts with
     * salon bookings and confirmed home visits (cleaning buffer included).
     *
     * @return array{available: bool, code: ?string, message: string, busyUntil: ?string}
     */
    public static function staffAvailability(PDO $pdo, int $employeeId, string $start, int $minutes, ?int $excludeAppointmentId = null, bool $immediate = false): array
    {
        $code = self::staffBookingBlock($pdo, $employeeId, $start, $minutes, $immediate);
        $until = null;
        if ($code === null) {
            $conflict = self::staffConflictUntil($pdo, $employeeId, $start, $minutes, $excludeAppointmentId);
            if ($conflict !== null) { $code = 'busy'; $until = $conflict->format('g:i A'); }
        }
        if ($code === null) {
            return ['available' => true, 'code' => null, 'message' => 'Available ' . self::friendlyWhen($start), 'busyUntil' => null];
        }
        return ['available' => false, 'code' => $code,
            'message' => $code === 'busy' ? "Booked until {$until}" : self::BLOCK_MESSAGES[$code],
            'busyUntil' => $until];
    }

    /** "today at 3:00 PM", "tomorrow at 10:00 AM", "on Mon, Nov 16 at 10:00 AM" (Asia/Manila). */
    public static function friendlyWhen(string $dateTime): string
    {
        $zone = new DateTimeZone('Asia/Manila');
        $when = new DateTimeImmutable($dateTime, $zone);
        $today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
        $day = $when->format('Y-m-d');
        $label = $day === $today ? 'today' : ($day === (new DateTimeImmutable('tomorrow', $zone))->format('Y-m-d') ? 'tomorrow' : 'on ' . $when->format('D, M j'));
        return $label . ' at ' . $when->format('g:i A');
    }

    public static function staffConflictUntil(
        PDO $pdo,
        int $employeeId,
        string $startDateTime,
        int $durationMinutes,
        ?int $excludeAppointmentId = null,
        ?int $excludeHomeServiceId = null
    ): ?DateTime {
        self::lockResource($pdo, 'employees', $employeeId);
        $newStart = new DateTime($startDateTime);
        $newEnd = (clone $newStart)->modify('+' . ($durationMinutes + self::cleaningBufferMinutes()) . ' minutes');
        $until = null;
        foreach (self::staffBusyWindows($pdo, $employeeId, $newStart->format('Y-m-d'), $excludeAppointmentId, $excludeHomeServiceId) as $window) {
            if ($window['start'] < $newEnd && $newStart < $window['end'] && ($until === null || $window['end'] > $until)) {
                $until = $window['end'];
            }
        }
        return $until;
    }

    /**
     * Every period on $dateYmd this staff member is with a client. A booking
     * with several services runs them back-to-back in booking order
     * (appointment_services.id); each service is done by its own stylist
     * (appointment_services.employee_id) or, when that's NULL, by the
     * booking's main stylist (appointments.employee_id). So a stylist is only
     * busy for their own services' part of a shared booking. Locking read
     * (FOR UPDATE) like countOverlapping() -- call within a transaction when
     * the result guards an insert/update.
     *
     * @return array<int, array{start: DateTime, end: DateTime, appointment_id: int}>
     */
    public static function staffBusyWindows(PDO $pdo, int $employeeId, string $dateYmd, ?int $excludeAppointmentId = null, ?int $excludeHomeServiceId = null): array
    {
        $sql = "SELECT a.id, a.appointment_datetime, a.employee_id FROM appointments a
                WHERE a.status NOT IN ('Cancelled', 'No-Show') AND a.appointment_datetime < DATE_ADD(?, INTERVAL 2 DAY)
                  AND (a.employee_id = ? OR EXISTS (SELECT 1 FROM appointment_services x WHERE x.appointment_id = a.id AND x.employee_id = ?))";
        $params = [$dateYmd, $employeeId, $employeeId];
        if ($excludeAppointmentId !== null) {
            $sql .= ' AND a.id != ?';
            $params[] = $excludeAppointmentId;
        }
        $stmt = $pdo->prepare($sql . ' FOR UPDATE');
        $stmt->execute($params);
        $appointments = $stmt->fetchAll();

        $ids = array_column($appointments, 'id');
        $placeholders = $ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL';
        $stmt = $pdo->prepare("
            SELECT aps.appointment_id, aps.employee_id, s.duration_minutes
            FROM appointment_services aps JOIN services s ON s.id = aps.service_id
            WHERE aps.appointment_id IN ($placeholders)
            ORDER BY aps.appointment_id, aps.id FOR UPDATE
        ");
        if ($ids) $stmt->execute($ids);
        $servicesByAppointment = [];
        foreach ($ids ? $stmt->fetchAll() : [] as $row) {
            $servicesByAppointment[$row['appointment_id']][] = $row;
        }

        $windows = [];
        foreach ($appointments as $appointment) {
            $cursor = new DateTime($appointment['appointment_datetime']);
            $rows = $servicesByAppointment[$appointment['id']] ?? [];
            if (!$rows) {
                // No service rows: the whole default-length booking is the main stylist's.
                if ((int) $appointment['employee_id'] === $employeeId) {
                    $windows[] = ['start' => clone $cursor, 'end' => (clone $cursor)->modify('+' . self::DEFAULT_DURATION_MINUTES . ' minutes'), 'appointment_id' => (int) $appointment['id']];
                }
                continue;
            }
            foreach ($rows as $row) {
                $minutes = (int) $row['duration_minutes'] > 0 ? (int) $row['duration_minutes'] : self::DEFAULT_DURATION_MINUTES;
                $end = (clone $cursor)->modify("+{$minutes} minutes");
                $doneBy = $row['employee_id'] !== null ? (int) $row['employee_id'] : (int) $appointment['employee_id'];
                if ($doneBy === $employeeId) {
                    $windows[] = ['start' => clone $cursor, 'end' => $end, 'appointment_id' => (int) $appointment['id']];
                }
                $cursor = $end;
            }
        }
        $buffer = self::cleaningBufferMinutes();
        foreach ($windows as &$window) $window['end']->modify("+{$buffer} minutes");
        unset($window);
        // Only Confirmed / Staff Assigned / Service in Progress visits reserve
        // the team. A pending or pre-assigned request (before Confirmed) never
        // blocks the stylist; cancellation releases the time.
        $stmt = $pdo->prepare("SELECT h.id, h.preferred_date, h.preferred_time, h.event_type, h.wedding_package FROM home_service_requests h
            WHERE h.status IN ('Confirmed', 'Staff Assigned', 'Service in Progress')
              AND (h.employee_id = ? OR EXISTS (SELECT 1 FROM home_service_staff t WHERE t.home_service_request_id = h.id AND t.employee_id = ?))
              AND h.preferred_date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)
              AND h.id != ? FOR UPDATE");
        $stmt->execute([$employeeId, $employeeId, $dateYmd, $dateYmd, $excludeHomeServiceId ?? 0]);
        foreach ($stmt->fetchAll() as $event) {
            $window = self::homeServiceWindow($pdo, $event);
            $windows[] = ['start' => $window['start'], 'end' => $window['end'], 'appointment_id' => 0];
        }
        return $windows;
    }

    /**
     * How full one stylist's day is, for the admin Staff page and the staff
     * portal: clients booked, minutes of service booked (cleaning buffer not
     * counted), and the working minutes available that day -- their own
     * staff_schedules hours, or their branch's hours when they have none.
     * Uses staffBusyWindows(), so shared bookings, per-service stylists and
     * confirmed home visits count exactly as they do for availability.
     *
     * @return array{date: string, clients: int, bookedMinutes: int, workMinutes: int, freeMinutes: int, offReason: ?string}
     */
    public static function staffDayLoad(PDO $pdo, int $employeeId, string $dateYmd): array
    {
        $offReason = null;
        $workMinutes = 0;
        if (self::staffLeaveOn($pdo, $employeeId, $dateYmd)) {
            $offReason = 'On leave';
        } else {
            $stmt = $pdo->prepare('SELECT start_time, end_time, is_rest_day FROM staff_schedules WHERE employee_id = ? AND day_of_week = ?');
            $stmt->execute([$employeeId, (int) (new DateTime($dateYmd))->format('N')]);
            $row = $stmt->fetch();
            $stmt = $pdo->prepare('SELECT b.branch_key FROM employees e JOIN branches b ON b.id = e.branch_id WHERE e.id = ?');
            $stmt->execute([$employeeId]);
            $branchKey = $stmt->fetchColumn() ?: null;
            if ($row && ($row['is_rest_day'] || !$row['start_time'] || !$row['end_time'])) {
                $offReason = 'Day off';
            } elseif ($branchKey && self::isBranchClosed($branchKey, $dateYmd)) {
                $offReason = 'Branch closed';
            } else {
                if ($row && !($branchKey && self::branchHoliday($branchKey, $dateYmd))) {
                    [$from, $to] = [$row['start_time'], $row['end_time']];
                } elseif ($branchKey) {
                    [$from, $to] = self::operatingHours($branchKey, $dateYmd);
                } else {
                    [$from, $to] = [null, null];
                }
                if ($from && $to) {
                    $workMinutes = max(0, (int) round((strtotime("2000-01-01 {$to}") - strtotime("2000-01-01 {$from}")) / 60));
                }
            }
        }

        $buffer = self::cleaningBufferMinutes();
        $clients = [];
        $booked = 0;
        foreach (self::staffBusyWindows($pdo, $employeeId, $dateYmd) as $i => $window) {
            if ($window['start']->format('Y-m-d') !== $dateYmd) continue;
            $minutes = (int) round(($window['end']->getTimestamp() - $window['start']->getTimestamp()) / 60) - $buffer;
            $booked += max(0, $minutes);
            // Several windows of one booking (back-to-back services) are one client.
            $clients[$window['appointment_id'] ? 'a' . $window['appointment_id'] : 'h' . $i] = true;
        }

        return [
            'date' => $dateYmd,
            'clients' => count($clients),
            'bookedMinutes' => $booked,
            'workMinutes' => $workMinutes,
            'freeMinutes' => max(0, $workMinutes - $booked),
            'offReason' => $offReason,
        ];
    }

    /**
     * When this staff member's current busy period ends, cleaning buffer
     * included, or null when free now. Same windows that block new bookings;
     * back-to-back windows count as one busy period.
     */
    public static function staffBusyUntilNow(PDO $pdo, int $employeeId): ?DateTime
    {
        $now = new DateTime($pdo->query('SELECT NOW()')->fetchColumn());
        $windows = self::staffBusyWindows($pdo, $employeeId, $now->format('Y-m-d'));
        $until = null;
        do {
            $extended = false;
            foreach ($windows as $window) {
                $reach = $until ?? $now;
                if ($window['start'] <= $reach && $window['end'] > $reach) {
                    $until = $window['end'];
                    $extended = true;
                }
            }
        } while ($extended);
        return $until;
    }

    /** attendance.notes markers -- shown to staff/cashier/admin so auto-closed hours can be reviewed. */
    const NOTE_AUTO_CLOSE = 'Auto clock-out at closing time staff did not sign out';
    const NOTE_EOD_CLOSE = 'Clocked out at end-of-day close staff did not sign out';

    /**
     * A login after the branch's closing time doesn't start a shift, so it
     * can't add hours or show the stylist as available. A stylist still on an
     * In Progress service today may continue, whatever the time.
     */
    public static function canStartShift(PDO $pdo, int $employeeId, ?int $branchId): bool
    {
        if (!$branchId) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT branch_key FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$branchId]);
        $branchKey = $stmt->fetchColumn();
        if (!$branchKey) {
            return true;
        }
        $today = $pdo->query('SELECT CURDATE()')->fetchColumn();
        [, $closingTime] = self::operatingHours($branchKey, $today);
        $stmt = $pdo->prepare('SELECT NOW() < ?');
        $stmt->execute([$today . ' ' . $closingTime]);
        if ($stmt->fetchColumn()) {
            return true;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments a WHERE a.status = 'In Progress' AND DATE(a.appointment_datetime) = CURDATE()
            AND (a.employee_id = ? OR EXISTS (SELECT 1 FROM appointment_services aps WHERE aps.appointment_id = a.id AND aps.employee_id = ?))");
        $stmt->execute([$employeeId, $employeeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Automatic attendance Time Out. There's no cron/background worker in
     * this project, and browser-close events are unreliable (tab killed,
     * laptop closed, crash), so this closes any open attendance row for the
     * employee the moment it's next checked if the branch's closing time
     * for that work day has passed. It runs for one employee on their own
     * login/dashboard load, and for the whole branch via
     * autoCloseBranchAttendance() whenever the public roster, the cashier
     * dashboard, or end-of-day close loads -- so a forgotten sign-out never
     * leaves someone "Available" after closing.
     *
     * The clock-out is recorded at the branch's closing time (not "now") and
     * tagged NOTE_AUTO_CLOSE so an admin can correct the hours. A stylist
     * still mid-service is left open, also past midnight for a shift that
     * started yesterday; older shifts are always closed, even if a booking
     * was left In Progress.
     */
    public static function autoCloseAttendance(PDO $pdo, int $employeeId, ?int $branchId): void
    {
        if (!$branchId) {
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id, clock_in_time, DATE(clock_in_time) AS work_date, DATE(clock_in_time) = CURDATE() AS is_today,
                   DATE(clock_in_time) >= CURDATE() - INTERVAL 1 DAY AS is_recent
            FROM attendance
            WHERE employee_id = ? AND clock_out_time IS NULL
        ");
        $stmt->execute([$employeeId]);
        $openRows = $stmt->fetchAll();
        if (!$openRows) {
            return;
        }

        $stmt = $pdo->prepare('SELECT branch_key FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$branchId]);
        $branchKey = $stmt->fetchColumn();
        if (!$branchKey) {
            return;
        }

        foreach ($openRows as $row) {
            [, $closingTime] = self::operatingHours($branchKey, $row['work_date']);
            $shiftEnd = $row['work_date'] . ' ' . $closingTime;

            // Clocked in after closing (e.g. logging in late): closing time
            // would be earlier than the clock-in, so close it at the
            // clock-in instead of recording negative hours -- but only once
            // the day is over; today it stays open.
            if ($shiftEnd <= $row['clock_in_time']) {
                if (!(int) $row['is_today']) {
                    $pdo->prepare('UPDATE attendance SET clock_out_time = clock_in_time, notes = ? WHERE id = ?')
                        ->execute([self::NOTE_AUTO_CLOSE, $row['id']]);
                }
                continue;
            }

            // Compared via MySQL's own NOW() rather than PHP's clock -- they
            // can run in different timezones, and clock_in_time itself was
            // already stamped using MySQL's NOW(), so this is the only
            // comparison guaranteed to agree with it.
            $stmt = $pdo->prepare('SELECT NOW() >= ?');
            $stmt->execute([$shiftEnd]);
            if (!$stmt->fetchColumn()) {
                continue; // Branch hours haven't ended yet for this work day -- stays On Shift.
            }

            // A service still running keeps its shift open, also past
            // midnight (a shift from yesterday whose service is still on).
            if ((int) $row['is_recent']) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE employee_id = ? AND status = 'In Progress' AND DATE(appointment_datetime) = ?");
                $stmt->execute([$employeeId, $row['work_date']]);
                $hasActiveAppointment = (int) $stmt->fetchColumn() > 0;

                $hasActiveHomeService = false;
                if ((int) $row['is_today']) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM home_service_requests WHERE employee_id = ? AND status IN ('Confirmed', 'Staff Assigned', 'Service in Progress') AND preferred_date = CURDATE()");
                    $stmt->execute([$employeeId]);
                    $hasActiveHomeService = (int) $stmt->fetchColumn() > 0;
                }

                if ($hasActiveAppointment || $hasActiveHomeService) {
                    continue; // Real work still open tonight -- don't force Off Shift yet.
                }
            }

            $pdo->prepare('UPDATE attendance SET clock_out_time = ?, notes = ? WHERE id = ?')
                ->execute([$shiftEnd, self::NOTE_AUTO_CLOSE, $row['id']]);
        }
    }

    /** Runs autoCloseAttendance() for every staff member of a branch who still has an open shift. */
    public static function autoCloseBranchAttendance(PDO $pdo, int $branchId): void
    {
        $stmt = $pdo->prepare('
            SELECT DISTINCT a.employee_id FROM attendance a
            JOIN employees e ON e.id = a.employee_id
            WHERE e.branch_id = ? AND a.clock_out_time IS NULL
        ');
        $stmt->execute([$branchId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $employeeId) {
            self::autoCloseAttendance($pdo, (int) $employeeId, $branchId);
        }
    }

    /**
     * End-of-day: clocks out everyone at the branch who is still on shift,
     * at the moment the day is closed. Returns the names clocked out.
     */
    public static function clockOutBranchAtEod(PDO $pdo, int $branchId): array
    {
        $stmt = $pdo->prepare("
            SELECT a.id, CONCAT(e.first_name, ' ', e.last_name) AS name
            FROM attendance a
            JOIN employees e ON e.id = a.employee_id
            WHERE e.branch_id = ? AND a.clock_out_time IS NULL AND a.clock_in_time <= NOW()
        ");
        $stmt->execute([$branchId]);
        $rows = $stmt->fetchAll();
        $update = $pdo->prepare('UPDATE attendance SET clock_out_time = NOW(), notes = ? WHERE id = ?');
        foreach ($rows as $row) {
            $update->execute([self::NOTE_EOD_CLOSE, $row['id']]);
        }
        return array_column($rows, 'name');
    }
}
