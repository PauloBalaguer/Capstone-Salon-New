<?php

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

        $sql = "SELECT id, appointment_datetime FROM appointments WHERE status != 'Cancelled' AND DATE(appointment_datetime) = ?";
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
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
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
        return self::countOverlapping($pdo, $branchId, null, $startDateTime, $durationMinutes, $excludeAppointmentId) >= self::slotLimit($pdo, $branchId);
    }

    // Home service jobs have no per-service duration data (no catalog item
    // is attached to a home service request), so a flat block is used for
    // conflict checking -- same idea as DEFAULT_DURATION_MINUTES above, just
    // sized for an off-site job instead of a single salon service.
    const HOME_SERVICE_DURATION_MINUTES = 120;

    /**
     * True if this staff member is already busy -- either with a salon
     * appointment or another home service job -- during the window starting
     * at $date/$time. Checked against both tables since a stylist sent
     * off-site is the same person who could otherwise be booked in-branch.
     */
    public static function homeServiceStaffHasConflict(
        PDO $pdo,
        int $employeeId,
        string $date,
        string $time,
        ?int $excludeRequestId = null
    ): bool {
        $startDateTime = $date . ' ' . $time;
        if (self::staffHasConflict($pdo, $employeeId, $startDateTime, self::HOME_SERVICE_DURATION_MINUTES)) {
            return true;
        }

        // Any home service team they're on (home_service_staff), or lead.
        $sql = "SELECT preferred_time FROM home_service_requests
                WHERE (employee_id = ? OR EXISTS (SELECT 1 FROM home_service_staff t WHERE t.home_service_request_id = home_service_requests.id AND t.employee_id = ?))
                  AND preferred_date = ? AND status NOT IN ('Cancelled', 'Completed')";
        $params = [$employeeId, $employeeId, $date];
        if ($excludeRequestId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeRequestId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $newStart = new DateTime($startDateTime);
        $newEnd = (clone $newStart)->modify('+' . self::HOME_SERVICE_DURATION_MINUTES . ' minutes');
        foreach ($stmt->fetchAll() as $row) {
            $existingStart = new DateTime($date . ' ' . ($row['preferred_time'] ?: '09:00:00'));
            $existingEnd = (clone $existingStart)->modify('+' . self::HOME_SERVICE_DURATION_MINUTES . ' minutes');
            if ($existingStart < $newEnd && $newStart < $existingEnd) {
                return true;
            }
        }
        return false;
    }

    /** True if this specific staff member is busy with another client at any point in this window. */
    public static function staffHasConflict(
        PDO $pdo,
        int $employeeId,
        string $startDateTime,
        int $durationMinutes,
        ?int $excludeAppointmentId = null
    ): bool {
        return self::staffConflictUntil($pdo, $employeeId, $startDateTime, $durationMinutes, $excludeAppointmentId) !== null;
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
        ?int $excludeAppointmentId = null
    ): ?DateTime {
        $newStart = new DateTime($startDateTime);
        $newEnd = (clone $newStart)->modify("+{$durationMinutes} minutes");
        $until = null;
        foreach (self::staffBusyWindows($pdo, $employeeId, $newStart->format('Y-m-d'), $excludeAppointmentId) as $window) {
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
    public static function staffBusyWindows(PDO $pdo, int $employeeId, string $dateYmd, ?int $excludeAppointmentId = null): array
    {
        $sql = "SELECT a.id, a.appointment_datetime, a.employee_id FROM appointments a
                WHERE a.status != 'Cancelled' AND DATE(a.appointment_datetime) = ?
                  AND (a.employee_id = ? OR EXISTS (SELECT 1 FROM appointment_services x WHERE x.appointment_id = a.id AND x.employee_id = ?))";
        $params = [$dateYmd, $employeeId, $employeeId];
        if ($excludeAppointmentId !== null) {
            $sql .= ' AND a.id != ?';
            $params[] = $excludeAppointmentId;
        }
        $stmt = $pdo->prepare($sql . ' FOR UPDATE');
        $stmt->execute($params);
        $appointments = $stmt->fetchAll();
        if (!$appointments) return [];

        $ids = array_column($appointments, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT aps.appointment_id, aps.employee_id, s.duration_minutes
            FROM appointment_services aps JOIN services s ON s.id = aps.service_id
            WHERE aps.appointment_id IN ($placeholders)
            ORDER BY aps.appointment_id, aps.id
        ");
        $stmt->execute($ids);
        $servicesByAppointment = [];
        foreach ($stmt->fetchAll() as $row) {
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
