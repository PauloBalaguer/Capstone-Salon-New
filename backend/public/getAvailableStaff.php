<?php

/**
 * Public: Get Available Staff
 *
 * No login required powers the required "Select Staff" step of the guest
 * booking flow (after branch/services/date/time are chosen, before guest
 * details). Returns only staff who are active at the branch, can perform
 * every selected service (staff_services), and have no overlapping
 * appointment during the selected window. Mirrors the qualification and
 * conflict checks already used by admin/assignStaff.php,
 * cashier/assignStaff.php, and submitGuestBooking.php's auto-assignment
 * see backend/shared/config/booking/Scheduling.php for the shared duration/overlap logic.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/booking/Scheduling.php';
require_once '../../backend/shared/config/helpers/StaffShift.php';
require_once '../../backend/shared/config/payments/PayMongo.php';

sendCorsHeaders();
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/shared/config/booking/BookingValidation.php';
BookingValidation::enforce($_GET, [
    'branch' => ['id', false, 'a staff or branch selection'],
    'date' => ['date', true, 'an appointment date'],
    'time' => ['time', true, 'an appointment time'],
    'services' => ['ids', true, 'service or staff selections'],
]);



$branchId = (int) ($_GET['branch'] ?? 0);
$branchKey = trim($_GET['branchKey'] ?? '');
$date = trim($_GET['date'] ?? '');
$time = trim($_GET['time'] ?? '');
$serviceIds = $_GET['services'] ?? [];
if (!is_array($serviceIds)) {
    $serviceIds = [$serviceIds];
}
$serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), fn($id) => $id > 0)));

if (($branchId <= 0 && $branchKey === '') || $date === '' || $time === '' || empty($serviceIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A branch, date, time, and at least one service are required.']);
    exit();
}

$appointmentTimestamp = strtotime("$date $time");
if ($appointmentTimestamp === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date or time selected.']);
    exit();
}
$appointmentDateTime = date('Y-m-d H:i:s', $appointmentTimestamp);

try {
    $pdo = Database::getInstance();
    PayMongo::releaseExpiredHolds($pdo);
    if ($branchId <= 0 && $branchKey !== '') {
        $branchLookup = $pdo->prepare('SELECT id FROM branches WHERE branch_key = ? LIMIT 1');
        $branchLookup->execute([$branchKey]);
        $branchId = (int) $branchLookup->fetchColumn();
    }

    $stmt = $pdo->prepare('SELECT id FROM branches WHERE id = ?');
    $stmt->execute([$branchId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }

    $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare("
        SELECT e.id, e.first_name, e.last_name, e.position
        FROM employees e
        WHERE e.branch_id = ? AND e.is_active = 1
          AND (SELECT COUNT(DISTINCT service_id) FROM staff_services WHERE employee_id = e.id AND service_id IN ($servicePlaceholders)) = ?
        ORDER BY e.first_name, e.last_name
    ");
    $stmt->execute([$branchId, ...$serviceIds, count($serviceIds)]);
    $candidates = $stmt->fetchAll();

    if ($serviceIds) {
        $marks = implode(',', array_fill(0, count($serviceIds), '?'));
        $selected = $pdo->prepare("SELECT COUNT(*) FROM services WHERE branch_id = ? AND is_active = 1 AND id IN ($marks)");
        $selected->execute([$branchId, ...$serviceIds]);
        if ((int) $selected->fetchColumn() !== count($serviceIds)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Please select active services offered at this branch.', 'errors' => ['services' => 'One or more selected services are unavailable at this branch.']]);
            exit();
        }
    }

    $durationMinutes = Scheduling::totalDurationMinutes($pdo, $serviceIds);

    // Every qualified, active stylist at the branch is listed -- including
    // anyone off shift right now -- with two separate things:
    //   currentShift*      their live status now (StaffShift, five statuses)
    //   available/message  whether the WHOLE service fits them at the chosen
    //                      date and time ("Available tomorrow at 10:00 AM",
    //                      "Day off", "On leave", "Booked until 3:30 PM",
    //                      "Unavailable at this time").
    // Advance bookings only need the stylist scheduled then; being off shift
    // now never blocks a later booking. 'busy' (unavailable ones) still feeds
    // the waitlist offer (backend/public/joinWaitlist.php).
    // Branch-level reason shown above the staff list, when there is one.
    $notice = null;
    $branchKeyNow = Scheduling::branchKeyForId($pdo, $branchId);
    if ($branchKeyNow !== null) {
        if (Scheduling::isBranchClosed($branchKeyNow, substr($appointmentDateTime, 0, 10))) $notice = 'The branch is closed on this date (holiday).';
        elseif (Scheduling::isOutsideOperatingHours($appointmentDateTime, $durationMinutes, $branchKeyNow)) $notice = 'The full service does not fit the branch hours at this time.';
    }
    $isPast = $pdo->query('SELECT ' . $pdo->quote($appointmentDateTime) . ' <= NOW()')->fetchColumn();
    $describe = function (array $e, string $start, int $minutes) use ($pdo, $isPast, $notice) {
        $live = StaffShift::liveStatus($pdo, (int) $e['id']);
        if ($isPast) $check = ['available' => false, 'code' => 'past', 'message' => Scheduling::BLOCK_MESSAGES['past'], 'busyUntil' => null];
        elseif ($notice !== null) $check = ['available' => false, 'code' => 'outside_hours', 'message' => Scheduling::BLOCK_MESSAGES['outside_hours'], 'busyUntil' => null];
        else $check = Scheduling::staffAvailability($pdo, (int) $e['id'], $start, $minutes);
        return [
            'id' => (string) $e['id'],
            'name' => trim($e['first_name'] . ' ' . $e['last_name']),
            'role' => $e['position'] ?: '',
            'currentShift' => $live,
            'currentShiftLabel' => StaffShift::statusLabel($live),
            'currentShiftColor' => StaffShift::statusColor($live),
            'available' => $check['available'],
            'status' => $check['available'] ? 'Available' : 'Unavailable',
            'availabilityCode' => $check['code'],
            'availabilityMessage' => $check['message'],
            'busyUntil' => $check['busyUntil'],
        ];
    };
    $staff = [];
    $busy = [];
    foreach ($candidates as $candidate) {
        $entry = $describe($candidate, $appointmentDateTime, $durationMinutes);
        if ($entry['available']) $staff[] = $entry; else $busy[] = $entry;
    }

    // Per-service view (one stylist per service): the services run
    // back-to-back in the order given, so each gets its own time window and
    // its own list of qualified stylists -- every one of them, flagged
    // available or "busy until" so the guest sees who is still with another
    // client instead of them silently disappearing.
    $stmt = $pdo->prepare("SELECT id, service_name, duration_minutes FROM services WHERE branch_id = ? AND id IN ($servicePlaceholders) AND is_active = 1");
    $stmt->execute([$branchId, ...$serviceIds]);
    $serviceRows = [];
    foreach ($stmt->fetchAll() as $row) $serviceRows[(int) $row['id']] = $row;

    $qualifiedStmt = $pdo->prepare("
        SELECT e.id, e.first_name, e.last_name, e.position
        FROM employees e JOIN staff_services ss ON ss.employee_id = e.id AND ss.service_id = ?
        WHERE e.branch_id = ? AND e.is_active = 1
        ORDER BY e.first_name, e.last_name
    ");
    $perService = [];
    $cursor = new DateTime($appointmentDateTime);
    foreach ($serviceIds as $serviceId) {
        if (!isset($serviceRows[$serviceId])) continue;
        $minutes = (int) $serviceRows[$serviceId]['duration_minutes'] > 0 ? (int) $serviceRows[$serviceId]['duration_minutes'] : Scheduling::DEFAULT_DURATION_MINUTES;
        $segmentStart = clone $cursor;
        $segmentEnd = (clone $cursor)->modify("+{$minutes} minutes");
        $qualifiedStmt->execute([$serviceId, $branchId]);
        $segment = $segmentStart->format('Y-m-d H:i:s');
        $options = array_map(fn($e) => $describe($e, $segment, $minutes), $qualifiedStmt->fetchAll());
        $perService[] = [
            'id' => (string) $serviceId,
            'name' => $serviceRows[$serviceId]['service_name'],
            'durationMinutes' => $minutes,
            'start' => $segmentStart->format('h:i A'),
            'end' => $segmentEnd->format('h:i A'),
            'staff' => $options,
        ];
        $cursor = $segmentEnd;
    }

    echo json_encode(['success' => true, 'staff' => $staff, 'busy' => $busy, 'services' => $perService, 'notice' => $notice]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getAvailableStaff error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading available staff.']);
}
