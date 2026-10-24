<?php

require_once '../../shared/backend/config/setup/cors.php';
require_once '../../shared/backend/config/setup/session.php';
require_once '../../shared/backend/config/setup/database.php';
require_once '../../shared/backend/config/security/AuditLog.php';
require_once '../../shared/backend/config/booking/Scheduling.php';
require_once '../../shared/backend/config/booking/HomeServiceRequest.php';
require_once '../../shared/backend/config/notifications/StaffNotifier.php';

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

require_once __DIR__ . '/../../shared/backend/config/booking/BookingValidation.php';
BookingValidation::enforce($_POST, [
    'id' => ['text', true, 'id'],
    'staffId' => ['id', false, 'a staff or branch selection'],
    'staffIds' => ['ids', false, 'service or staff selections'],
]);

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
        $pdo->beginTransaction();
        $homeService = HomeServiceRequest::lock($pdo, (int) $homeServiceId);
        if (in_array($homeService['status'], ['Cancelled','Completed','Service in Progress'], true)) throw new RuntimeException('This request can no longer be reassigned.');
        HomeServiceRequest::requireAvailable($pdo, $homeService, $staffIds);

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

        // Assigning staff to a request that's already past quoting/payment
        // naturally advances it to "Staff Assigned" -- earlier-stage requests
        // (still under review, awaiting a quote or payment) keep their
        // current status; staff can be pre-assigned without forcing the flow.
        $nextStatus = $homeService['status'] === 'Confirmed' ? 'Staff Assigned' : $homeService['status'];

        $stmt = $pdo->prepare('SELECT employee_id FROM home_service_staff WHERE home_service_request_id = ?');
        $stmt->execute([$homeServiceId]);
        $previousTeam = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

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

    $pdo->beginTransaction();
    $appointment = Scheduling::lockAppointment($pdo, (int) $appointment['id']);
    Scheduling::validateAppointmentStaff($pdo, $appointment, $appointment['appointment_datetime'], $staffId);
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM employees WHERE id = ?');
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch();

    $pdo->prepare('UPDATE appointments SET employee_id = ? WHERE id = ?')->execute([$staffId, $appointment['id']]);

    $staffName = trim($staff['first_name'] . ' ' . $staff['last_name']);
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["{$staffName} assigned to booking {$referenceCode}."]);
    StaffNotifier::notify($pdo, $staffId, 'APPOINTMENT_ASSIGNED', "You've been assigned to a new appointment: {$referenceCode}.");

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Staff assigned.']);
} catch (PDOException | RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!($e instanceof PDOException) || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? Scheduling::STAFF_UNAVAILABLE_MESSAGE : $e->getMessage()]);
        exit();
    }
    http_response_code(500);
    error_log('admin assignStaff error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while assigning staff.']);
}
