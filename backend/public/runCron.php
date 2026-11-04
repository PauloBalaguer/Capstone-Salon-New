<?php

/**
 * Lets Vercel Cron run the command-line workers in backend/shared/cron, which
 * a container has no scheduler for. Each job runs unchanged in its own PHP
 * process. Vercel sends "Authorization: Bearer <CRON_SECRET>"; with no secret
 * set this endpoint doesn't exist, so a normal XAMPP or hosting setup is unaffected.
 */

require_once __DIR__ . '/../shared/config/setup/env.php';

const CRON_JOBS = [
    'reminders' => 'sendAppointmentReminders.php',
    'emails'    => 'sendQueuedEmails.php',
    'sms'       => 'sendQueuedSms.php',
];

header('Content-Type: application/json');
header('Cache-Control: no-store');

$secret = env('CRON_SECRET');
$job = CRON_JOBS[$_GET['job'] ?? ''] ?? null;
if ($secret === '' || $job === null || !hash_equals("Bearer {$secret}", (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
    http_response_code(404);
    exit();
}

// PHP_BINARY can't be used here: under Apache it is empty or points to Apache itself.
$php = is_file(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
$script = dirname(__DIR__) . '/shared/cron/' . $job;
$process = proc_open([$php, $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
if (!is_resource($process)) {
    http_response_code(500);
    error_log("Cron {$job}: could not start PHP.");
    echo json_encode(['success' => false]);
    exit();
}

// The output can name customers, so it stays out of the response and the logs.
stream_get_contents($pipes[1]);
fclose($pipes[1]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    http_response_code(500);
    error_log("Cron {$job}: exited with code {$exitCode}.");
}
echo json_encode(['success' => $exitCode === 0]);
