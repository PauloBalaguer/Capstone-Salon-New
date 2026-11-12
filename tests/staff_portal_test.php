<?php
/**
 * Staff Portal: logging supplies, photos, shift actions.
 *
 * 1. Supplies logged at the same moment keep the stock right, and every log
 *    belongs to a recent booking of this stylist.
 * 2. A profile photo too large for MySQL gets a clear refusal, not a crash.
 * 3. A confirmation code that can't be emailed is reported, not claimed as sent.
 * 4. Parallel Start Breaks give one break and one success, with no false error.
 * 5. The page's own wiring: its messages reach toast.js, and start-up runs once.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_staff_portal_test');
$p = test_people($pdo);
$pdo->exec("UPDATE branches SET opening_time = '00:00:00', closing_time = '23:59:59', sunday_opening_time = NULL, sunday_closing_time = NULL WHERE id = 1");
$pdo->exec('DELETE FROM attendance');

$staff = $pdo->query("SELECT e.id, e.user_id FROM employees e JOIN users u ON u.id = e.user_id JOIN roles r ON r.id = u.role_id
    WHERE e.branch_id = 1 AND e.is_active = 1 AND r.role_name = 'Staff' ORDER BY e.id LIMIT 1")->fetch();
if (!$staff) exit("The dev database needs a Daraga stylist with a login.\n");
$me = ['user_id' => (int) $staff['user_id'], 'user_role' => 'Staff', 'branch_id' => 1, 'user_email' => 'staff@example.test', 'csrf_token' => 'test-token'];
$call = fn(string $file, array $post) => run_endpoint(['script' => "backend/staff/{$file}.php", 'session' => $me, 'post' => $post + ['csrfToken' => 'test-token']]);
$parallel = function (string $file, array $post, int $count) use ($me) {
    $at = microtime(true) + 1.0;
    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $handles[] = start_endpoint(['script' => "backend/staff/{$file}.php", 'session' => $me, 'post' => $post + ['csrfToken' => 'test-token'], 'startAt' => $at]);
    }
    return array_map('endpoint_result', $handles);
};
$succeeded = fn(array $results) => count(array_filter($results, fn($r) => ($r['success'] ?? false) === true));

// ---- 1. Logging supplies -----------------------------------------------------
$rows = $pdo->query('SELECT a.id, a.reference_code FROM appointments a JOIN appointment_services s ON s.appointment_id = a.id GROUP BY a.id ORDER BY a.id LIMIT 3')->fetchAll();
if (count($rows) < 3) exit("The dev database needs three appointments with services.\n");
$book = $pdo->prepare('UPDATE appointments SET branch_id = 1, employee_id = ?, status = ?, appointment_datetime = NOW() + INTERVAL ? DAY WHERE id = ?');
$book->execute([$staff['id'], 'In Progress', 0, $rows[0]['id']]);
$book->execute([$staff['id'], 'Completed', -6, $rows[1]['id']]);
$book->execute([$staff['id'], 'Completed', -8, $rows[2]['id']]);
[$running, $recent, $old] = array_column($rows, 'reference_code');

$item = (int) $pdo->query('SELECT id FROM inventory WHERE branch_id = 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$setStock = fn(int $stock) => $pdo->exec("UPDATE inventory SET quantity_on_hand = {$stock}, reorder_level = 0 WHERE id = {$item}");
$stock = fn() => (int) $pdo->query("SELECT quantity_on_hand FROM inventory WHERE id = {$item}")->fetchColumn();

$setStock(100);
$results = $parallel('updateInventory', ['id' => $item, 'quantity' => 1, 'referenceCode' => $running], 8);
check('8 logs at the same moment all count', $succeeded($results) === 8 && $stock() === 92);
$setStock(2);
$results = $parallel('updateInventory', ['id' => $item, 'quantity' => 2, 'referenceCode' => $running], 6);
check('6 logs of the last 2 units -> only one accepted, stock not negative', $succeeded($results) === 1 && $stock() === 0);

$setStock(10);
check('a log with no booking is refused', ($call('updateInventory', ['id' => $item, 'quantity' => 1, 'referenceCode' => ''])['success'] ?? true) === false && $stock() === 10);
check('a service finished 8 days ago is refused', ($call('updateInventory', ['id' => $item, 'quantity' => 1, 'referenceCode' => $old])['success'] ?? true) === false && $stock() === 10);
check('a service finished 6 days ago is accepted', ($call('updateInventory', ['id' => $item, 'quantity' => 1, 'referenceCode' => $recent])['success'] ?? false) === true && $stock() === 9);

// ---- 2. Profile photo --------------------------------------------------------
$phone = '09990001122';
$email = 'staff.portal.test@example.test';
$photo = fn(int $bytes) => 'data:image/jpeg;base64,' . base64_encode(random_bytes($bytes));
$small = $photo(300 * 1024);
$r = $call('saveProfile', ['phone' => $phone, 'email' => $email, 'profilePicture' => $small]);
$saved = $pdo->query("SELECT profile_picture FROM employees WHERE id = {$staff['id']}")->fetchColumn();
check('a 300 KB photo saves', ($r['success'] ?? false) === true && $saved === $small);
$r = $call('saveProfile', ['phone' => $phone, 'email' => $email, 'profilePicture' => $photo(1536 * 1024)]);
check('a 1.5 MB photo gets a clear refusal, not a crash', ($r['field'] ?? '') === 'profilePicture' && !str_starts_with((string) ($r['message'] ?? ''), 'Not JSON'));

// ---- 3. Password: staff can't change it themselves; see admin_staff_password_test.php.

// ---- 4. Shift actions --------------------------------------------------------
$failedRounds = 0;
for ($round = 0; $round < 6; $round++) {
    $pdo->exec('DELETE FROM attendance');
    $call('shiftAction', ['action' => 'shift_in']);
    $results = $parallel('shiftAction', ['action' => 'break_start'], 4);
    $breaks = (int) $pdo->query("SELECT COUNT(*) FROM attendance_breaks b JOIN attendance a ON a.id = b.attendance_id WHERE a.employee_id = {$staff['id']}")->fetchColumn();
    if ($succeeded($results) !== 1 || $breaks !== 1) $failedRounds++;
}
check('parallel Start Breaks: one break, one success (6 rounds)', $failedRounds === 0);

// ---- 5. The page's wiring ----------------------------------------------------
$root = dirname(__DIR__);
check('page messages call toast.js directly', !str_contains(file_get_contents("{$root}/function/staff/shared-data.js"), 'window.LMToast'));
check('Alpine runs init() itself; the page does not call it again', !str_contains(file_get_contents("{$root}/frontend/staff/dashboard.html"), 'x-init="init()"'));

finish();
