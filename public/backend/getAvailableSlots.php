<?php

/**
 * Public: Get Available Time Slots
 *
 * No login required — powers the time-slot picker on the public landing
 * page's guest booking form. A slot is unavailable if starting a booking
 * there would push the branch to Scheduling::slotLimit() concurrent
 * appointments at any point during the selected services' total duration,
 * or would run past closing time. Pass services[]=<service id> (repeatable)
 * once services are chosen so the check reflects their real duration;
 * without any, a flat 30-minute default is used.
 *
 * Branch may be identified either by its numeric id (branch=<id>, used by
 * the public landing page) or by its branch_key (branchKey=<key>, used by
 * the logged-in customer dashboard, which only knows branches by key).
 */

require_once '../../shared/backend/config/cors.php';
require_once '../../shared/backend/config/database.php';
require_once '../../shared/backend/config/Scheduling.php';
require_once '../../shared/backend/config/PayMongo.php';

// php.ini on this XAMPP install is Europe/Berlin; "no slots in the past"
// must be judged on the salon's clock.
date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
header('Content-Type: application/json');
require_once __DIR__ . '/../../shared/backend/config/BookingValidation.php';
BookingValidation::enforce($_GET, [
    'branch' => ['id', false, 'a staff or branch selection'],
    'date' => ['date', true, 'an appointment date'],
    'services' => ['ids', false, 'service or staff selections'],
]);



$branchId = (int) ($_GET['branch'] ?? 0);
$branchKey = trim($_GET['branchKey'] ?? '');
$date = trim($_GET['date'] ?? '');
$serviceIds = $_GET['services'] ?? [];
if (!is_array($serviceIds)) {
    $serviceIds = [$serviceIds];
}
$serviceIds = array_values(array_filter(array_map('intval', $serviceIds)));

if (($branchId <= 0 && $branchKey === '') || $date === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A branch and date are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    PayMongo::releaseExpiredHolds($pdo); // Free slots held by abandoned online payments.

    if ($branchId <= 0 && $branchKey !== '') {
        $stmt = $pdo->prepare('SELECT id, branch_key FROM branches WHERE branch_key = ? LIMIT 1');
        $stmt->execute([$branchKey]);
        $branch = $stmt->fetch();
        $branchId = (int) ($branch['id'] ?? 0);
        if ($branchId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
            exit();
        }
        $branchKey = $branch['branch_key'];
    } else {
        $stmt = $pdo->prepare('SELECT branch_key FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$branchId]);
        $branchKey = (string) $stmt->fetchColumn();
        if ($branchKey === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
            exit();
        }
    }

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
    $allSlots = Scheduling::timeSlots($branchKey, $date);

    // Customer booking assigns one stylist to the complete selection; guest booking may assign per service.
    $singleStaff = ($_GET['assignment'] ?? '') === 'single';
    $singleCandidates = [];
    if ($singleStaff && $serviceIds) {
        $marks = implode(',', array_fill(0, count($serviceIds), '?'));
        $qualified = $pdo->prepare("SELECT e.id FROM employees e WHERE e.branch_id = ? AND e.is_active = 1 AND (SELECT COUNT(DISTINCT ss.service_id) FROM staff_services ss WHERE ss.employee_id = e.id AND ss.service_id IN ($marks)) = ?");
        $qualified->execute([$branchId, ...$serviceIds, count($serviceIds)]);
        $singleCandidates = $qualified->fetchAll(PDO::FETCH_COLUMN);
    }
    $slots = array_map(function ($slot) use ($pdo, $branchId, $branchKey, $date, $durationMinutes, $serviceIds, $singleStaff, $singleCandidates) {
        $slotTimestamp = strtotime("$date $slot");
        $slotDateTime = date('Y-m-d H:i:s', $slotTimestamp);
        $available = $slotTimestamp > time()
            && !Scheduling::isOutsideOperatingHours($slotDateTime, $durationMinutes, $branchKey)
            && !Scheduling::branchWindowIsFull($pdo, $branchId, $slotDateTime, $durationMinutes);
        if ($available && $singleStaff) {
            $available = false;
            foreach ($singleCandidates as $employeeId) {
                if (!Scheduling::staffHasConflict($pdo, (int) $employeeId, $slotDateTime, $durationMinutes)) { $available = true; break; }
            }
        }
        // Each service needs a qualified free stylist for its own segment,
        // matching the guest form's per-service assignment.
        $cursor = new DateTime($slotDateTime);
        foreach ($singleStaff ? [] : $serviceIds as $serviceId) {
            if (!$available) break;
            $minutes = Scheduling::totalDurationMinutes($pdo, [$serviceId]);
            $staff = $pdo->prepare('SELECT e.id FROM employees e JOIN staff_services ss ON ss.employee_id = e.id WHERE ss.service_id = ? AND e.branch_id = ? AND e.is_active = 1');
            $staff->execute([$serviceId, $branchId]);
            $free = false;
            foreach ($staff->fetchAll() as $employee) {
                if (!Scheduling::staffHasConflict($pdo, (int) $employee['id'], $cursor->format('Y-m-d H:i:s'), $minutes)) {
                    $free = true;
                    break;
                }
            }
            $available = $free;
            $cursor->modify("+{$minutes} minutes");
        }
        return [
            'time' => $slot,
            'available' => $available,
        ];
    }, $allSlots);

    [$openingTime, $closingTime] = Scheduling::operatingHours($branchKey, $date);
    echo json_encode(['success' => true, 'branchKey' => $branchKey, 'openingTime' => $openingTime, 'closingTime' => $closingTime, 'slots' => $slots]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getAvailableSlots error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading time slots.']);
}
