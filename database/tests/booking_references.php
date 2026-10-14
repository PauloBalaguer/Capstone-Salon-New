<?php
// CLI integration test. Uses an isolated, disposable database; no real bookings.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/config/database.php';

function connectTest(string $name): PDO {
    return new PDO('mysql:host=localhost;dbname=' . $name . ';charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function migrate(PDO $pdo): void {
    $delimiter = ';'; $sql = '';
    foreach (file(__DIR__ . '/../migrations/024_booking_references.sql') as $line) {
        if (preg_match('/^DELIMITER (.+)/', trim($line), $match)) { $delimiter = $match[1]; continue; }
        if (str_starts_with(trim($line), '--')) continue;
        $sql .= $line;
        if (str_ends_with(trim($sql), $delimiter)) {
            $pdo->exec(substr(trim($sql), 0, -strlen($delimiter))); $sql = '';
        }
    }
}
function launch(array $arguments): array {
    $process = proc_open([PHP_BINARY, __FILE__, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$process, $pipes];
}
function finish(array $job): string {
    [$process, $pipes] = $job;
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && $error === '', 'Worker error: ' . $error . $output);
    return $output;
}
function endpoint(string $db, string $path, array $data = [], string $role = 'Customer'): array {
    $output = finish(launch(['endpoint', $db, $path, base64_encode(json_encode($data)), $role]));
    $result = json_decode($output, true);
    check(is_array($result), 'Invalid endpoint response: ' . $output);
    return $result;
}
if (($argv[1] ?? '') === 'endpoint') {
    check((bool) preg_match('/^capstone_ref_test_[a-f0-9]+$/', $argv[2]), 'Unsafe test database');
    $property = new ReflectionProperty(Database::class, 'instance');
    $property->setAccessible(true); $property->setValue(null, connectTest($argv[2]));
    require_once __DIR__ . '/../../backend/config/session.php';
    $_SESSION = ['user_id' => 1, 'user_role' => $argv[5], 'branch_id' => 1];
    register_shutdown_function(function () { session_destroy(); });
    $_POST = $_GET = json_decode(base64_decode($argv[4]), true);
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $file = realpath(__DIR__ . '/../../backend/' . $argv[3]);
    chdir(dirname($file)); require $file; exit;
}
if (($argv[1] ?? '') === 'concurrent') {
    check((bool) preg_match('/^capstone_ref_test_[a-f0-9]+$/', $argv[2]), 'Unsafe test database');
    $pdo = connectTest($argv[2]);
    for ($i = 0; $i < 10; $i++) {
        $pdo->exec("INSERT INTO appointments (customer_id, branch_id, appointment_datetime) VALUES (1, 1, '2027-01-01 09:00:00')");
    }
    exit;
}

$source = Database::getInstance();
$name = 'capstone_ref_test_' . bin2hex(random_bytes(5));
$source->exec("CREATE DATABASE `$name`");
try {
    $pdo = connectTest($name);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($source->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $table) {
        if ($table[0] === 'booking_reference_registry') continue;
        $definition = $source->query('SHOW CREATE TABLE `' . $table[0] . '`')->fetch(PDO::FETCH_NUM)[1];
        $pdo->exec(preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=1', $definition));
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO roles (id, role_name) VALUES (1, 'Customer')");
    $pdo->exec("INSERT INTO users (id, role_id, email, password) VALUES (1, 1, 'reference-test@example.invalid', 'unused')");
    $pdo->exec("INSERT INTO customers (id, user_id, first_name, last_name, phone_number) VALUES (1, 1, 'Reference', 'Test', '09999999001')");
    $pdo->exec("INSERT INTO branches (id, branch_key, branch_name, location, branch_type) VALUES (1, 'daraga', 'Daraga', 'Test', 'Test'), (2, 'yashano', 'Yashano', 'Test', 'Test'), (3, 'cabangan', 'Cabangan', 'Test', 'Test')");
    $pdo->exec("INSERT INTO employees (id, user_id, branch_id, first_name, last_name, phone_number) VALUES (1, 1, 1, 'Test', 'Stylist', '09999999002')");
    $pdo->exec("INSERT INTO services (id, branch_id, service_name, duration_minutes, price) VALUES (1, 1, 'Test Haircut', 30, 200)");
    $pdo->exec('INSERT INTO staff_services (employee_id, service_id) VALUES (1, 1)');
    // Simulate an old public code plus a missing reference before migration.
    $pdo->exec("INSERT INTO appointments (reference_code, customer_id, branch_id, appointment_datetime) VALUES ('LMS-LEGACY', 1, 1, '2027-01-01 09:00:00')");
    $pdo->exec("INSERT INTO home_service_requests (customer_id, address, event_type, preferred_date) VALUES (1, 'Test', 'Test', '2027-01-01')");
    migrate($pdo);
    check($pdo->query('SELECT reference_code FROM appointments WHERE id=1')->fetchColumn() === 'LMS-LEGACY', 'Legacy reference changed');
    check(str_starts_with($pdo->query('SELECT reference_code FROM home_service_requests WHERE id=1')->fetchColumn(), 'LM-HOM-'), 'Missing reference not backfilled');
    $references = [];
    foreach ([1 => 'DAR', 2 => 'YAS', 3 => 'CAB'] as $branch => $code) {
        $pdo->exec("INSERT INTO appointments (customer_id, branch_id, appointment_datetime) VALUES (1, $branch, '2027-01-02 09:00:00')");
        $id = $pdo->lastInsertId();
        $reference = $pdo->query("SELECT reference_code FROM appointments WHERE id=$id")->fetchColumn();
        check((bool) preg_match('/^LM-' . $code . '-\d{4}-\d{6,}$/', $reference), 'Wrong reference format');
        $references[] = $reference;
    }
    check(count(array_unique($references)) === 3, 'Repeated references');
    echo 'Branch bookings: ' . implode(', ', $references) . "\n";
    $duplicate = $pdo->quote($references[0]);
    foreach ([
        "INSERT INTO appointments (reference_code, customer_id, appointment_datetime) VALUES ($duplicate, 1, NOW())",
        "INSERT INTO home_service_requests (reference_code, customer_id, address, event_type, preferred_date) VALUES ($duplicate, 1, 'Test', 'Test', CURDATE())",
        "UPDATE appointments SET reference_code='CHANGED' WHERE reference_code=$duplicate",
        "DELETE FROM booking_reference_registry WHERE reference_code=$duplicate",
    ] as $sql) {
        try { $pdo->exec($sql); throw new RuntimeException('Uniqueness/immutability protection failed'); }
        catch (PDOException $expected) { check(in_array($expected->getCode(), ['23000', '45000']), $expected->getMessage()); }
    }
    $pdo->exec("DELETE FROM appointments WHERE reference_code=$duplicate");
    try {
        $pdo->exec("INSERT INTO appointments (reference_code, customer_id, appointment_datetime) VALUES ($duplicate, 1, NOW())");
        throw new RuntimeException('Deleted reference was reused');
    } catch (PDOException $expected) { check($expected->getCode() === '23000', $expected->getMessage()); }
    $pdo->beginTransaction();
    $abandoned = $pdo->query('SELECT next_booking_reference(1)')->fetchColumn();
    $pdo->rollBack();
    check($abandoned !== $pdo->query('SELECT next_booking_reference(1)')->fetchColumn(), 'Rollback reused sequence');
    $jobs = [];
    for ($i = 0; $i < 4; $i++) $jobs[] = launch(['concurrent', $name]);
    foreach ($jobs as $job) finish($job);
    $counts = $pdo->query('SELECT COUNT(*) AS n, COUNT(DISTINCT reference_code) AS unique_n FROM appointments')->fetch();
    check($counts['n'] === $counts['unique_n'], 'Concurrent booking collision');
    migrate($pdo); // Re-running must not reset the sequence or alter references.
    echo "PASS: migration/backfill, duplicate and cross-table rejection, immutability, deletion, rollback, 40 concurrent bookings.\n";

    $booking = endpoint($name, 'customer/submitBooking.php', [
        'branch' => 'daraga', 'serviceIds' => [1], 'date' => '2027-06-01', 'time' => '10:00',
        'customerName' => 'Reference Test', 'customerPhone' => '09999999001',
        'paymentMethod' => 'Cash', 'depositAmount' => 100, 'agreedToTerms' => '1',
    ]);
    check($booking['success'] ?? false, 'Customer booking failed: ' . json_encode($booking));
    $ref = $booking['appointment']['id'];
    check(str_starts_with($ref, 'LM-DAR-'), 'Customer confirmation missing reference');
    $tracking = endpoint($name, 'public/trackBooking.php', ['reference' => strtolower($ref), 'phone' => '09999999001']);
    check(($tracking['reference'] ?? '') === $ref && !isset($tracking['id']), 'Tracking failed or exposed internal key');
    $wrongPhone = endpoint($name, 'public/trackBooking.php', ['reference' => $ref, 'phone' => '00000000000']);
    check(!($wrongPhone['success'] ?? false), 'Tracking allowed wrong phone');
    $home = endpoint($name, 'customer/submitHomeServiceRequest.php', [
        'address' => 'Test address', 'eventType' => 'Test event', 'preferredDate' => '2027-06-01',
        'paymentMethod' => 'Cash', 'depositAmount' => 500, 'agreedToTerms' => '1',
    ]);
    check(($home['success'] ?? false) && str_starts_with($home['request']['id'], 'LM-HOM-'), 'Home confirmation failed');
    $dashboard = endpoint($name, 'customer/getDashboardData.php');
    check(in_array($ref, array_column($dashboard['appointments'] ?? [], 'id')), 'Dashboard missing reference');
    check(in_array($home['request']['id'], array_column($dashboard['homeServiceRequests'] ?? [], 'id')), 'Home dashboard missing reference');
    $pdo->exec("INSERT INTO booking_verifications (email, code, expires_at) VALUES ('guest@example.invalid', '123456', NOW() + INTERVAL 1 HOUR)");
    $guest = endpoint($name, 'public/submitGuestBooking.php', [
        'fullname' => 'Guest Test', 'contact' => '09999999003', 'branch' => 1, 'services' => [1],
        'appointment_date' => '2027-06-02', 'time_slot' => '10:00', 'email' => 'guest@example.invalid',
        'otp_code' => '123456', 'payment_method' => 'Cash', 'deposit_amount' => 100, 'agreedToTerms' => '1',
    ]);
    check(($guest['success'] ?? false) && str_starts_with($guest['reference'], 'LM-DAR-'), 'Guest booking failed: ' . json_encode($guest));
    $guestHome = endpoint($name, 'public/submitGuestHomeService.php', [
        'fullname' => 'Guest Test', 'contact' => '09999999003', 'address' => 'Test address',
        'eventType' => 'Test event', 'date' => '2027-06-03', 'time' => '10:00', 'clients' => '1', 'services' => ['Makeup'],
        'payment_method' => 'Cash', 'deposit_amount' => 500, 'agreedToTerms' => '1',
    ]);
    check(($guestHome['success'] ?? false) && str_starts_with($guestHome['reference'], 'LM-HOM-'), 'Guest home booking failed');
    $walkIn = endpoint($name, 'cashier/createWalkIn.php', [
        'clientName' => 'Walkin Test', 'clientPhone' => '09999999004', 'stylistId' => 1, 'serviceIds' => [1], 'branchId' => 'daraga',
    ], 'Admin');
    check(($walkIn['success'] ?? false) && str_starts_with($walkIn['reference'], 'LM-DAR-'), 'Walk-in booking failed: ' . json_encode($walkIn));
    $created = [$ref, $home['reference'], $guest['reference'], $guestHome['reference'], $walkIn['reference']];
    check(count(array_unique($created)) === 5, 'Endpoint references repeated');
    $assigned = endpoint($name, 'admin/assignStaff.php', ['id' => $home['reference'], 'staffId' => 1], 'Admin');
    check($assigned['success'] ?? false, 'Admin home assignment by reference failed');
    $updated = endpoint($name, 'admin/updateBookingStatus.php', ['id' => $home['reference'], 'status' => 'Confirmed'], 'Admin');
    check($updated['success'] ?? false, 'Admin home status by reference failed');
    $notice = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE message LIKE ?');
    $notice->execute(['%' . $home['reference'] . '%']);
    check((int) $notice->fetchColumn() > 0, 'Notification missing reference');
    $admin = endpoint($name, 'admin/getDashboardData.php', [], 'Admin');
    check(in_array($home['reference'], array_column($admin['bookings'] ?? [], 'id')), 'Admin dashboard missing public home reference');
    echo 'Endpoint bookings: ' . implode(', ', $created) . "\n";
    echo "PASS: all five booking endpoints, confirmations, customer/admin dashboards, tracking, phone verification, home assignment/status and notifications.\n";
    require __DIR__ . '/reservation_payment_cases.php';
} finally {
    // Only the randomly named database created above is removed.
    $source->exec("DROP DATABASE `$name`");
}
