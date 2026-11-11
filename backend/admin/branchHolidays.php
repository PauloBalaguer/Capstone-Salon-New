<?php

/**
 * Admin: branch-specific holidays (branch_holidays, migration 062). Not the
 * cashier end-of-day lock (daily_closures).
 *
 * GET  ?branchKey=daraga                       -> { holidays[] } (from 7 days ago)
 * POST action=preview  branchKey, date, type, openTime, closeTime
 * POST action=save     branchKey, date, type (closed|special_hours|open), openTime, closeTime, name, confirmed=1
 * POST action=delete   holidayId
 *
 * Saving never moves or cancels a booking: confirmed salon appointments and
 * home visits that no longer fit are listed first, then flagged for
 * follow-up (needs_followup) with their original details kept.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/booking/ScheduleExceptions.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin', 'Owner'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

try {
    $pdo = Database::getInstance();
    $branchKey = trim((string) ($_GET['branchKey'] ?? $_POST['branchKey'] ?? ''));
    $stmt = $pdo->prepare('SELECT id, branch_name FROM branches WHERE branch_key = ?');
    $stmt->execute([$branchKey]);
    $branch = $stmt->fetch();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$branch) fail(404, 'Branch not found.');
        $stmt = $pdo->prepare("SELECT id, DATE_FORMAT(holiday_date, '%Y-%m-%d') AS date, holiday_type AS type,
                TIME_FORMAT(open_time, '%H:%i') AS openTime, TIME_FORMAT(close_time, '%H:%i') AS closeTime, name
            FROM branch_holidays WHERE branch_id = ? AND holiday_date >= CURDATE() - INTERVAL 7 DAY ORDER BY holiday_date");
        $stmt->execute([$branch['id']]);
        $rows = array_map(fn($r) => $r + ['typeLabel' => ScheduleExceptions::HOLIDAY_TYPES[$r['type']] ?? $r['type']], $stmt->fetchAll());
        echo json_encode(['success' => true, 'holidays' => $rows]);
        exit();
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Invalid request method.');

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM branch_holidays WHERE id = ?');
        $stmt->execute([(int) ($_POST['holidayId'] ?? 0)]);
        if (!$stmt->rowCount()) fail(404, 'Holiday not found.');
        echo json_encode(['success' => true, 'message' => 'Holiday removed. The branch uses its normal hours that day.']);
        exit();
    }

    if (!$branch) fail(404, 'Branch not found.');
    $date = trim((string) ($_POST['date'] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) fail(400, 'Please choose a valid date.');
    $type = (string) ($_POST['type'] ?? '');
    if (!isset(ScheduleExceptions::HOLIDAY_TYPES[$type])) fail(400, 'Please choose Closed all day, Special hours or Open as usual.');
    $open = $close = null;
    if ($type === 'special_hours') {
        $open = trim((string) ($_POST['openTime'] ?? ''));
        $close = trim((string) ($_POST['closeTime'] ?? ''));
        $pattern = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!preg_match($pattern, $open) || !preg_match($pattern, $close) || $open >= $close) fail(400, 'Special hours need an opening time before the closing time.');
        $open .= ':00';
        $close .= ':00';
    }
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);

    if ($action === 'preview') {
        $affected = ScheduleExceptions::affectedByHoliday($pdo, (int) $branch['id'], $date, $type, $open, $close);
        echo json_encode(['success' => true, 'affected' => ScheduleExceptions::publicRows($affected)]);
        exit();
    }
    if ($action !== 'save') fail(400, 'Unknown action.');

    $pdo->beginTransaction();
    // Same lock booking creation takes first, so the list can't change underneath.
    $pdo->prepare('SELECT id FROM branches WHERE id = ? FOR UPDATE')->execute([$branch['id']]);
    $affected = ScheduleExceptions::affectedByHoliday($pdo, (int) $branch['id'], $date, $type, $open, $close);
    if ($affected && !filter_var($_POST['confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'needsConfirmation' => true, 'affected' => ScheduleExceptions::publicRows($affected),
            'message' => 'Review the affected bookings before saving this holiday.']);
        exit();
    }
    $pdo->prepare('INSERT INTO branch_holidays (branch_id, holiday_date, holiday_type, open_time, close_time, name, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE holiday_type = VALUES(holiday_type), open_time = VALUES(open_time), close_time = VALUES(close_time), name = VALUES(name)')
        ->execute([$branch['id'], $date, $type, $open, $close, $name !== '' ? $name : null, $_SESSION['user_id'] ?? null]);
    $label = ScheduleExceptions::HOLIDAY_TYPES[$type] . ($type === 'special_hours' ? ' ' . substr($open, 0, 5) . '-' . substr($close, 0, 5) : '');
    ScheduleExceptions::flag($pdo, $affected, "{$branch['branch_name']} {$label} on {$date}" . ($name !== '' ? " ({$name})" : '') . ' - contact the customer');
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "SETTINGS", ?)')
        ->execute(["Holiday saved for {$branch['branch_name']} on {$date}: {$label}."]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Holiday saved.' . ($affected ? ' ' . count($affected) . ' booking(s) flagged for follow-up.' : '')]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('admin branchHolidays error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Has migration 062 been applied?']);
}
