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
    const SLOT_UNAVAILABLE_MESSAGE = 'Selected time slot is unavailable. Please choose another schedule.';

    public static function cleaningBufferMinutes(): int
    {
        $value = env('SALON_CLEANING_BUFFER_MINUTES');
        return ctype_digit($value) ? (int) $value : 30;
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
            if (self::staffHasConflict($pdo, $mainStaffId, $start, $duration, (int) $appointment['id'])) {
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
            if (self::staffHasConflict($pdo, $employeeId, $cursor->format('Y-m-d H:i:s'), $minutes, (int) $appointment['id'])) {
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
        elseif (substr($now, 0, 10) !== substr($appointment['appointment_datetime'], 0, 10)) $reason = 'This appointment is on a different day. Please reschedule it before starting service.';
        elseif ($now < $appointment['appointment_datetime']) $reason = 'The scheduled start time has not arrived. Review availability again at the appointment time.';
        elseif (self::isOutsideOperatingHours($start, $duration, $branchKey)) $reason = 'The full service would run outside branch operating hours. Please reschedule.';
        elseif (self::branchWindowIsFull($pdo, (int) $appointment['branch_id'], $start, $duration, (int) $appointment['id'])) $reason = 'The branch is full for the actual service window. Please choose another schedule.';
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
            $stmt = $pdo->query('SELECT branch_key, opening_time, closing_time, sunday_opening_time, sunday_closing_time, slot_limit FROM branches');
            self::$branchConfigCache = [];
            foreach ($stmt->fetchAll() as $row) {
                self::$branchConfigCache[$row['branch_key']] = $row;
            }
        }
        return self::$branchConfigCache[$branchKey] ?? null;
    }

    /**
     * Optionally pass the booking's date ($dateYmd, 'Y-m-d') to apply that
     * branch's distinct Sunday hours, if configured (sunday_opening_time/
     * sunday_closing_time both set) -- otherwise the regular daily hours
     * apply every day including Sunday, same as before this was configurable.
     */
    public static function operatingHours(string $branchKey, ?string $dateYmd = null): array
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
            $cursor->modify('+30 minutes');
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
            $stmt = $pdo->prepare($isWedding
                ? 'SELECT travel_minutes, setup_minutes, service_minutes FROM wedding_packages WHERE package_name = ?'
                : "SELECT travel_minutes, setup_minutes, service_minutes FROM event_type_durations WHERE event_type IN (?, 'Other') ORDER BY event_type = 'Other' LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch() ?: [];
            self::$eventDurationCache[$cacheKey] = [
                'travel' => (int) ($row['travel_minutes'] ?? 0),
                'setup' => (int) ($row['setup_minutes'] ?? 0),
                'service' => (int) ($row['service_minutes'] ?? 0) ?: self::HOME_SERVICE_DURATION_MINUTES,
            ];
        }
        return self::$eventDurationCache[$cacheKey];
    }

    /**
     * The staff time one event blocks. The preferred time is when the
     * service starts at the venue, so the team leaves (travel) and sets up
     * before it; the cleaning buffer follows the service. 'end' includes the
     * buffer, 'minutes' does not (staffHasConflict() adds it itself).
     */
    public static function homeServiceWindow(PDO $pdo, array $request): array
    {
        $durations = self::homeServiceDurations($pdo, $request);
        $lead = $durations['travel'] + $durations['setup'];
        $start = (new DateTime($request['preferred_date'] . ' ' . ($request['preferred_time'] ?: '09:00:00')))->modify("-{$lead} minutes");
        $minutes = $lead + $durations['service'];
        return $durations + [
            'start' => $start,
            'end' => (clone $start)->modify('+' . ($minutes + self::cleaningBufferMinutes()) . ' minutes'),
            'minutes' => $minutes,
        ];
    }

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
        $stmt = $pdo->prepare("SELECT h.id, h.preferred_date, h.preferred_time, h.event_type, h.wedding_package FROM home_service_requests h
            WHERE h.status NOT IN ('Cancelled', 'Completed')
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

    /** attendance.notes markers -- shown to staff/cashier/admin so auto-closed hours can be reviewed. */
    const NOTE_AUTO_CLOSE = 'Auto clock-out at closing time — staff did not sign out';
    const NOTE_EOD_CLOSE = 'Clocked out at end-of-day close — staff did not sign out';

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
     * still mid-service today is left open; a shift from a previous day is
     * always closed, even if a booking was left In Progress.
     */
    public static function autoCloseAttendance(PDO $pdo, int $employeeId, ?int $branchId): void
    {
        if (!$branchId) {
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id, clock_in_time, DATE(clock_in_time) AS work_date, DATE(clock_in_time) = CURDATE() AS is_today
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

            if ((int) $row['is_today']) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE employee_id = ? AND status = 'In Progress' AND DATE(appointment_datetime) = CURDATE()");
                $stmt->execute([$employeeId]);
                $hasActiveAppointment = (int) $stmt->fetchColumn() > 0;

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM home_service_requests WHERE employee_id = ? AND status = 'Confirmed'");
                $stmt->execute([$employeeId]);
                $hasActiveHomeService = (int) $stmt->fetchColumn() > 0;

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
