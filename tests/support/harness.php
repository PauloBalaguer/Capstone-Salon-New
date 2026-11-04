<?php
/**
 * Shared harness for the exception-case tests (see tests/README.md).
 *
 * clone_database() copies the dev database into a throwaway one (dropped
 * again when the test ends) and points this process at it; run_endpoint()
 * runs a real backend endpoint against it in a child PHP process
 * (support/endpoint.php). check() prints one PASS/FAIL line; finish()
 * exits 1 if anything failed. No API keys are needed: nothing is emailed,
 * texted or charged.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

const TEST_MYSQL = 'C:\\xampp\\mysql\\bin\\mysql.exe -uroot -P3307 -h127.0.0.1';
const TEST_DUMP = 'C:\\xampp\\mysql\\bin\\mysqldump.exe -uroot -P3307 -h127.0.0.1 --routines --triggers';
const DEV_DATABASE = 'new capstone_salon';

$failures = 0;
$testDb = '';

function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) $failures++;
}

function finish(): void
{
    global $failures;
    echo $failures ? "\n{$failures} check(s) failed.\n" : "\nAll checks passed.\n";
    exit($failures ? 1 : 0);
}

function clone_database(string $name): PDO
{
    global $testDb;
    $testDb = $name;
    $root = dirname(__DIR__, 2);
    passthru(TEST_MYSQL . " -e \"DROP DATABASE IF EXISTS {$name}; CREATE DATABASE {$name}\"");
    passthru(TEST_DUMP . ' "' . DEV_DATABASE . '" | ' . TEST_MYSQL . " {$name}", $code);
    if ($code !== 0) exit("Could not clone the database.\n");
    // Newer migrations may not be applied to the dev database yet; both are safe to run twice.
    foreach (['053_paymongo_separate_methods.sql', '054_event_durations.sql'] as $migration) {
        passthru(TEST_MYSQL . " {$name} < \"{$root}\\database\\migrations\\{$migration}\"");
    }
    register_shutdown_function(fn() => passthru(TEST_MYSQL . " -e \"DROP DATABASE IF EXISTS {$name}\""));

    putenv('SALON_SECRETS_DIR=' . dirname(__DIR__) . DIRECTORY_SEPARATOR . 'no-secrets');
    require_once $root . '/backend/shared/config/setup/database.php';
    require_once $root . '/backend/shared/config/notifications/EmailOutbox.php';
    (new ReflectionProperty(Database::class, 'db_name'))->setValue(null, $name);
    (new ReflectionProperty(EmailOutbox::class, 'hookRegistered'))->setValue(null, true);
    return Database::getInstance();
}

/** Starts an endpoint in a child process; pass the handle to endpoint_result(). */
function start_endpoint(array $spec): array
{
    global $testDb;
    $proc = proc_open([PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'endpoint.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($spec + ['db' => $testDb]));
    fclose($pipes[0]);
    return [$proc, $pipes];
}

/** Waits for the endpoint and returns its JSON response (or success=false with the raw output). */
function endpoint_result(array $handle): array
{
    [$proc, $pipes] = $handle;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : ['success' => false, 'message' => 'Not JSON: ' . trim($out . ' ' . $err)];
}

function run_endpoint(array $spec): array
{
    return endpoint_result(start_endpoint($spec));
}

/** Fixtures from the cloned dev data: two customer accounts, the admin, and branch 1's cashier. */
function test_people(PDO $pdo): array
{
    $customers = $pdo->query("SELECT c.id, c.user_id FROM customers c JOIN users u ON u.id = c.user_id JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'Customer' ORDER BY c.id LIMIT 2")->fetchAll();
    $admin = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.role_name = 'Admin' ORDER BY u.id LIMIT 1")->fetchColumn();
    $cashier = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.role_name = 'Cashier' AND u.branch_id = 1 ORDER BY u.id LIMIT 1")->fetchColumn();
    if (count($customers) < 2 || !$admin || !$cashier) exit("The dev database needs two customer accounts, an admin and a Daraga cashier.\n");
    $session = fn($row) => ['user_id' => (int) $row['user_id'], 'user_role' => 'Customer'];
    return [
        'customerA' => (int) $customers[0]['id'], 'sessionA' => $session($customers[0]),
        'customerB' => (int) $customers[1]['id'], 'sessionB' => $session($customers[1]),
        'admin' => ['user_id' => (int) $admin, 'user_role' => 'Admin'],
        'adminId' => (int) $admin,
        'cashier' => ['user_id' => (int) $cashier, 'user_role' => 'Cashier', 'branch_id' => 1],
    ];
}

/** A Monday-Saturday at least $fromDays ahead with no salon bookings at the branch and no events nearby. */
function free_day(PDO $pdo, int $fromDays, int $branchId = 1): string
{
    $busy = $pdo->prepare("SELECT (SELECT COUNT(*) FROM appointments WHERE branch_id = ? AND DATE(appointment_datetime) = ?)
        + (SELECT COUNT(*) FROM home_service_requests WHERE preferred_date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY))");
    for ($day = $fromDays; $day < $fromDays + 60; $day++) {
        $date = date('Y-m-d', strtotime("+{$day} days"));
        if (date('N', strtotime($date)) === '7') continue;
        $busy->execute([$branchId, $date, $date, $date]);
        if ((int) $busy->fetchColumn() === 0) return $date;
    }
    exit("No free test day found.\n");
}

function row(PDO $pdo, string $table, int $id): array
{
    return $pdo->query("SELECT * FROM {$table} WHERE id = {$id}")->fetch();
}

/** HH:MM that is $minutes after $time (HH:MM). */
function plus_minutes(string $time, int $minutes): string
{
    return date('H:i', strtotime("2000-01-01 {$time}") + $minutes * 60);
}
