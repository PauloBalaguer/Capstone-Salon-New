<?php
// Real saveService endpoint checks using temporary services/notifications tables.
// No catalog records, appointments, payments, or audit rows are persisted.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$cases = ['edit-duration', 'edit-price', 'edit-price-omitted-duration', 'add-short', 'zero', 'negative', 'text', 'fraction', 'overflow', 'missing-add-duration'];
if (($argv[1] ?? '') !== '--case') {
    foreach ($cases as $case) {
        $process = proc_open([PHP_BINARY, __FILE__, '--case', $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not run endpoint test.');
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || !str_contains($output, 'PASS:')) throw new RuntimeException("$case failed: $output $error");
        echo "PASS: $case\n";
    }
    exit;
}
$case = $argv[2] ?? '';
require_once __DIR__ . '/../../backend/config/database.php';
$pdo = Database::getInstance();
$fixtures = $pdo->query("SELECT s.*, b.branch_key FROM services s JOIN branches b ON b.id=s.branch_id WHERE s.service_name='Haircut' AND b.branch_key IN ('daraga','yashano') ORDER BY b.branch_key")->fetchAll();
if (count($fixtures) !== 2) throw new RuntimeException('Expected separate Daraga and Yashano haircut records.');
foreach (['services', 'notifications'] as $table) {
    $definition = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
    $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
    $definition = preg_replace('/^  CONSTRAINT .*\n?/m', '', $definition);
    $definition = preg_replace('/,\s*\)/', "\n)", $definition);
    $pdo->exec($definition);
}
foreach ($fixtures as $fixture) {
    unset($fixture['branch_key']);
    $fixture['duration_minutes'] = 30;
    $fixture['duration_label'] = '30 minutes';
    $columns = array_keys($fixture);
    $pdo->prepare('INSERT INTO services (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($fixture));
}
$first = $pdo->query('SELECT * FROM services ORDER BY id LIMIT 1')->fetch();
$second = $pdo->query('SELECT * FROM services ORDER BY id DESC LIMIT 1')->fetch();
$branch = $pdo->query('SELECT branch_key FROM branches WHERE id=' . (int) $first['branch_id'])->fetchColumn();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/backend/admin/saveService.php';
require_once __DIR__ . '/../../backend/config/session.php';
$_SESSION['user_id'] = 1; $_SESSION['user_role'] = 'Admin';
$_POST = ['id' => (string) $first['id'], 'branchId' => $branch, 'name' => $first['service_name'], 'description' => $first['description'] ?? '', 'category' => $first['category'] ?? '', 'price' => $first['price'], 'durationMinutes' => '30', 'loyaltyMultiplier' => $first['loyalty_multiplier'], 'paymentRequirement' => $first['payment_requirement'] === 'Half Payment' ? '50% Down Payment' : $first['payment_requirement'], 'active' => (string) $first['is_active'], 'preview' => '1'];
$expectSuccess = in_array($case, ['edit-duration', 'edit-price', 'edit-price-omitted-duration', 'add-short'], true);
if ($case === 'edit-duration') $_POST['durationMinutes'] = '45';
if (str_starts_with($case, 'edit-price')) $_POST['price'] = (string) ((float) $first['price'] + 1);
if ($case === 'edit-price-omitted-duration') unset($_POST['durationMinutes']);
if ($case === 'add-short') { $_POST['id'] = ''; $_POST['name'] = 'Duration test add-on'; $_POST['durationMinutes'] = '10'; }
if ($case === 'missing-add-duration') { $_POST['id'] = ''; unset($_POST['durationMinutes']); }
foreach (['zero' => '0', 'negative' => '-30', 'text' => '30minutes', 'fraction' => '30.5', 'overflow' => '2147483648'] as $name => $value) if ($case === $name) $_POST['durationMinutes'] = $value;
ob_start();
register_shutdown_function(function () use ($pdo, $case, $first, $second, $expectSuccess) {
    $body = json_decode(ob_get_contents(), true); ob_end_clean();
    session_destroy();
    $fail = function ($message) { fwrite(STDERR, $message . "\n"); exit(1); };
    if (($body['success'] ?? null) !== $expectSuccess) $fail('Unexpected endpoint response: ' . json_encode($body));
    $rows = $pdo->query('SELECT * FROM services ORDER BY id')->fetchAll();
    $current = $pdo->query('SELECT * FROM services WHERE id=' . (int) $first['id'])->fetch();
    $other = $pdo->query('SELECT * FROM services WHERE id=' . (int) $second['id'])->fetch();
    if ($other !== $second) $fail('Another branch service was modified.');
    $expected = $first;
    if ($case === 'edit-duration') { $expected['duration_minutes'] = 45; $expected['duration_label'] = '45 minutes'; }
    if (str_starts_with($case, 'edit-price')) $expected['price'] = number_format((float) $first['price'] + 1, 2, '.', '');
    unset($current['updated_at'], $expected['updated_at']); // Normal automatic update timestamp.
    if ($current !== $expected) $fail('Unrelated fields changed or expected duration/price was not saved: ' . json_encode(array_diff_assoc($current, $expected)));
    if ($case === 'add-short' && (count($rows) !== 3 || (int) $rows[2]['duration_minutes'] !== 10)) $fail('Short add-on was not created.');
    if (!$expectSuccess && count($rows) !== 2) $fail('Invalid request changed catalog.');
    if ($case === 'edit-duration') {
        // Verify the public/customer catalog immediately exposes the admin change.
        $_GET['branch'] = $first['branch_id'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        chdir(__DIR__ . '/../../backend/public');
        ob_start(); require 'getBranchServices.php'; $catalog = json_decode(ob_get_clean(), true);
        $updated = array_values(array_filter($catalog['services'] ?? [], fn($service) => (int) $service['id'] === (int) $first['id']));
        if (!$updated || $updated[0]['durationMinutes'] !== 45 || $updated[0]['duration'] !== '45 minutes') $fail('Customer catalog did not reflect the duration edit.');
    }
    echo "PASS: $case\n";
});
chdir(__DIR__ . '/../../backend/admin');
require 'saveService.php';
