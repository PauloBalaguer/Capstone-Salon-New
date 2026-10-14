<?php

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';

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

$branchKey = trim($_POST['id'] ?? '');
$location = trim($_POST['location'] ?? '');
$type = trim($_POST['type'] ?? '');

// Operating hours / booking capacity are optional on this endpoint so the
// existing "Save Branch Info" (location/type only) call keeps working
// unchanged; Platform Settings' Operating Hours / Booking Capacity fields
// pass these too, in the same save.
$openingTime = trim($_POST['openingTime'] ?? '');
$closingTime = trim($_POST['closingTime'] ?? '');
// Sunday fields specifically: absent means "not part of this save" (keep
// whatever's configured); present-but-empty means "clear the Sunday
// override, use regular hours every day" -- these are different signals so
// isset() is checked separately for each once fields are read below.
$sundayOpeningTime = trim($_POST['sundayOpeningTime'] ?? '');
$sundayClosingTime = trim($_POST['sundayClosingTime'] ?? '');
$slotLimitRaw = trim($_POST['slotLimit'] ?? '');
$timePattern = '/^([01]\d|2[0-3]):[0-5]\d$/';

if ($branchKey === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid branch.']);
    exit();
}

if ($openingTime !== '' && !preg_match($timePattern, $openingTime)
    || $closingTime !== '' && !preg_match($timePattern, $closingTime)
    || $sundayOpeningTime !== '' && !preg_match($timePattern, $sundayOpeningTime)
    || $sundayClosingTime !== '' && !preg_match($timePattern, $sundayClosingTime)
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Operating hours must be in HH:MM format.']);
    exit();
}

$slotLimit = $slotLimitRaw === '' ? null : filter_var($slotLimitRaw, FILTER_VALIDATE_INT);
if ($slotLimitRaw !== '' && ($slotLimit === false || $slotLimit < 1)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking capacity (slot limit) must be a positive whole number.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id, branch_name, opening_time, closing_time, sunday_opening_time, sunday_closing_time, slot_limit FROM branches WHERE branch_key = ? LIMIT 1');
    $stmt->execute([$branchKey]);
    $branch = $stmt->fetch();
    if (!$branch) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Branch not found.']);
        exit();
    }

    $pdo->prepare('
        UPDATE branches SET location = ?, branch_type = ?,
            opening_time = ?, closing_time = ?,
            sunday_opening_time = ?, sunday_closing_time = ?,
            slot_limit = ?
        WHERE id = ?
    ')->execute([
        $location, $type,
        $openingTime !== '' ? $openingTime : $branch['opening_time'],
        $closingTime !== '' ? $closingTime : $branch['closing_time'],
        isset($_POST['sundayOpeningTime']) ? ($sundayOpeningTime !== '' ? $sundayOpeningTime : null) : $branch['sunday_opening_time'],
        isset($_POST['sundayClosingTime']) ? ($sundayClosingTime !== '' ? $sundayClosingTime : null) : $branch['sunday_closing_time'],
        $slotLimit !== null ? $slotLimit : $branch['slot_limit'],
        $branch['id'],
    ]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "SETTINGS", ?)')
        ->execute(["Branch info updated for {$branch['branch_name']}."]);

    echo json_encode(['success' => true, 'message' => 'Branch info updated.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin saveBranch error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while updating the branch.']);
}
