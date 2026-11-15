<?php
/**
 * Balance payment email (panel item 4). Every email attempt is logged with
 * its real outcome -- including "not sent: no email address on file" and
 * Brevo's own reason when it refuses -- Admin is told when the link was NOT
 * emailed, and Track your booking has a "Pay remaining balance" button that
 * works without an emailed link.
 *
 * Starts a tiny mock PayMongo API (php -S), clones the dev database, prints
 * one line per check, exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_balance_email_test');
$p = test_people($pdo);

// Mock PayMongo: POST /checkout_sessions -> a new session id and checkout_url each time.
$mock = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paymongo_checkout_mock.php';
file_put_contents($mock, '<?php $n = (int) @file_get_contents(__FILE__ . ".n") + 1; file_put_contents(__FILE__ . ".n", $n);'
    . ' header("Content-Type: application/json"); echo json_encode(["data" => ["id" => "cs_mock_$n", "attributes" => ["checkout_url" => "https://checkout.example/cs_mock_$n"]]]);');
@unlink($mock . '.n');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8796', $mock], [['pipe', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']], $pipes);
register_shutdown_function(fn() => proc_terminate($server));
usleep(600000);
$paymongo = 'http://127.0.0.1:8796';
$checkouts = fn() => (int) @file_get_contents($mock . '.n');

// A guest home service: ₱3,000 quote, ₱1,000 reservation fee paid, no email anywhere.
$id = (int) $pdo->query('SELECT id FROM home_service_requests ORDER BY id LIMIT 1')->fetchColumn();
$customer = (int) $pdo->query("SELECT customer_id FROM home_service_requests WHERE id = {$id}")->fetchColumn();
$pdo->exec("UPDATE customers SET email = NULL, user_id = NULL, notify_email = 1 WHERE id = {$customer}");
$pdo->exec("UPDATE home_service_requests SET status = 'Confirmed', quote_price = 3000, deposit_amount = 1000, deposit_paid = 1, payment_status = 'Down Payment Verified',
    contact_email = NULL, balance_checkout_url = NULL, balance_checkout_id = NULL WHERE id = {$id}");
$pdo->exec("DELETE FROM home_service_payments WHERE home_service_request_id = {$id}");
$pdo->exec("INSERT INTO home_service_payments (home_service_request_id, kind, amount, method, reference) VALUES ({$id}, 'deposit', 1000, 'Cash', NULL)");
$ref = $pdo->query("SELECT reference_code FROM home_service_requests WHERE id = {$id}")->fetchColumn();
$log = fn() => $pdo->query("SELECT to_email, subject, status, attempts, last_error FROM email_outbox WHERE reference_code = " . $pdo->quote($ref) . ' ORDER BY id DESC')->fetchAll();

$r = run_endpoint(['script' => 'backend/admin/homeServiceBalance.php', 'session' => $p['admin'], 'paymongo' => $paymongo, 'post' => ['id' => $ref, 'action' => 'sendLink']]);
check('admin send link with no email: link made, admin told it was NOT emailed', ($r['success'] ?? false) && ($r['emailed'] ?? true) === false
    && str_contains($r['message'] ?? '', 'NOT emailed') && str_starts_with($r['payUrl'] ?? '', 'https://checkout.example/'));
$first = $log()[0] ?? [];
check('skipped email is logged with the reason', ($first['status'] ?? '') === 'Failed' && str_contains($first['last_error'] ?? '', 'no email address')
    && str_starts_with($first['subject'] ?? '', 'Pay your remaining balance'));

$r = run_endpoint(['script' => 'backend/public/trackBooking.php', 'method' => 'GET', 'paymongo' => $paymongo, 'get' => ['reference' => $ref]]);
check('Track your booking offers "Pay remaining balance" (₱2,000)', ($r['canPayBalance'] ?? false) === true && (float) ($r['balanceDue'] ?? 0) === 2000.0);

$before = $checkouts();
$r = run_endpoint(['script' => 'backend/public/payHomeServiceBalance.php', 'paymongo' => $paymongo, 'post' => ['reference' => $ref]]);
check('pay button reuses the link already sent (no second checkout)', ($r['success'] ?? false) && $checkouts() === $before
    && $r['url'] === $pdo->query("SELECT balance_checkout_url FROM home_service_requests WHERE id = {$id}")->fetchColumn());

// With an email but Brevo not configured (tests have no keys): the real reason is stored, not a generic one.
$pdo->exec("UPDATE home_service_requests SET contact_email = 'balance-test@example.com', balance_checkout_url = NULL WHERE id = {$id}");
$r = run_endpoint(['script' => 'backend/public/payHomeServiceBalance.php', 'paymongo' => $paymongo, 'post' => ['reference' => $ref]]);
EmailOutbox::flush($pdo); // what the request's shutdown hook (or the cron) does; tests have no Brevo keys
$latest = $log()[0] ?? [];
check('no link yet: pay button creates one', ($r['success'] ?? false) && $checkouts() === $before + 1);
check('balance email queued to the request email', ($latest['to_email'] ?? '') === 'balance-test@example.com' && str_starts_with($latest['subject'] ?? '', 'Pay your remaining balance'));
check('failed send keeps the real reason', (int) ($latest['attempts'] ?? 0) === 1 && str_contains($latest['last_error'] ?? '', 'sender configuration is incomplete'));

$r = run_endpoint(['script' => 'backend/admin/emailLog.php', 'method' => 'GET', 'session' => $p['admin'], 'get' => ['reference' => $ref]]);
check('admin email log lists every attempt for the booking', count($r['emails'] ?? []) === count($log()) && count($log()) >= 2);
$skippedId = (int) $pdo->query("SELECT id FROM email_outbox WHERE reference_code = " . $pdo->quote($ref) . " AND to_email = '' LIMIT 1")->fetchColumn();
$r = run_endpoint(['script' => 'backend/admin/emailLog.php', 'session' => $p['admin'], 'post' => ['action' => 'retry', 'id' => $skippedId]]);
check('"Try again" is refused for an email with no address', !($r['success'] ?? true));
$r = run_endpoint(['script' => 'backend/admin/emailLog.php', 'method' => 'GET', 'session' => $p['cashier'], 'get' => ['reference' => $ref]]);
check('cashier cannot read the admin email log', !($r['success'] ?? true));

// Should fail
$r = run_endpoint(['script' => 'backend/public/payHomeServiceBalance.php', 'paymongo' => $paymongo, 'post' => ['reference' => 'not-a-ref']]);
check('bad reference is refused', !($r['success'] ?? true));
$pdo->exec("INSERT INTO home_service_payments (home_service_request_id, kind, amount, method, reference) VALUES ({$id}, 'balance', 2000, 'Cash', NULL)");
$r = run_endpoint(['script' => 'backend/public/payHomeServiceBalance.php', 'paymongo' => $paymongo, 'post' => ['reference' => $ref]]);
check('fully paid booking: nothing to pay', !($r['success'] ?? true) && str_contains($r['message'] ?? '', 'fully paid'));
$r = run_endpoint(['script' => 'backend/public/trackBooking.php', 'method' => 'GET', 'paymongo' => $paymongo, 'get' => ['reference' => $ref]]);
check('fully paid booking: no pay button on tracking', ($r['canPayBalance'] ?? true) === false);

finish();
