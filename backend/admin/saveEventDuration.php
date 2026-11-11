<?php

/**
 * Admin: Save one event type's travel / setup / service minutes
 * (event_type_durations, migration 054). Used by Scheduling::homeServiceWindow()
 * to block the team for the real length of a home service or event.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';

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

$eventType = trim($_POST['eventType'] ?? '');
// Blank = not set; a blank service time falls back to 120 minutes.
$minutes = [];
// returnTravelMinutes (migration 062): blank = same as travelMinutes.
foreach (['travelMinutes' => 0, 'setupMinutes' => 0, 'serviceMinutes' => 1, 'returnTravelMinutes' => 0] as $field => $min) {
    $raw = trim($_POST[$field] ?? '');
    $minutes[$field] = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 1440]]);
    if ($minutes[$field] === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Travel, setup, service and return travel times must be whole minutes (up to 1440).']);
        exit();
    }
}
$durationsConfirmed = filter_var($_POST['durationsConfirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT 1 FROM event_type_durations WHERE event_type = ?');
    $stmt->execute([$eventType]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Event type not found.']);
        exit();
    }

    require_once __DIR__ . '/../../backend/shared/config/helpers/Schema.php';
    $pdo->prepare('
        UPDATE event_type_durations SET travel_minutes = ?, setup_minutes = ?, service_minutes = ?, durations_confirmed = ?
        WHERE event_type = ?
    ')->execute([$minutes['travelMinutes'], $minutes['setupMinutes'], $minutes['serviceMinutes'], $durationsConfirmed ? 1 : 0, $eventType]);
    // Return travel needs migration 062; skipped (blank = same as travel) until it is applied.
    if (Schema::hasColumn($pdo, 'event_type_durations', 'return_travel_minutes')) {
        $pdo->prepare('UPDATE event_type_durations SET return_travel_minutes = ? WHERE event_type = ?')->execute([$minutes['returnTravelMinutes'], $eventType]);
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "SETTINGS", ?)')
        ->execute(["Event durations updated for {$eventType}."]);

    echo json_encode(['success' => true, 'message' => 'Event durations saved.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin saveEventDuration error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving event durations.']);
}
