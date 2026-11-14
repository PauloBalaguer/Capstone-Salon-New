<?php

require_once __DIR__ . '/../booking/Scheduling.php';

/**
 * Staff Portal attendance: manual Shift In, breaks and Shift Out.
 *
 * A shift is one `attendance` row (clock_in_time .. clock_out_time). Breaks
 * taken during it are rows in `attendance_breaks` (migration 058). Every
 * timestamp is MySQL's NOW() -- the same clock the rest of the attendance
 * code (Scheduling::autoCloseAttendance, cashier clock-out, EOD close) uses --
 * so the browser's clock is never authoritative. All durations are worked
 * out from full DATETIMEs, so a shift that crosses midnight adds up correctly.
 */
final class StaffShift
{
    public const DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    public const NO_CLOCKED_IN_MESSAGE = 'No stylists are clocked in at this branch. Ask a stylist to open the Staff Portal and tap Shift In, then refresh the staff list.';

    public static function branchHasClockedInStaff(PDO $pdo, int $branchId): bool
    {
        Scheduling::autoCloseBranchAttendance($pdo, $branchId);
        $stmt = $pdo->prepare('SELECT 1 FROM employees e JOIN attendance a ON a.employee_id = e.id
            WHERE e.branch_id = ? AND e.is_active = 1 AND a.clock_out_time IS NULL
              AND EXISTS (SELECT 1 FROM staff_services ss WHERE ss.employee_id = e.id) LIMIT 1');
        $stmt->execute([$branchId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * The five live statuses every panel shows (Admin, Staff, Cashier, Guest,
     * Customer). Compare on these stable keys; show statusLabel() to people.
     */
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_BUSY = 'busy';
    public const STATUS_BUSY_HOME = 'busy_home_service';
    public const STATUS_BREAK = 'on_break';
    public const STATUS_OFF = 'off_shift';

    /** key => [label, dot colour]. */
    public const STATUS_META = [
        self::STATUS_AVAILABLE => ['Available now', 'green'],
        self::STATUS_BUSY => ['Busy', 'blue'],
        self::STATUS_BUSY_HOME => ['Busy · Home service', 'blue'],
        self::STATUS_BREAK => ['On break', 'amber'],
        self::STATUS_OFF => ['Off shift', 'gray'],
    ];

    /** Statuses of someone clocked in at the salon (available, with a client, or on a break). */
    public const CLOCKED_IN_STATUSES = [self::STATUS_AVAILABLE, self::STATUS_BUSY, self::STATUS_BREAK];

    public static function statusLabel(?string $key): string
    {
        return self::STATUS_META[$key][0] ?? self::STATUS_META[self::STATUS_OFF][0];
    }

    public static function statusColor(?string $key): string
    {
        return self::STATUS_META[$key][1] ?? 'gray';
    }

    /** ['status' => key, 'statusLabel' => ..., 'statusColor' => ...] for JSON responses. */
    public static function statusPayload(?string $key): array
    {
        $key = isset(self::STATUS_META[$key]) ? $key : self::STATUS_OFF;
        return ['status' => $key, 'statusLabel' => self::statusLabel($key), 'statusColor' => self::statusColor($key)];
    }

    /**
     * SQL for one of a home service request's duration values (aliased $h):
     * its wedding package's row, else its event type's (or 'Other') row --
     * the same lookup as Scheduling::homeServiceDurations(). $expr uses "x."
     * for the duration row's columns.
     */
    private static function homeDurationSql(string $h, string $expr, int $default): string
    {
        $wp = str_replace('x.', 'ls_wp.', $expr);
        $ed = str_replace('x.', 'ls_ed.', $expr);
        return "COALESCE(CASE WHEN {$h}.event_type = 'Wedding'
                THEN (SELECT {$wp} FROM wedding_packages ls_wp WHERE ls_wp.package_name = CONCAT('Package ', {$h}.wedding_package) LIMIT 1)
                ELSE (SELECT {$ed} FROM event_type_durations ls_ed WHERE ls_ed.event_type IN ({$h}.event_type, 'Other') ORDER BY ls_ed.event_type = 'Other' LIMIT 1)
            END, {$default})";
    }

    /**
     * SQL condition: employee $e is away on a confirmed home visit right now
     * -- from leaving the salon (travel + setup before the start) until back
     * (service + return travel after it). A visit later today does not match
     * until its outbound travel starts; an unconfirmed or pre-assigned
     * request never matches.
     */
    public static function onHomeVisitSql(string $e): string
    {
        $start = "TIMESTAMP(ls_h.preferred_date, COALESCE(ls_h.preferred_time, '09:00:00'))";
        $lead = self::homeDurationSql('ls_h', 'COALESCE(x.travel_minutes, 0) + COALESCE(x.setup_minutes, 0)', 0);
        $default = Scheduling::HOME_SERVICE_DURATION_MINUTES;
        // return_travel_minutes (migration 062) is optional: before it, return = outbound travel.
        require_once __DIR__ . '/Schema.php';
        $return = Schema::hasColumn(Database::getInstance(), 'wedding_packages', 'return_travel_minutes')
            && Schema::hasColumn(Database::getInstance(), 'event_type_durations', 'return_travel_minutes')
            ? 'COALESCE(x.return_travel_minutes, x.travel_minutes, 0)' : 'COALESCE(x.travel_minutes, 0)';
        $tail = self::homeDurationSql('ls_h', "COALESCE(NULLIF(x.service_minutes, 0), {$default}) + {$return}", $default);
        return "EXISTS (SELECT 1 FROM home_service_requests ls_h
                WHERE (ls_h.employee_id = {$e}.id OR EXISTS (SELECT 1 FROM home_service_staff ls_t WHERE ls_t.home_service_request_id = ls_h.id AND ls_t.employee_id = {$e}.id))
                  AND ls_h.preferred_date BETWEEN CURDATE() - INTERVAL 1 DAY AND CURDATE() + INTERVAL 1 DAY
                  AND (ls_h.status = 'Service in Progress'
                       OR (ls_h.status IN ('Confirmed', 'Staff Assigned')
                           AND NOW() >= {$start} - INTERVAL ({$lead}) MINUTE
                           AND NOW() < {$start} + INTERVAL ({$tail}) MINUTE)))";
    }

    /**
     * The one live-status rule, as a SQL expression for the employees row
     * aliased $e, returning a STATUS_* key. Every panel uses this instead of
     * its own copy, so they can't disagree:
     *   away on a confirmed home visit (incl. travel)       -> busy_home_service
     *   no open attendance row (any day, like openShift())  -> off_shift
     *   open shift with a running attendance_breaks row     -> on_break
     *   with a client now: an In Progress booking, or a
     *   Confirmed one whose time window covers NOW(), as the
     *   main stylist or on a per-service part               -> busy
     *   otherwise                                           -> available
     * Everything is MySQL's NOW() (session time zone +08:00, Asia/Manila).
     */
    public static function liveStatusSql(string $e): string
    {
        $worksOn = "(ls_ap.employee_id = {$e}.id OR EXISTS (SELECT 1 FROM appointment_services ls_x WHERE ls_x.appointment_id = ls_ap.id AND ls_x.employee_id = {$e}.id))";
        return "(CASE
            WHEN " . self::onHomeVisitSql($e) . "
                THEN '" . self::STATUS_BUSY_HOME . "'
            WHEN NOT EXISTS (SELECT 1 FROM attendance ls_a WHERE ls_a.employee_id = {$e}.id AND ls_a.clock_out_time IS NULL)
                THEN '" . self::STATUS_OFF . "'
            WHEN EXISTS (SELECT 1 FROM attendance ls_a JOIN attendance_breaks ls_b ON ls_b.attendance_id = ls_a.id AND ls_b.break_end IS NULL
                         WHERE ls_a.employee_id = {$e}.id AND ls_a.clock_out_time IS NULL)
                THEN '" . self::STATUS_BREAK . "'
            WHEN EXISTS (SELECT 1 FROM appointments ls_ap WHERE {$worksOn} AND (
                    (ls_ap.status = 'In Progress' AND ls_ap.appointment_datetime >= CURDATE() - INTERVAL 1 DAY)
                    OR (ls_ap.status = 'Confirmed' AND NOW() >= ls_ap.appointment_datetime
                        AND NOW() < ls_ap.appointment_datetime + INTERVAL (
                            SELECT COALESCE(NULLIF(SUM(ls_s.duration_minutes), 0), 30)
                            FROM appointment_services ls_aps JOIN services ls_s ON ls_s.id = ls_aps.service_id
                            WHERE ls_aps.appointment_id = ls_ap.id
                        ) MINUTE)))
                THEN '" . self::STATUS_BUSY . "'
            ELSE '" . self::STATUS_AVAILABLE . "'
        END)";
    }

    /** Live status key of one employee (same rule as liveStatusSql()). */
    public static function liveStatus(PDO $pdo, int $employeeId): string
    {
        $stmt = $pdo->prepare('SELECT ' . self::liveStatusSql('e') . ' FROM employees e WHERE e.id = ?');
        $stmt->execute([$employeeId]);
        return (string) ($stmt->fetchColumn() ?: self::STATUS_OFF);
    }

    /**
     * The logged-in Staff account's employee row (with branch and account
     * status), or stops the request with 401/404 JSON.
     */
    public static function requireEmployee(PDO $pdo): array
    {
        if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Staff') {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please log in as a staff member.']);
            exit();
        }
        $stmt = $pdo->prepare('
            SELECT e.id, e.user_id, e.first_name, e.middle_name, e.last_name, e.phone_number, e.position, e.hire_date,
                   e.profile_picture, e.is_active, e.branch_id,
                   u.email, u.is_active AS user_active,
                   br.branch_name, br.branch_key, br.is_active AS branch_active
            FROM employees e
            JOIN users u ON u.id = e.user_id
            LEFT JOIN branches br ON br.id = e.branch_id
            WHERE e.user_id = ?
            LIMIT 1
        ');
        $stmt->execute([$_SESSION['user_id']]);
        $employee = $stmt->fetch();
        if (!$employee) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No staff profile is linked to this account.']);
            exit();
        }
        $employee['id'] = (int) $employee['id'];
        $employee['branch_id'] = $employee['branch_id'] !== null ? (int) $employee['branch_id'] : null;
        return $employee;
    }

    /** "EMP-0007": the employee number shown on the profile, from employees.id. */
    public static function employeeCode(int $employeeId): string
    {
        return 'EMP-' . str_pad((string) $employeeId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * SQL for the break seconds inside the attendance row aliased $a. A break
     * still running counts up to now; a break left open when the shift was
     * closed elsewhere (cashier, end-of-day, auto close) stops at clock-out.
     */
    public static function breakSecondsSql(string $a): string
    {
        $end = "COALESCE({$a}.clock_out_time, NOW())";
        return "(SELECT COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, b.break_start, LEAST(COALESCE(b.break_end, {$end}), {$end})))), 0)
                 FROM attendance_breaks b WHERE b.attendance_id = {$a}.id)";
    }

    /** SQL for the worked seconds (shift length minus breaks) of attendance row $a. */
    public static function workedSecondsSql(string $a): string
    {
        return "GREATEST(0, TIMESTAMPDIFF(SECOND, {$a}.clock_in_time, COALESCE({$a}.clock_out_time, NOW())) - " . self::breakSecondsSql($a) . ')';
    }

    /** Ends breaks left open on shifts that were already closed elsewhere. */
    public static function closeDanglingBreaks(PDO $pdo, int $employeeId): void
    {
        $pdo->prepare('
            UPDATE attendance_breaks b
            JOIN attendance a ON a.id = b.attendance_id
            SET b.break_end = GREATEST(b.break_start, a.clock_out_time)
            WHERE a.employee_id = ? AND a.clock_out_time IS NOT NULL AND b.break_end IS NULL
        ')->execute([$employeeId]);
    }

    /** The employee's open shift (any day, so one that crossed midnight is found), or null. */
    public static function openShift(PDO $pdo, int $employeeId, bool $forUpdate = false): ?array
    {
        $stmt = $pdo->prepare('SELECT id, clock_in_time FROM attendance WHERE employee_id = ? AND clock_out_time IS NULL
            ORDER BY clock_in_time DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$employeeId]);
        return $stmt->fetch() ?: null;
    }

    public static function openBreak(PDO $pdo, int $attendanceId, bool $forUpdate = false): ?array
    {
        $stmt = $pdo->prepare('SELECT id, break_start FROM attendance_breaks WHERE attendance_id = ? AND break_end IS NULL
            ORDER BY break_start DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$attendanceId]);
        return $stmt->fetch() ?: null;
    }

    /** Services this employee has In Progress (as main stylist or on a per-service part). */
    public static function servicesInProgress(PDO $pdo, int $employeeId): array
    {
        $stmt = $pdo->prepare("
            SELECT a.reference_code FROM appointments a
            WHERE a.status = 'In Progress'
              AND (a.employee_id = ? OR EXISTS (SELECT 1 FROM appointment_services aps WHERE aps.appointment_id = a.id AND aps.employee_id = ?))
            ORDER BY a.appointment_datetime
        ");
        $stmt->execute([$employeeId, $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** "2026-11-05 14:03:00" -> "2026-11-05T14:03:00+08:00" for the browser (display only). */
    public static function iso(?string $dateTime): ?string
    {
        return $dateTime ? str_replace(' ', 'T', $dateTime) . '+08:00' : null;
    }

    public static function timeLabel(?string $time): ?string
    {
        return $time ? date('g:i A', strtotime('2000-01-01 ' . substr($time, -8))) : null;
    }

    /**
     * The employee's week: their own staff_schedules rows where set,
     * otherwise their branch's operating hours (Sunday hours if configured).
     */
    public static function weeklySchedule(PDO $pdo, array $employee): array
    {
        $stmt = $pdo->prepare('SELECT day_of_week, start_time, end_time, is_rest_day FROM staff_schedules WHERE employee_id = ?');
        $stmt->execute([$employee['id']]);
        $custom = [];
        foreach ($stmt->fetchAll() as $row) {
            $custom[(int) $row['day_of_week']] = $row;
        }

        $today = new DateTimeImmutable($pdo->query('SELECT CURDATE()')->fetchColumn());
        $todayDow = (int) $today->format('N');
        $monday = $today->modify('-' . ($todayDow - 1) . ' days');

        $days = [];
        foreach (self::DAY_NAMES as $dow => $name) {
            $date = $monday->modify('+' . ($dow - 1) . ' days')->format('Y-m-d');
            if (isset($custom[$dow])) {
                $row = $custom[$dow];
                $rest = (bool) $row['is_rest_day'] || !$row['start_time'] || !$row['end_time'];
                $start = $rest ? null : $row['start_time'];
                $end = $rest ? null : $row['end_time'];
                $source = 'staff';
            } elseif ($employee['branch_key']) {
                [$start, $end] = Scheduling::operatingHours($employee['branch_key'], $date);
                $rest = false;
                $source = 'branch';
            } else {
                $start = $end = null;
                $rest = false;
                $source = 'none';
            }
            $days[] = [
                'dayOfWeek' => $dow,
                'dayName' => $name,
                'date' => $date,
                'isToday' => $dow === $todayDow,
                'isRestDay' => $rest,
                'start' => $start,
                'end' => $end,
                'startLabel' => self::timeLabel($start),
                'endLabel' => self::timeLabel($end),
                'source' => $source,
            ];
        }
        return $days;
    }

    /**
     * Everything the Today's Shift card needs, from the server's clock:
     * status (off / on / break), the open shift and break, today's totals,
     * and today's scheduled hours.
     */
    public static function state(PDO $pdo, array $employee, ?array $schedule = null): array
    {
        $employeeId = $employee['id'];
        self::closeDanglingBreaks($pdo, $employeeId);
        $now = $pdo->query('SELECT NOW()')->fetchColumn();
        $schedule = $schedule ?? self::weeklySchedule($pdo, $employee);
        $scheduleToday = null;
        foreach ($schedule as $day) {
            if ($day['isToday']) $scheduleToday = $day;
        }

        $shift = self::openShift($pdo, $employeeId);
        $state = [
            'status' => 'off',
            'serverNow' => self::iso($now),
            'attendanceId' => null,
            'clockIn' => null,
            'breakStart' => null,
            'breakSeconds' => 0,
            'workedSeconds' => 0,
            'breakCount' => 0,
            'scheduleToday' => $scheduleToday,
        ];

        if ($shift) {
            $stmt = $pdo->prepare('SELECT ' . self::breakSecondsSql('a') . ' AS breakSeconds, ' . self::workedSecondsSql('a') . ' AS workedSeconds,
                (SELECT COUNT(*) FROM attendance_breaks b WHERE b.attendance_id = a.id) AS breakCount
                FROM attendance a WHERE a.id = ?');
            $stmt->execute([$shift['id']]);
            $totals = $stmt->fetch();
            $break = self::openBreak($pdo, (int) $shift['id']);
            $state = array_merge($state, [
                'status' => $break ? 'break' : 'on',
                'attendanceId' => (int) $shift['id'],
                'clockIn' => self::iso($shift['clock_in_time']),
                'breakStart' => $break ? self::iso($break['break_start']) : null,
                'breakSeconds' => (int) $totals['breakSeconds'],
                'workedSeconds' => (int) $totals['workedSeconds'],
                'breakCount' => (int) $totals['breakCount'],
            ]);
        }

        // Today's totals across every shift that started today (normally
        // one), so the card still shows Time In / Time Out after Shift Out.
        $stmt = $pdo->prepare('SELECT MIN(a.clock_in_time) AS firstIn,
                MAX(a.clock_out_time) AS lastOut, SUM(a.clock_out_time IS NULL) AS stillOpen,
                SUM(' . self::workedSecondsSql('a') . ') AS workedSeconds,
                SUM(' . self::breakSecondsSql('a') . ') AS breakSeconds
            FROM attendance a WHERE a.employee_id = ? AND DATE(a.clock_in_time) = CURDATE()');
        $stmt->execute([$employeeId]);
        $todayRow = $stmt->fetch();
        $state['today'] = [
            'timeIn' => self::iso($todayRow['firstIn']),
            'timeOut' => (int) $todayRow['stillOpen'] ? null : self::iso($todayRow['lastOut']),
            'workedSeconds' => (int) $todayRow['workedSeconds'],
            'breakSeconds' => (int) $todayRow['breakSeconds'],
        ];
        // A shift carried over from yesterday counts towards "today" too.
        if ($shift && !$todayRow['firstIn']) {
            $state['today'] = [
                'timeIn' => $state['clockIn'],
                'timeOut' => null,
                'workedSeconds' => $state['workedSeconds'],
                'breakSeconds' => $state['breakSeconds'],
            ];
        }
        return $state;
    }
}
