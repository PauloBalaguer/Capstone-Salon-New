<?php

/**
 * Admin: set (or fix) the responsible branch of a home service request
 * (home_service_requests.branch_id, migration 062). Older requests have no
 * branch and show "Branch not set". The branch's hours, holidays and
 * home-service eligibility then apply to the visit; for a request that is
 * already Confirmed / Staff Assigned the change is refused if the visit no
 * longer fits, and the original branch is kept.
 *
 * POST id (reference), branchKey
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/booking/HomeServiceRequest.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin', 'Owner'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$reference = trim((string) ($_POST['id'] ?? ''));
$branchKey = trim((string) ($_POST['branchKey'] ?? ''));

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT id, branch_name FROM branches WHERE branch_key = ?');
    $stmt->execute([$branchKey]);
    $branch = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT id FROM home_service_requests WHERE reference_code = ?');
    $stmt->execute([$reference]);
    $requestId = (int) $stmt->fetchColumn();
    if (!$branch || !$requestId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request or branch not found.']);
        exit();
    }

    $pdo->beginTransaction();
    $request = HomeServiceRequest::lock($pdo, $requestId);
    if (in_array($request['status'], ['Cancelled', 'Completed'], true)) throw new RuntimeException('This request is closed.');
    $proposed = array_replace($request, ['branch_id' => (int) $branch['id']]);
    if ($problem = HomeServiceRequest::branchProblem($pdo, $proposed)) throw new RuntimeException($problem);
    $pdo->prepare('UPDATE home_service_requests SET branch_id = ? WHERE id = ?')->execute([$branch['id'], $requestId]);
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["Home service {$reference}: responsible branch set to {$branch['branch_name']}."]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => "Responsible branch set to {$branch['branch_name']}."]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('admin setHomeServiceBranch error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving the branch.']);
}
