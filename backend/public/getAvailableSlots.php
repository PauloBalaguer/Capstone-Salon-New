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

require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/Scheduling.php';
require_once '../config/PayMongo.php';

// php.ini on this XAMPP install is Europe/Berlin; "no slots in the past"
// must be judged on the salon's clock.
date_default_timezone_set('Asia/Manila');

sendCorsHeaders();
header('Content-Type: application/json');

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

    $durationMinutes = Scheduling::totalDurationMinutes($pdo, $serviceIds);
    $allSlots = Scheduling::timeSlots($branchKey);

    $slots = array_map(function ($slot) use ($pdo, $branchId, $branchKey, $date, $durationMinutes) {
        $slotTimestamp = strtotime("$date $slot");
        $slotDateTime = date('Y-m-d H:i:s', $slotTimestamp);
        $available = $slotTimestamp > time()
            && !Scheduling::isOutsideOperatingHours($slotDateTime, $durationMinutes, $branchKey)
            && !Scheduling::branchWindowIsFull($pdo, $branchId, $slotDateTime, $durationMinutes);
        return [
            'time' => $slot,
            'available' => $available,
        ];
    }, $allSlots);

    [$openingTime, $closingTime] = Scheduling::operatingHours($branchKey);
    echo json_encode(['success' => true, 'branchKey' => $branchKey, 'openingTime' => $openingTime, 'closingTime' => $closingTime, 'slots' => $slots]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getAvailableSlots error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading time slots.']);
}
