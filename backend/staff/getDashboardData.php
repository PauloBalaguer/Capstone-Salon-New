<?php

/**
 * Staff Dashboard Data API Endpoint
 *
 * Returns everything the Staff Portal (frontend/staff/dashboard.html) needs in
 * one request: the logged-in staff member's profile, their current shift
 * (StaffShift::state), their weekly schedule, every appointment assigned to
 * them, their attendance history with break and worked hours, their branch's
 * inventory (for logging supplies used on a service) and their notifications.
 * It also hands the page this session's CSRF token for its POST requests.
 * function/staff/shared-data.js calls this once on load and again after every
 * change to refresh the Alpine store.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/booking/Scheduling.php';
require_once '../../backend/shared/config/booking/ArrivalRequest.php';
require_once '../../backend/shared/config/helpers/StaffShift.php';
require_once '../../backend/shared/config/booking/HomeServiceRequest.php';
require_once '../../backend/shared/config/security/Csrf.php';

sendCorsHeaders();
header('Content-Type: application/json');

try {
    $pdo = Database::getInstance();
    // Staff only (401 otherwise), and only this account's own records.
    $employee = StaffShift::requireEmployee($pdo);
    $userId = $_SESSION['user_id'];
    $employeeId = $employee['id'];
    $branchId = $employee['branch_id'];

    // No cron/background worker exists in this project and browser-close
    // events are unreliable, so a shift left open past closing time is
    // settled here (checked on every dashboard load) -- see
    // Scheduling::autoCloseAttendance().
    Scheduling::autoCloseAttendance($pdo, $employeeId, $branchId);

    $profile = [
        'employeeId' => $employeeId,
        'employeeCode' => StaffShift::employeeCode($employeeId),
        'name' => implode(' ', array_filter([$employee['first_name'], $employee['middle_name'] ?? '', $employee['last_name']], fn($part) => $part !== '')),
        'firstName' => $employee['first_name'],
        'middleName' => $employee['middle_name'] ?? '',
        'lastName' => $employee['last_name'],
        'phone' => $employee['phone_number'],
        'profilePicture' => $employee['profile_picture'],
        'email' => $employee['email'],
        'position' => $employee['position'] ?: 'Staff',
        'role' => $_SESSION['user_role'],
        'accountStatus' => ((int) $employee['is_active'] && (int) $employee['user_active']) ? 'Active' : 'Inactive',
        'branchId' => $branchId,
        'branchName' => $employee['branch_name'] ?? 'Unassigned Branch',
        'branchKey' => $employee['branch_key'] ?? null,
        'hireDate' => $employee['hire_date'],
    ];

    $schedule = StaffShift::weeklySchedule($pdo, $employee);
    $shift = StaffShift::state($pdo, $employee, $schedule);

    $stmt = $pdo->prepare("
        SELECT
            a.reference_code AS id,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            c.phone_number AS clientPhone,
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS serviceName,
            DATE_FORMAT(a.appointment_datetime, '%Y-%m-%d') AS date,
            DATE_FORMAT(a.appointment_datetime, '%h:%i %p') AS time,
            a.appointment_datetime AS startDateTime,
            TIMESTAMPDIFF(MINUTE, a.appointment_datetime, NOW()) AS minutesLate,
            COALESCE(SUM(s.duration_minutes), 0) AS durationMinutes,
            a.status AS status,
            a.total_price AS price,
            br.branch_name AS branchName
        FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        LEFT JOIN branches br ON br.id = a.branch_id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services s ON s.id = aps.service_id
        WHERE a.employee_id = ? OR EXISTS (SELECT 1 FROM appointment_services mine WHERE mine.appointment_id = a.id AND mine.employee_id = ?)
        GROUP BY a.id
        ORDER BY a.appointment_datetime DESC
    ");
    $stmt->execute([$employeeId, $employeeId]);
    // Per-service stylists: services run back-to-back in booking order, each
    // by its own stylist (appointment_services.employee_id, NULL = the
    // booking's main stylist). Show this staff member only their part --
    // their services and their own time window -- plus who else is on it.
    $serviceRowsStmt = $pdo->prepare("
        SELECT aps.employee_id, a.employee_id AS main_id, s.service_name, s.duration_minutes,
               CONCAT(e.first_name, ' ', e.last_name) AS stylist
        FROM appointment_services aps
        JOIN appointments a ON a.id = aps.appointment_id
        JOIN services s ON s.id = aps.service_id
        LEFT JOIN employees e ON e.id = COALESCE(aps.employee_id, a.employee_id)
        WHERE a.reference_code = ?
        ORDER BY aps.id
    ");
    $arrivalRequests = ArrivalRequest::openToday($pdo);
    $appointments = array_map(function ($row) use ($serviceRowsStmt, $employeeId, $arrivalRequests) {
        $row['arrivalRequests'] = $arrivalRequests[$row['id']] ?? [];
        $row['minutesLate'] = (int) $row['minutesLate'];
        $row['price'] = (float) $row['price'];
        $row['durationMinutes'] = (int) $row['durationMinutes'];
        $start = new DateTime($row['startDateTime']);
        $endTime = (clone $start)->modify('+' . $row['durationMinutes'] . ' minutes');
        $row['endTime'] = $endTime->format('h:i A');
        $row['isMainStylist'] = true;
        $row['otherStylists'] = '';

        $serviceRowsStmt->execute([$row['id']]);
        $parts = $serviceRowsStmt->fetchAll();
        if ($parts) {
            $mainId = (int) $parts[0]['main_id'];
            $row['isMainStylist'] = $mainId === $employeeId;
            $cursor = clone $start;
            $mine = [];
            $myStart = null;
            $myEnd = null;
            $others = [];
            foreach ($parts as $part) {
                $minutes = (int) $part['duration_minutes'] > 0 ? (int) $part['duration_minutes'] : 30;
                $segmentEnd = (clone $cursor)->modify("+{$minutes} minutes");
                $doneBy = $part['employee_id'] !== null ? (int) $part['employee_id'] : $mainId;
                if ($doneBy === $employeeId) {
                    $mine[] = $part['service_name'];
                    $myStart = $myStart ?? clone $cursor;
                    $myEnd = $segmentEnd;
                } elseif ($part['stylist']) {
                    $others[$part['stylist']] = true;
                }
                $cursor = $segmentEnd;
            }
            if ($mine && count($mine) < count($parts)) {
                // Shared booking: narrow to this stylist's own services and window.
                $row['serviceName'] = implode(', ', $mine);
                $row['time'] = $myStart->format('h:i A');
                $row['endTime'] = $myEnd->format('h:i A');
                $row['durationMinutes'] = (int) (($myEnd->getTimestamp() - $myStart->getTimestamp()) / 60);
            }
            $row['otherStylists'] = implode(', ', array_keys($others));
        }
        unset($row['startDateTime']);
        return $row;
    }, $stmt->fetchAll());

    // Home visits this staff member is on (lead or team), once Confirmed or
    // later -- a pending or pre-assigned request is not shown as theirs yet.
    // Times include the reserved travel (leave by / back by).
    $stmt = $pdo->prepare("
        SELECT h.*, br.branch_name, CONCAT(c.first_name, ' ', c.last_name) AS client_name, c.phone_number AS client_phone
        FROM home_service_requests h
        JOIN customers c ON c.id = h.customer_id
        LEFT JOIN branches br ON br.id = h.branch_id
        WHERE h.status IN ('Confirmed', 'Staff Assigned', 'Service in Progress', 'Completed')
          AND (h.employee_id = ? OR EXISTS (SELECT 1 FROM home_service_staff t WHERE t.home_service_request_id = h.id AND t.employee_id = ?))
          AND h.preferred_date >= CURDATE() - INTERVAL 30 DAY
        ORDER BY h.preferred_date, h.preferred_time
    ");
    $stmt->execute([$employeeId, $employeeId]);
    $homeVisits = [];
    foreach ($stmt->fetchAll() as $h) {
        $window = Scheduling::homeServiceWindow($pdo, $h);
        $team = HomeServiceRequest::team($pdo, $h);
        $names = [];
        if ($team) {
            $teamStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM employees WHERE id IN (" . implode(',', array_fill(0, count($team), '?')) . ')');
            $teamStmt->execute($team);
            $names = $teamStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $homeVisits[] = [
            'id' => $h['reference_code'],
            'status' => $h['status'],
            'eventType' => $h['event_type'],
            'service' => HomeServiceRequest::serviceSummary($h),
            'branchName' => $h['branch_name'] ?: 'Branch not set',
            'date' => $h['preferred_date'],
            'time' => date('h:i A', strtotime($h['preferred_time'] ?: '09:00:00')),
            'leaveBy' => $window['start']->format('h:i A'),
            'backBy' => $window['occupiedEnd']->format('h:i A'),
            'address' => $h['address'],
            'clientName' => $h['client_name'],
            'clientPhone' => $h['client_phone'],
            'clients' => (int) $h['number_of_clients'],
            'team' => implode(', ', $names),
            'notes' => $h['requests'],
        ];
    }

    // Attendance history, newest first. Hours are worked out in SQL from the
    // full DATETIMEs (so a shift past midnight adds up) minus every break.
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            DATE_FORMAT(a.clock_in_time, '%Y-%m-%d') AS date,
            a.clock_in_time AS clockInAt,
            a.clock_out_time AS clockOutAt,
            a.notes,
            " . StaffShift::breakSecondsSql('a') . " AS breakSeconds,
            " . StaffShift::workedSecondsSql('a') . " AS workedSeconds,
            EXISTS (SELECT 1 FROM attendance_breaks ob WHERE ob.attendance_id = a.id AND ob.break_end IS NULL) AS onBreak
        FROM attendance a
        WHERE a.employee_id = ?
        ORDER BY a.clock_in_time DESC
        LIMIT 500
    ");
    $stmt->execute([$employeeId]);
    $attendance = array_map(function ($row) {
        $isOpen = $row['clockOutAt'] === null;
        $notes = (string) $row['notes'];
        if ($isOpen) {
            $status = (int) $row['onBreak'] ? 'On break' : 'On shift';
        } elseif ($notes === Scheduling::NOTE_AUTO_CLOSE) {
            $status = 'Auto clock-out';
        } elseif ($notes === Scheduling::NOTE_EOD_CLOSE) {
            $status = 'Closed at end of day';
        } elseif ($notes !== '') {
            $status = 'Clocked out by cashier';
        } else {
            $status = 'Completed';
        }
        return [
            'id' => (string) $row['id'],
            'date' => $row['date'],
            'clockIn' => StaffShift::iso($row['clockInAt']),
            'clockOut' => StaffShift::iso($row['clockOutAt']),
            'breakSeconds' => (int) $row['breakSeconds'],
            'workedSeconds' => (int) $row['workedSeconds'],
            'isOpen' => $isOpen,
            'status' => $status,
            'needsReview' => !$isOpen && $notes !== '',
        ];
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare("
        SELECT id, type, message, is_read AS isRead, DATE_FORMAT(created_at, '%M %e, %Y %h:%i %p') AS time
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $notifications = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['isRead'] = (bool) $row['isRead'];
        return $row;
    }, $stmt->fetchAll());

    $inventory = [];
    if ($branchId) {
        $stmt = $pdo->prepare("
            SELECT id, product_name AS name, sku, type, quantity_on_hand AS stock, reorder_level AS minQty
            FROM inventory
            WHERE branch_id = ? AND is_active = 1
            ORDER BY product_name ASC
        ");
        $stmt->execute([$branchId]);
        $inventory = array_map(function ($row) {
            $row['id'] = (string) $row['id'];
            $row['stock'] = (int) $row['stock'];
            $row['minQty'] = (int) $row['minQty'];
            return $row;
        }, $stmt->fetchAll());
    }

    // Live availability, from the same busy windows that block new bookings.
    $busyUntil = Scheduling::staffBusyUntilNow($pdo, $employeeId);
    $availability = [
        'busyUntil' => $busyUntil ? $busyUntil->format('g:i A') : null,
        'busyMinutesLeft' => $busyUntil
            ? (int) ceil(($busyUntil->getTimestamp() - (new DateTime($pdo->query('SELECT NOW()')->fetchColumn()))->getTimestamp()) / 60)
            : 0,
    ];

    echo json_encode([
        'success' => true,
        'csrfToken' => Csrf::token(),
        'profile' => $profile,
        'shift' => $shift,
        'schedule' => $schedule,
        'availability' => $availability,
        // Same five-status live source as Admin, Cashier and the booking forms.
        'liveStatus' => StaffShift::statusPayload(StaffShift::liveStatus($pdo, $employeeId)),
        'homeVisits' => $homeVisits,
        'lateGraceMinutes' => Scheduling::arrivalGraceMinutes(),
        'appointments' => $appointments,
        'attendance' => $attendance,
        'inventory' => $inventory,
        'notifications' => $notifications,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('employee getDashboardData error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading your dashboard.']);
}
