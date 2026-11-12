<?php

require_once __DIR__ . '/Scheduling.php';
require_once __DIR__ . '/HomeServiceRequest.php';

/**
 * Staff leave (staff_leave) and branch holidays (branch_holidays), migration
 * 062. Before Admin saves either, preview() lists the confirmed salon
 * appointments and home visits it affects; on save, flag() marks them for
 * follow-up (needs_followup) WITHOUT moving, cancelling or reassigning them
 * -- Admin arranges any change with the customer.
 *
 * Booking checks themselves live in Scheduling::staffScheduleBlock() and
 * Scheduling::operatingHours() (holiday-aware).
 */
final class ScheduleExceptions
{
    const HOLIDAY_TYPES = ['closed' => 'Closed all day', 'special_hours' => 'Special hours', 'open' => 'Open as usual'];

    /** Confirmed bookings this leave would affect. */
    public static function affectedByLeave(PDO $pdo, int $employeeId, string $from, string $to): array
    {
        $stmt = $pdo->prepare("SELECT a.id, a.reference_code, a.appointment_datetime, CONCAT(c.first_name, ' ', c.last_name) AS client
            FROM appointments a JOIN customers c ON c.id = a.customer_id
            WHERE a.status = 'Confirmed' AND DATE(a.appointment_datetime) BETWEEN ? AND ?
              AND (a.employee_id = ? OR EXISTS (SELECT 1 FROM appointment_services x WHERE x.appointment_id = a.id AND x.employee_id = ?))
            ORDER BY a.appointment_datetime");
        $stmt->execute([$from, $to, $employeeId, $employeeId]);
        $affected = array_map(fn($r) => self::salonRow($r), $stmt->fetchAll());

        $stmt = $pdo->prepare("SELECT h.*, CONCAT(c.first_name, ' ', c.last_name) AS client
            FROM home_service_requests h JOIN customers c ON c.id = h.customer_id
            WHERE h.status IN ('Confirmed', 'Staff Assigned') AND h.preferred_date BETWEEN ? AND ?
              AND (h.employee_id = ? OR EXISTS (SELECT 1 FROM home_service_staff t WHERE t.home_service_request_id = h.id AND t.employee_id = ?))
            ORDER BY h.preferred_date, h.preferred_time");
        $stmt->execute([$from, $to, $employeeId, $employeeId]);
        foreach ($stmt->fetchAll() as $h) $affected[] = self::homeRow($pdo, $h);
        return $affected;
    }

    /**
     * Confirmed bookings a holiday would affect: every one that day when the
     * branch closes; with special hours, only those that no longer fit
     * (a home visit's whole occupied window, travel included).
     */
    public static function affectedByHoliday(PDO $pdo, int $branchId, string $date, string $type, ?string $open, ?string $close): array
    {
        if ($type === 'open') return [];
        $stmt = $pdo->prepare("SELECT a.id, a.reference_code, a.appointment_datetime, CONCAT(c.first_name, ' ', c.last_name) AS client,
                COALESCE(NULLIF((SELECT SUM(s.duration_minutes) FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = a.id), 0), 30) AS minutes
            FROM appointments a JOIN customers c ON c.id = a.customer_id
            WHERE a.status = 'Confirmed' AND a.branch_id = ? AND DATE(a.appointment_datetime) = ?
            ORDER BY a.appointment_datetime");
        $stmt->execute([$branchId, $date]);
        $affected = [];
        foreach ($stmt->fetchAll() as $r) {
            $start = new DateTime($r['appointment_datetime']);
            $end = (clone $start)->modify('+' . (int) $r['minutes'] . ' minutes');
            if ($type === 'closed' || !self::fits($start, $end, $date, $open, $close)) $affected[] = self::salonRow($r);
        }
        try {
            $stmt = $pdo->prepare("SELECT h.*, CONCAT(c.first_name, ' ', c.last_name) AS client
                FROM home_service_requests h JOIN customers c ON c.id = h.customer_id
                WHERE h.status IN ('Confirmed', 'Staff Assigned') AND h.branch_id = ? AND h.preferred_date = ?
                ORDER BY h.preferred_time");
            $stmt->execute([$branchId, $date]);
            foreach ($stmt->fetchAll() as $h) {
                // Home visits ignore salon hours, so only a full closure affects them.
                if ($type === 'closed') $affected[] = self::homeRow($pdo, $h);
            }
        } catch (PDOException $e) {
            // branch_id not added yet (migration 062): no home visits to list.
        }
        return $affected;
    }

    /**
     * Upcoming confirmed bookings of this stylist that would no longer fit a
     * proposed weekly schedule. $days: [{dayOfWeek, mode: branch|work|off,
     * start 'HH:MM', end 'HH:MM'}] (as staffSchedule.php saveWeek receives).
     * Salon: only this stylist's own service segments count. Home visit: the
     * whole occupied window (travel out to back at the salon). Leave and
     * holidays are separate and not re-checked here.
     */
    public static function affectedBySchedule(PDO $pdo, int $employeeId, array $days): array
    {
        $byDow = [];
        foreach ($days as $d) $byDow[(int) $d['dayOfWeek']] = $d;
        $stmt = $pdo->prepare('SELECT b.branch_key FROM employees e LEFT JOIN branches b ON b.id = e.branch_id WHERE e.id = ?');
        $stmt->execute([$employeeId]);
        $branchKey = $stmt->fetchColumn() ?: null;
        $fits = function (DateTime $start, DateTime $end) use ($byDow, $branchKey): bool {
            $date = $start->format('Y-m-d');
            $day = $byDow[(int) $start->format('N')] ?? ['mode' => 'branch'];
            if ($day['mode'] === 'off') return false;
            if ($day['mode'] === 'work') { $open = $day['start'] . ':00'; $close = $day['end'] . ':00'; }
            elseif ($branchKey) { [$open, $close] = Scheduling::operatingHours($branchKey, $date); }
            else return true;
            return self::fits($start, $end, $date, $open, $close);
        };

        $stmt = $pdo->prepare("SELECT a.id, a.reference_code, a.appointment_datetime, a.employee_id, CONCAT(c.first_name, ' ', c.last_name) AS client
            FROM appointments a JOIN customers c ON c.id = a.customer_id
            WHERE a.status = 'Confirmed' AND a.appointment_datetime >= NOW()
              AND (a.employee_id = ? OR EXISTS (SELECT 1 FROM appointment_services x WHERE x.appointment_id = a.id AND x.employee_id = ?))
            ORDER BY a.appointment_datetime");
        $stmt->execute([$employeeId, $employeeId]);
        $parts = $pdo->prepare('SELECT aps.employee_id, s.duration_minutes FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = ? ORDER BY aps.id');
        $affected = [];
        foreach ($stmt->fetchAll() as $r) {
            $parts->execute([$r['id']]);
            $rows = $parts->fetchAll() ?: [['employee_id' => null, 'duration_minutes' => Scheduling::DEFAULT_DURATION_MINUTES]];
            $cursor = new DateTime($r['appointment_datetime']);
            foreach ($rows as $row) {
                $minutes = (int) $row['duration_minutes'] > 0 ? (int) $row['duration_minutes'] : Scheduling::DEFAULT_DURATION_MINUTES;
                $end = (clone $cursor)->modify("+{$minutes} minutes");
                $who = $row['employee_id'] !== null ? (int) $row['employee_id'] : (int) $r['employee_id'];
                if ($who === $employeeId && !$fits($cursor, $end)) { $affected[] = self::salonRow($r); break; }
                $cursor = $end;
            }
        }

        $stmt = $pdo->prepare("SELECT h.*, CONCAT(c.first_name, ' ', c.last_name) AS client
            FROM home_service_requests h JOIN customers c ON c.id = h.customer_id
            WHERE h.status IN ('Confirmed', 'Staff Assigned') AND h.preferred_date >= CURDATE()
              AND (h.employee_id = ? OR EXISTS (SELECT 1 FROM home_service_staff t WHERE t.home_service_request_id = h.id AND t.employee_id = ?))
            ORDER BY h.preferred_date, h.preferred_time");
        $stmt->execute([$employeeId, $employeeId]);
        foreach ($stmt->fetchAll() as $h) {
            // Home visits ignore working hours; only a new day off affects them.
            $start = new DateTime($h['preferred_date'] . ' ' . $h['preferred_time']);
            if (($byDow[(int) $start->format('N')]['mode'] ?? 'branch') === 'off') $affected[] = self::homeRow($pdo, $h);
        }
        return $affected;
    }

    /** Marks each affected booking for follow-up; the booking itself is not changed. */
    public static function flag(PDO $pdo, array $affected, string $reason): void
    {
        $reason = mb_substr($reason, 0, 255);
        require_once __DIR__ . '/../helpers/Schema.php';
        if (!Schema::hasColumn($pdo, 'appointments', 'needs_followup') || !Schema::hasColumn($pdo, 'home_service_requests', 'needs_followup')) {
            return; // Migration 062 not applied yet: nothing to flag.
        }
        $salon = $pdo->prepare('UPDATE appointments SET needs_followup = ? WHERE id = ?');
        $home = $pdo->prepare('UPDATE home_service_requests SET needs_followup = ? WHERE id = ?');
        foreach ($affected as $row) {
            ($row['kind'] === 'salon' ? $salon : $home)->execute([$reason, $row['internalId']]);
        }
        if ($affected) {
            $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
                ->execute([count($affected) . ' confirmed booking(s) need follow-up: ' . $reason . ' (' . implode(', ', array_column($affected, 'reference')) . ').']);
        }
    }

    /** For JSON: drop internal ids. */
    public static function publicRows(array $affected): array
    {
        return array_map(fn($r) => array_diff_key($r, ['internalId' => 1]), $affected);
    }

    private static function fits(DateTime $start, DateTime $end, string $date, ?string $open, ?string $close): bool
    {
        if (!$open || !$close) return false;
        return $start >= new DateTime("$date $open") && $end <= new DateTime("$date $close");
    }

    private static function salonRow(array $r): array
    {
        return ['kind' => 'salon', 'type' => 'Salon appointment', 'internalId' => (int) $r['id'], 'reference' => $r['reference_code'],
            'when' => date('D, M j, Y g:i A', strtotime($r['appointment_datetime'])), 'client' => $r['client']];
    }

    private static function homeRow(PDO $pdo, array $h): array
    {
        $window = Scheduling::homeServiceWindow($pdo, $h);
        return ['kind' => 'home', 'type' => 'Home visit', 'internalId' => (int) $h['id'], 'reference' => $h['reference_code'],
            'when' => date('D, M j, Y g:i A', strtotime($h['preferred_date'] . ' ' . ($h['preferred_time'] ?: '09:00:00')))
                . ' (team away ' . $window['start']->format('g:i A') . '–' . $window['occupiedEnd']->format('g:i A') . ')',
            'client' => $h['client']];
    }
}
