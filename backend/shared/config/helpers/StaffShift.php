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
            SELECT e.id, e.user_id, e.first_name, e.last_name, e.phone_number, e.position, e.hire_date,
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
