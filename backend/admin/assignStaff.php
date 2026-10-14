<?php

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/Scheduling.php';
require_once '../config/StaffNotifier.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$referenceCode = trim($_POST['id'] ?? '');
$staffId = (int) ($_POST['staffId'] ?? 0);
// Home service teams: staffIds[] (several stylists). A single staffId still works.
$staffIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['staffIds'] ?? [])))));
if (!$staffIds && $staffId > 0) $staffIds = [$staffId];
if ($staffId <= 0 && $staffIds) $staffId = $staffIds[0];

if ($referenceCode === '' || $staffId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking reference and staff are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    // Resolve the public reference; internal keys stay inside the backend.
    $stmt = $pdo->prepare('SELECT id, status, preferred_date, preferred_time FROM home_service_requests WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$referenceCode]);
    $homeService = $stmt->fetch();
    if ($homeService !== false) {
        $homeServiceId = $homeService['id'];

        // A home service can have a whole team (home_service_staff); the
        // first stylist picked is the team lead (home_service_requests.employee_id).
        $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE id IN ($placeholders) AND is_active = 1");
        $stmt->execute($staffIds);
        $staffById = [];
        foreach ($stmt->fetchAll() as $row) $staffById[(int) $row['id']] = trim($row['first_name'] . ' ' . $row['last_name']);
        if (count($staffById) !== count($staffIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'One or more selected staff members are not available.']);
            exit();
        }

        $preferredTime = $homeService['preferred_time'] ?: '09:00:00';
        $busy = [];
        foreach ($staffIds as $id) {
            if (Scheduling::homeServiceStaffHasConflict($pdo, $id, $homeService['preferred_date'], $preferredTime, $homeServiceId)) {
                $busy[] = $staffById[$id];
            }
        }
        if ($busy) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => implode(', ', $busy) . (count($busy) === 1 ? ' already has' : ' already have') . ' an overlapping booking (in-salon or home service) around this date and time. Unselect them or pick others.']);
            exit();
        }

        // Assigning staff to a request that's already past quoting/payment
        // naturally advances it to "Staff Assigned" -- earlier-stage requests
        // (still under review, awaiting a quote or payment) keep their
        // current status; staff can be pre-assigned without forcing the flow.
        $nextStatus = $homeService['status'] === 'Confirmed' ? 'Staff Assigned' : $homeService['status'];

        $stmt = $pdo->prepare('SELECT employee_id FROM home_service_staff WHERE home_service_request_id = ?');
        $stmt->execute([$homeServiceId]);
        $previousTeam = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM home_service_staff WHERE home_service_request_id = ?')->execute([$homeServiceId]);
        $add = $pdo->prepare('INSERT INTO home_service_staff (home_service_request_id, employee_id) VALUES (?, ?)');
        foreach ($staffIds as $id) $add->execute([$homeServiceId, $id]);
        $pdo->prepare('UPDATE home_service_requests SET employee_id = ?, status = ? WHERE id = ?')
            ->execute([$staffIds[0], $nextStatus, $homeServiceId]);

        $teamNames = implode(', ', array_map(fn($id) => $staffById[$id], $staffIds));
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
            ->execute(["Team for home service request {$referenceCode}: {$teamNames}."]);
        foreach ($staffIds as $id) {
            if (!in_array($id, $previousTeam, true)) {
                StaffNotifier::notify($pdo, $id, 'HOME_SERVICE_ASSIGNED', "You've been assigned to home service request {$referenceCode} on "
                    . date('M j, Y', strtotime($homeService['preferred_date'])) . ' (team: ' . $teamNames . ').');
            }
        }
        foreach (array_diff($previousTeam, $staffIds) as $removedId) {
            StaffNotifier::notify($pdo, (int) $removedId, 'HOME_SERVICE_UNASSIGNED', "You've been removed from home service request {$referenceCode}.");
        }
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => count($staffIds) === 1 ? 'Staff assigned.' : count($staffIds) . ' staff assigned: ' . $teamNames . '.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT id, branch_id, appointment_datetime FROM appointments WHERE reference_code = ? LIMIT 1');
    $stmt->execute([$referenceCode]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE id = ? AND branch_id = ? AND is_active = 1');
    $stmt->execute([$staffId, $appointment['branch_id']]);
    $staff = $stmt->fetch();
    if (!$staff) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Selected staff member is not available at this branch.']);
        exit();
    }

    // Qualification: staff must cover every service on this booking.
    $stmt = $pdo->prepare('SELECT service_id FROM appointment_services WHERE appointment_id = ?');
    $stmt->execute([$appointment['id']]);
    $requiredServiceIds = array_map('intval', array_column($stmt->fetchAll(), 'service_id'));
    if (!empty($requiredServiceIds)) {
        $placeholders = implode(',', array_fill(0, count($requiredServiceIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT service_id) FROM staff_services WHERE employee_id = ? AND service_id IN ($placeholders)");
        $stmt->execute(array_merge([$staffId], $requiredServiceIds));
        if ((int) $stmt->fetchColumn() !== count($requiredServiceIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This staff member does not offer every service on this booking.']);
            exit();
        }
    }

    // Duration-aware time-conflict: don't double-book this stylist across any
    // overlapping window, not just the exact same start time. Broader than
    // the Confirmed-only priority check in updateBookingStatus.php -- this
    // mirrors submitBooking.php's booking-creation-time exclusion.
    $durationMinutes = Scheduling::totalDurationMinutes($pdo, $requiredServiceIds);

    $pdo->beginTransaction();

    if (Scheduling::staffHasConflict($pdo, $staffId, $appointment['appointment_datetime'], $durationMinutes, $appointment['id'])) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This staff member already has an overlapping booking at this date and time.']);
        exit();
    }

    $pdo->prepare('UPDATE appointments SET employee_id = ? WHERE id = ?')->execute([$staffId, $appointment['id']]);

    $staffName = trim($staff['first_name'] . ' ' . $staff['last_name']);
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["{$staffName} assigned to booking {$referenceCode}."]);
    StaffNotifier::notify($pdo, $staffId, 'APPOINTMENT_ASSIGNED', "You've been assigned to a new appointment: {$referenceCode}.");

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Staff assigned.']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('admin assignStaff error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while assigning staff.']);
}
