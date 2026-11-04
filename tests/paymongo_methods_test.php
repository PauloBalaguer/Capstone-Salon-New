<?php
/**
 * Self-check for the separate GCash / Maya / Card PayMongo methods, no keys needed.
 *
 *   C:\xampp\php\php.exe tests\paymongo_methods_test.php
 *
 * Clones the dev database into a throwaway one, starts this same file as a
 * mock PayMongo API (php -S), runs the real PayMongo.php code against both,
 * then drops the clone. Prints one line per check; exits 1 on any failure.
 */

// ---- Mock PayMongo API (this file is the php -S router) ---------------------
if (PHP_SAPI === 'cli-server') {
    $file = sys_get_temp_dir() . '/paymongo_mock_state.json';
    $state = is_file($file) ? json_decode(file_get_contents($file), true) : ['intents' => [], 'methods' => [], 'sessions' => [], 'sources' => [], 'payments' => [], 'calls' => []];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $body = json_decode(file_get_contents('php://input'), true)['data']['attributes'] ?? [];
    $key = explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 6)))[0];
    $state['calls'][] = ['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'key' => $key, 'body' => $body];
    $reply = function ($code, $data) use (&$state, $file) {
        file_put_contents($file, json_encode($state));
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    };
    $payment = function ($id) use (&$state) { return ['id' => $id, 'attributes' => $state['payments'][$id]]; };
    $intent = function ($id) use (&$state, $payment) {
        $a = $state['intents'][$id];
        $a['payments'] = array_map($payment, $a['payment_ids']);
        unset($a['payment_ids']);
        return ['id' => $id, 'attributes' => $a];
    };
    $session = function ($id) use (&$state, $payment) {
        $a = $state['sessions'][$id];
        $a['payments'] = array_map($payment, $a['payment_ids']);
        unset($a['payment_ids']);
        return ['id' => $id, 'attributes' => $a];
    };
    if ($post && $path === '/payment_intents') {
        $id = 'pi_' . (count($state['intents']) + 1);
        $state['intents'][$id] = $body + ['status' => 'awaiting_payment_method', 'last_payment_error' => null, 'next_action' => null, 'payment_ids' => []];
        $reply(200, ['data' => $intent($id)]);
    }
    if ($post && $path === '/payment_methods') {
        $id = 'pm_' . (count($state['methods']) + 1);
        $state['methods'][$id] = $body;
        $reply(200, ['data' => ['id' => $id, 'attributes' => $body]]);
    }
    if ($post && preg_match('#^/payment_intents/(pi_\d+)/attach$#', $path, $m) && isset($state['intents'][$m[1]])) {
        $state['intents'][$m[1]]['status'] = 'awaiting_next_action';
        $state['intents'][$m[1]]['payment_method'] = $body['payment_method'];
        $state['intents'][$m[1]]['next_action'] = ['type' => 'redirect', 'redirect' => [
            'url' => 'https://secure-authentication.paymongo.com/sources?id=src_for_' . $m[1], 'return_url' => $body['return_url']]];
        $reply(200, ['data' => $intent($m[1])]);
    }
    if (preg_match('#^/payment_intents/(pi_\d+)$#', $path, $m) && isset($state['intents'][$m[1]])) $reply(200, ['data' => $intent($m[1])]);
    if ($post && $path === '/checkout_sessions') {
        $id = 'cs_' . (count($state['sessions']) + 1);
        $state['sessions'][$id] = $body + ['status' => 'active', 'checkout_url' => 'https://checkout.paymongo.com/' . $id,
            'payment_intent' => ['attributes' => ['last_payment_error' => null]], 'payment_ids' => []];
        $reply(200, ['data' => $session($id)]);
    }
    if ($post && preg_match('#^/checkout_sessions/(cs_\d+)/expire$#', $path, $m) && isset($state['sessions'][$m[1]])) {
        $state['sessions'][$m[1]]['status'] = 'expired';
        $reply(200, ['data' => $session($m[1])]);
    }
    if (preg_match('#^/checkout_sessions/(cs_\d+)$#', $path, $m) && isset($state['sessions'][$m[1]])) $reply(200, ['data' => $session($m[1])]);
    if ($post && $path === '/sources') {
        $id = 'src_' . (count($state['sources']) + 1);
        $body['redirect']['checkout_url'] = 'https://secure-authentication.paymongo.com/sources?id=' . $id;
        $state['sources'][$id] = $body + ['status' => 'pending', 'charge_as' => 'paid'];
        $reply(200, ['data' => ['id' => $id, 'attributes' => $state['sources'][$id]]]);
    }
    if (preg_match('#^/sources/(src_\d+)$#', $path, $m) && isset($state['sources'][$m[1]])) $reply(200, ['data' => ['id' => $m[1], 'attributes' => $state['sources'][$m[1]]]]);
    if ($post && $path === '/payments') {
        // A payment from a chargeable source; charge_as lets a test make GCash decline it.
        if (($state['sources'][$body['source']['id'] ?? '']['status'] ?? '') !== 'chargeable') $reply(400, ['errors' => [['code' => 'resource_not_chargeable_state', 'detail' => 'Source is not chargeable.']]]);
        $src = &$state['sources'][$body['source']['id']];
        $id = 'pay_' . (count($state['payments']) + 1);
        $state['payments'][$id] = ['amount' => $body['amount'], 'currency' => $body['currency'], 'status' => $src['charge_as'], 'fee' => 250,
            'payment_intent_id' => null, 'source' => ['id' => $body['source']['id'], 'type' => 'gcash'], 'description' => $body['description'], 'metadata' => $body['metadata']]
            + ($src['charge_as'] === 'failed' ? ['failed_code' => 'insufficient_funds', 'failed_message' => 'Insufficient balance.'] : []);
        $src['status'] = 'consumed';
        $reply(200, ['data' => $payment($id)]);
    }
    if (preg_match('#^/payments/(pay_\d+)$#', $path, $m) && isset($state['payments'][$m[1]])) $reply(200, ['data' => $payment($m[1])]);
    if ($path === '/payments') $reply(200, ['data' => array_map($payment, array_reverse(array_keys($state['payments']))), 'has_more' => false]);
    if ($post && $path === '/refunds') $reply(200, ['data' => ['id' => 'ref_1', 'attributes' => ['status' => 'pending', 'payment_id' => $body['payment_id']]]]);
    $reply(404, ['errors' => [['detail' => 'Mock: no route ' . $path]]]);
}
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

// ---- Test harness ------------------------------------------------------------
$root = dirname(__DIR__);
require_once __DIR__ . '/support/dev_db.php'; // DEV_DATABASE, TEST_MYSQL, TEST_DUMP from the .env's DB_NAME / DB_PORT
$mysql = TEST_MYSQL;
$dump = TEST_DUMP;
$testDb = 'capstone_paymongo_test';
$stateFile = sys_get_temp_dir() . '/paymongo_mock_state.json';
@unlink($stateFile);

passthru("$mysql -e \"DROP DATABASE IF EXISTS $testDb; CREATE DATABASE $testDb\"");
passthru("$dump \"" . DEV_DATABASE . "\" | $mysql $testDb", $code);
if ($code !== 0) exit("Could not clone the database.\n");
// 057 first: on a dev database that ran 056, 053 can't place its columns until 057 puts paymongo_checkout_url back.
foreach (['057_restore_paymongo.sql', '053_paymongo_separate_methods.sql', '058_paymongo_gcash_sources.sql'] as $migration) {
    passthru("$mysql $testDb < \"$root\\database\\migrations\\$migration\"");
}
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8765', __FILE__], [['pipe', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']], $pipes);
usleep(500000);
register_shutdown_function(function () use ($server, $mysql, $testDb, $stateFile) {
    proc_terminate($server);
    passthru("$mysql -e \"DROP DATABASE IF EXISTS $testDb\"");
    @unlink($stateFile);
});

// Tests never deliver mail or texts: point env() at a folder with no .env, so Brevo and textbee have no keys...
putenv('SALON_SECRETS_DIR=' . __DIR__ . DIRECTORY_SEPARATOR . 'no-secrets');
require_once $root . '/backend/shared/config/setup/database.php';
require_once $root . '/backend/shared/config/payments/PayMongo.php';
require_once $root . '/backend/shared/config/payments/ReservationPayment.php';
require_once $root . '/backend/shared/config/booking/CancellationPolicy.php';
require_once $root . '/backend/shared/config/notifications/EmailOutbox.php';
(new ReflectionProperty(Database::class, 'db_name'))->setValue(null, $testDb);
(new ReflectionProperty(PayMongo::class, 'credentials'))->setValue(null, ['secret_key' => 'sk_test_mock', 'api_base' => 'http://127.0.0.1:8765']);
// ...and mark EmailOutbox's send-after-request hook as already registered, so queued emails stay queued
// (it would otherwise run after the shutdown function above has dropped the test database).
(new ReflectionProperty(EmailOutbox::class, 'hookRegistered'))->setValue(null, true);
$pdo = Database::getInstance();
$pdo->exec("UPDATE customers SET email = 'guest@example.com' WHERE id = 23");

$failures = 0;
function check(string $name, bool $ok): void { global $failures; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $failures++; }
function mock(): array { global $stateFile; return json_decode(file_get_contents($stateFile), true); }
function edit(callable $change): void { global $stateFile; $s = mock(); $change($s); file_put_contents($stateFile, json_encode($s)); }
function calls(string $method, string $pathPattern): array { return array_values(array_filter(mock()['calls'], fn($c) => $c['method'] === $method && preg_match($pathPattern, $c['path']))); }
// What PayMongo does when the customer authorizes / fails on the GCash or Maya page, or pays / is declined on the card page.
function authorize(string $intentId): string {
    $payId = '';
    edit(function (&$s) use ($intentId, &$payId) {
        $payId = 'pay_' . (count($s['payments']) + 1);
        $i = &$s['intents'][$intentId];
        $s['payments'][$payId] = ['amount' => $i['amount'], 'currency' => 'PHP', 'status' => 'paid', 'fee' => 250, 'payment_intent_id' => $intentId,
            'source' => ['id' => 'src_x', 'type' => $i['payment_method_allowed'][0]], 'description' => $i['description']];
        $i['status'] = 'succeeded';
        $i['payment_ids'][] = $payId;
    });
    return $payId;
}
// Like the real test page ("Fail" / "Expire/Fail Test Payment"): a failed payment carries the message, last_payment_error stays null.
function failIntent(string $intentId, string $message): void {
    edit(function (&$s) use ($intentId, $message) {
        $payId = 'pay_' . (count($s['payments']) + 1);
        $i = &$s['intents'][$intentId];
        $s['payments'][$payId] = ['amount' => $i['amount'], 'currency' => 'PHP', 'status' => 'failed', 'failed_code' => 'CLOSED', 'failed_message' => $message,
            'payment_intent_id' => $intentId, 'source' => ['id' => 'src_x', 'type' => $i['payment_method_allowed'][0]]];
        $i['status'] = 'awaiting_payment_method';
        $i['payment_ids'][] = $payId;
    });
}
// GCash Source: Authorize makes it chargeable (charge_as 'failed' = GCash declines the payment); Fail / Expire end it.
function setSource(string $sourceId, string $status, string $chargeAs = 'paid'): void {
    edit(function (&$s) use ($sourceId, $status, $chargeAs) { $s['sources'][$sourceId]['status'] = $status; $s['sources'][$sourceId]['charge_as'] = $chargeAs; });
}
function payCheckout(string $sessionId): string {
    $payId = '';
    edit(function (&$s) use ($sessionId, &$payId) {
        $payId = 'pay_' . (count($s['payments']) + 1);
        $c = &$s['sessions'][$sessionId];
        $s['payments'][$payId] = ['amount' => $c['line_items'][0]['amount'], 'currency' => 'PHP', 'status' => 'paid', 'fee' => 900,
            'payment_intent_id' => 'pi_checkout', 'source' => ['id' => 'card_x', 'type' => 'card']];
        $c['payment_ids'][] = $payId;
    });
    return $payId;
}
function row(string $table, int $id): array { global $pdo; return $pdo->query("SELECT * FROM {$table} WHERE id = {$id}")->fetch(); }
function logCount(string $event, string $externalId): int {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_transactions WHERE event = ? AND external_id = ?');
    $stmt->execute([$event, $externalId]);
    return (int) $stmt->fetchColumn();
}
function newAppointment(string $method, int $customer = 23, string $minutesAgo = '0', int $points = 0): int {
    global $pdo;
    $pdo->prepare("INSERT INTO appointments (reference_code, customer_id, branch_id, appointment_datetime, total_price, preferred_payment_method,
            deposit_paid, status, payment_status, reservation_amount_due, loyalty_points_used, created_at)
        VALUES ('', ?, 1, NOW() + INTERVAL 3 DAY, 1000, ?, 0, 'Pending', 'Payment Required', 250, ?, NOW() - INTERVAL {$minutesAgo} MINUTE)")
        ->execute([$customer, $method, $points]);
    return (int) $pdo->lastInsertId();
}
$ref = fn($id) => row('appointments', $id)['reference_code'];
$sync = fn($id, $home = false) => PayMongo::sync($GLOBALS['pdo'], ['id' => $id], $home);

// 1. GCash: a Source, straight to the GCash page; Fail / Cancel come back through redirect.failed.
$a = newAppointment('PayMongo-GCash');
$start = PayMongo::startPayment($pdo, 'PayMongo-GCash', $a, $ref($a), 250, 'guest');
$appt = row('appointments', $a);
$sourceCall = calls('POST', '#^/sources$#')[0];
check('GCash goes straight to the authorization page', str_starts_with($start['url'], 'https://secure-authentication.paymongo.com/') && $appt['paymongo_source_id'] === 'src_1'
    && $appt['paymongo_payment_intent_id'] === null);
check('source: secret key, centavos, gcash, billing from the customer record', $sourceCall['key'] === 'sk_test_mock' && $sourceCall['body']['amount'] === 25000
    && $sourceCall['body']['type'] === 'gcash' && ($sourceCall['body']['billing']['email'] ?? '') === 'guest@example.com' && ($sourceCall['body']['billing']['phone'] ?? '') !== '');
check('source returns to the result page with ref + token: success -> return, failed -> cancel', str_contains($sourceCall['body']['redirect']['success'], 'outcome=return')
    && str_contains($sourceCall['body']['redirect']['failed'], 'outcome=cancel') && str_contains($sourceCall['body']['redirect']['failed'], $appt['online_payment_token']));
check('no intent or checkout page in between', !calls('POST', '#^/payment_intents$#') && !calls('POST', '#^/checkout_sessions$#'));

// 2-4. Pending -> chargeable -> charged once -> paid; the webhook and refreshes never charge or record twice.
check('not authorized yet -> pending, nothing charged', $sync($a)['state'] === 'pending' && (int) row('appointments', $a)['deposit_paid'] === 0 && !calls('POST', '#^/payments$#'));
setSource('src_1', 'chargeable');
check('authorized (chargeable) -> payment created from the source -> paid', $sync($a)['state'] === 'paid');
$pay1 = row('appointments', $a)['paymongo_payment_id'];
$charge = calls('POST', '#^/payments$#');
check('payment: the source, centavos, reference in description and metadata', count($charge) === 1 && $charge[0]['body']['source'] === ['id' => 'src_1', 'type' => 'source']
    && $charge[0]['body']['amount'] === 25000 && str_contains($charge[0]['body']['description'], $appt['reference_code']) && $charge[0]['body']['metadata']['reference'] === $appt['reference_code']);
$appt = row('appointments', $a);
check('recorded as PayMongo-GCash, Awaiting Verification, pay_ id saved', (int) $appt['deposit_paid'] === 1 && $appt['deposit_method'] === 'PayMongo-GCash'
    && $appt['payment_status'] === 'Awaiting Verification' && $appt['deposit_reference'] === $pay1 && $appt['paymongo_payment_id'] === $pay1);
foreach (range(1, 4) as $_) $sync($a);
check('refreshing the success page (and the webhook) -> still one payment, one record', count(calls('POST', '#^/payments$#')) === 1 && logCount('payment_paid', $pay1) === 1);

// Payment created but not yet recorded (e.g. the request died in between): the next sync re-reads it, never charges again.
$a2 = newAppointment('PayMongo-GCash');
PayMongo::startPayment($pdo, 'PayMongo-GCash', $a2, $ref($a2), 250, 'guest');
$src2 = row('appointments', $a2)['paymongo_source_id'];
setSource($src2, 'chargeable');
(new ReflectionMethod(PayMongo::class, 'chargeSource'))->invoke(null, $pdo, row('appointments', $a2), false, 250.0);
check('half-finished charge -> next sync records it without a second payment', (int) row('appointments', $a2)['deposit_paid'] === 0 && $sync($a2)['state'] === 'paid'
    && count(array_filter(calls('POST', '#^/payments$#'), fn($c) => $c['body']['source']['id'] === $src2)) === 1);

// GCash Fail / Expire on the GCash page -> failed with a reason, logged once; Try again -> a new source.
foreach (['cancelled', 'expired'] as $ending) {
    $x = newAppointment('PayMongo-GCash');
    PayMongo::startPayment($pdo, 'PayMongo-GCash', $x, $ref($x), 250, 'guest');
    $oldSource = row('appointments', $x)['paymongo_source_id'];
    setSource($oldSource, $ending);
    $result = $sync($x);
    $sync($x);
    check("GCash source {$ending} -> failed, reason shown, unpaid, logged once", $result['state'] === 'failed' && $result['reason'] === 'GCash payment was cancelled or did not go through.'
        && (int) row('appointments', $x)['deposit_paid'] === 0 && logCount('payment_failed', $oldSource) === 1);
}
PayMongo::startPayment($pdo, 'PayMongo-GCash', $x, $ref($x), 250, 'guest');
check('Try again with GCash -> a NEW source for the same booking', row('appointments', $x)['paymongo_source_id'] !== $oldSource);

// GCash declines the payment after authorization -> failed with GCash's reason; not charged again.
$y = newAppointment('PayMongo-GCash');
PayMongo::startPayment($pdo, 'PayMongo-GCash', $y, $ref($y), 250, 'guest');
$srcY = row('appointments', $y)['paymongo_source_id'];
setSource($srcY, 'chargeable', 'failed');
$result = $sync($y);
$sync($y);
check('declined payment from the source -> failed with the reason, charged once', $result['state'] === 'failed' && $result['reason'] === 'Insufficient balance.'
    && (int) row('appointments', $y)['deposit_paid'] === 0 && count(array_filter(calls('POST', '#^/payments$#'), fn($c) => $c['body']['source']['id'] === $srcY)) === 1);
PayMongo::startPayment($pdo, 'PayMongo-GCash', $y, $ref($y), 250, 'guest');
setSource(row('appointments', $y)['paymongo_source_id'], 'chargeable');
check('Try again after a declined charge -> the new source is charged and paid', $sync($y)['state'] === 'paid' && (int) row('appointments', $y)['deposit_paid'] === 1);

// A GCash booking made before the switch (Payment Intent) still syncs.
$o = newAppointment('PayMongo-GCash');
PayMongo::startPayment($pdo, 'PayMongo-Maya', $o, $ref($o), 250, 'guest');
$oldIntentId = row('appointments', $o)['paymongo_payment_intent_id'];
$pdo->exec("UPDATE appointments SET preferred_payment_method = 'PayMongo-GCash' WHERE id = {$o}");
edit(function (&$s) use ($oldIntentId) { $s['intents'][$oldIntentId]['payment_method_allowed'] = ['gcash']; });
authorize($oldIntentId);
check('older GCash booking with a Payment Intent -> still paid as PayMongo-GCash', $sync($o)['state'] === 'paid' && row('appointments', $o)['deposit_method'] === 'PayMongo-GCash');
check('payment log shows the method', str_contains($pdo->query("SELECT details FROM payment_transactions WHERE event = 'payment_paid' AND external_id = '{$pay1}'")->fetchColumn(), 'PayMongo-GCash'));

// Staff verification, cancellation policy and refunds accept the new method.
$verifies = true;
try { ReservationPayment::validateVerification($appt, 250, 'PayMongo-GCash'); } catch (RuntimeException $e) { $verifies = false; }
$rejectsWrong = false;
try { ReservationPayment::validateVerification($appt, 250, 'PayMongo-Card'); } catch (RuntimeException $e) { $rejectsWrong = true; }
check('staff verify it as GCash (PayMongo), not as another method', $verifies && $rejectsWrong);
check('cancellation policy treats it as a held online deposit', CancellationPolicy::holdsDeposit($appt));
$refund = PayMongo::refundPayment($appt['deposit_reference'], 250, 'test');
check('refund goes to PayMongo with the pay_ id', $refund['id'] === 'ref_1' && end(mock()['calls'])['body']['payment_id'] === $pay1);

// 5. Maya: fail on the Maya page -> failed with reason, logged once; Try again -> new intent -> paid.
$b = newAppointment('PayMongo-Maya');
PayMongo::startPayment($pdo, 'PayMongo-Maya', $b, $ref($b), 250, 'guest');
$firstIntent = row('appointments', $b)['paymongo_payment_intent_id'];
check('Maya intent allows paymaya only', mock()['intents'][$firstIntent]['payment_method_allowed'] === ['paymaya']);
failIntent($firstIntent, 'The payment was cancelled by the customer.');
$result = $sync($b);
$sync($b); $sync($b);
check('cancelled on the Maya page -> failed, reason shown, booking unpaid', $result['state'] === 'failed' && $result['reason'] === 'The payment was cancelled by the customer.'
    && (int) row('appointments', $b)['deposit_paid'] === 0 && row('appointments', $b)['payment_status'] === 'Payment Required');
check('failure logged once however often the page refreshes', logCount('payment_failed', $firstIntent) === 1);
$token = row('appointments', $b)['online_payment_token'];
PayMongo::startPayment($pdo, 'PayMongo-Maya', $b, $ref($b), 250, 'guest');
$retry = row('appointments', $b);
check('Try again -> a NEW intent, same booking and result-page token', $retry['paymongo_payment_intent_id'] !== $firstIntent && $retry['online_payment_token'] === $token
    && (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE reference_code = '{$retry['reference_code']}'")->fetchColumn() === 1);
authorize($retry['paymongo_payment_intent_id']);
check('retried Maya payment -> paid as PayMongo-Maya', $sync($b)['state'] === 'paid' && row('appointments', $b)['deposit_method'] === 'PayMongo-Maya');

// Card: card-only checkout with billing; a decline shows the reason; choosing another method switches the booking.
$c = newAppointment('PayMongo-GCash');
PayMongo::startPayment($pdo, 'PayMongo-GCash', $c, $ref($c), 250, 'customer');
setSource(row('appointments', $c)['paymongo_source_id'], 'cancelled');
PayMongo::startPayment($pdo, 'PayMongo-Card', $c, $ref($c), 250, 'customer');
$appt = row('appointments', $c);
$cs = calls('POST', '#^/checkout_sessions$#')[0];
check('Card -> PayMongo hosted card page, card only, billing prefilled', $cs['body']['payment_method_types'] === ['card'] && ($cs['body']['billing']['email'] ?? '') === 'guest@example.com'
    && str_starts_with($appt['paymongo_checkout_url'], 'https://checkout.paymongo.com/'));
check('choosing another method switches the booking to Card', $appt['preferred_payment_method'] === 'PayMongo-Card' && $appt['paymongo_source_id'] === null);
edit(function (&$s) use ($appt) { $s['sessions'][$appt['paymongo_checkout_id']]['payment_intent']['attributes']['last_payment_error'] = ['failed_code' => 'card_declined', 'failed_message' => 'Your card was declined.']; });
$declined = $sync($c);
check('card declined -> unpaid, decline reason shown', $declined['state'] === 'pending' && $declined['reason'] === 'Your card was declined.' && (int) row('appointments', $c)['deposit_paid'] === 0);
$cardPay = payCheckout($appt['paymongo_checkout_id']);
check('card paid -> recorded as PayMongo-Card', $sync($c)['state'] === 'paid' && row('appointments', $c)['deposit_method'] === 'PayMongo-Card' && row('appointments', $c)['paymongo_payment_id'] === $cardPay);

// Switching away from an open card page closes it.
$d = newAppointment('PayMongo-Card');
PayMongo::startPayment($pdo, 'PayMongo-Card', $d, $ref($d), 250, 'guest');
$openSession = row('appointments', $d)['paymongo_checkout_id'];
PayMongo::startPayment($pdo, 'PayMongo-GCash', $d, $ref($d), 250, 'guest');
check('switching from Card to GCash expires the open card page', mock()['sessions'][$openSession]['status'] === 'expired');

// An earlier Maya attempt finished after "Try again": recorded once; the second one is flagged for refund.
$e = newAppointment('PayMongo-Maya');
PayMongo::startPayment($pdo, 'PayMongo-Maya', $e, $ref($e), 250, 'guest');
$oldIntent = row('appointments', $e)['paymongo_payment_intent_id'];
PayMongo::startPayment($pdo, 'PayMongo-Maya', $e, $ref($e), 250, 'guest');
$newIntent = row('appointments', $e)['paymongo_payment_intent_id'];
$latePay = authorize($oldIntent);
check('payment for a superseded attempt (webhook) is still recorded', PayMongo::syncPayment($pdo, $latePay) && row('appointments', $e)['deposit_reference'] === $latePay);
$secondPay = authorize($newIntent);
PayMongo::syncPayment($pdo, $secondPay);
check('a second payment on a paid booking is flagged for refund, not recorded', logCount('duplicate_payment', $secondPay) === 1
    && row('appointments', $e)['deposit_reference'] === $latePay
    && (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE message LIKE '%({$secondPay},%'")->fetchColumn() === 1);

// 2b. Closed the tab: hold expires, slot freed, points back; card page closed. A late Maya payment becomes Refund Due;
// a GCash source authorized too late is never charged.
// Each payment is started within the hold, then the clock moves past it.
$f = newAppointment('PayMongo-Maya', 23, '0', 50);
PayMongo::startPayment($pdo, 'PayMongo-Maya', $f, $ref($f), 250, 'guest');
$g = newAppointment('PayMongo-Card', 23);
PayMongo::startPayment($pdo, 'PayMongo-Card', $g, $ref($g), 250, 'guest');
$k = newAppointment('PayMongo-GCash', 23);
PayMongo::startPayment($pdo, 'PayMongo-GCash', $k, $ref($k), 250, 'guest');
$pdo->exec("UPDATE appointments SET created_at = NOW() - INTERVAL 31 MINUTE WHERE id IN ({$f}, {$g}, {$k})");
$late = false;
try { PayMongo::startPayment($pdo, 'PayMongo-GCash', $k, $ref($k), 250, 'guest'); } catch (RuntimeException $e) { $late = str_contains($e->getMessage(), 'deadline'); }
check('past the hold -> a new payment attempt is refused', $late);
$points = (int) $pdo->query('SELECT loyalty_points FROM customers WHERE id = 23')->fetchColumn();
PayMongo::releaseExpiredHolds($pdo);
check('abandoned Maya booking released, loyalty points restored', row('appointments', $f)['status'] === 'Cancelled'
    && (int) $pdo->query('SELECT loyalty_points FROM customers WHERE id = 23')->fetchColumn() === $points + 50);
check('abandoned card booking released and its card page expired', row('appointments', $g)['status'] === 'Cancelled'
    && mock()['sessions'][row('appointments', $g)['paymongo_checkout_id']]['status'] === 'expired');
check('abandoned GCash booking released', row('appointments', $k)['status'] === 'Cancelled');
authorize(row('appointments', $f)['paymongo_payment_intent_id']);
$sync($f);
check('paid after release -> recorded as Refund Due', row('appointments', $f)['payment_status'] === 'Refund Due');
$srcK = row('appointments', $k)['paymongo_source_id'];
setSource($srcK, 'chargeable');
$sync($k);
check('GCash authorized after release -> not charged, booking stays released and unpaid', !array_filter(calls('POST', '#^/payments$#'), fn($c) => $c['body']['source']['id'] === $srcK)
    && (int) row('appointments', $k)['deposit_paid'] === 0 && row('appointments', $k)['status'] === 'Cancelled');

// Guest without email: billing left out (PayMongo requires an email when billing is sent).
$pdo->exec("UPDATE customers SET email = NULL WHERE id = 25");
$h = newAppointment('PayMongo-GCash', 25);
PayMongo::startPayment($pdo, 'PayMongo-GCash', $h, $ref($h), 250, 'guest');
$sourceCalls = calls('POST', '#^/sources$#');
check('guest without email -> GCash source has no billing', !isset(end($sourceCalls)['body']['billing']));

// Legacy combined 'PayMongo' booking still works and keeps its method.
$l = newAppointment('PayMongo');
$legacy = PayMongo::createCheckout($ref($l), 250, 'Legacy', 'http://x/s', 'http://x/c');
$pdo->prepare('UPDATE appointments SET paymongo_checkout_id = ? WHERE id = ?')->execute([$legacy['id'], $l]);
payCheckout($legacy['id']);
check('legacy combined PayMongo booking still syncs and stays PayMongo', $sync($l)['state'] === 'paid' && row('appointments', $l)['deposit_method'] === 'PayMongo');

// 5. Home service reservation fee with each method.
$hsToken = str_repeat('a', 32);
$pdo->exec("INSERT INTO home_service_requests (reference_code, customer_id, address, event_type, preferred_date, preferred_time, status, payment_status, quote_price, deposit_amount, deposit_paid, online_payment_token)
    VALUES ('', 23, 'Test venue', 'Debut', CURDATE() + INTERVAL 30 DAY, '10:00:00', 'Payment Required', 'Payment Required', 5000, 1500, 0, '{$hsToken}')");
$hs = (int) $pdo->lastInsertId();
$hsRef = row('home_service_requests', $hs)['reference_code'];
$urls = PayMongo::payUrls($hsToken, $hsRef, 1500, 'guest');
check('home service gets GCash, Maya and Card pay links', isset($urls['PayMongo-GCash'], $urls['PayMongo-Maya'], $urls['PayMongo-Card'])
    && str_contains($urls['PayMongo-Maya'], 'outcome=pay') && str_contains($urls['choose'], 'outcome=choose'));
PayMongo::startPayment($pdo, 'PayMongo-Maya', $hs, $hsRef, 1500, 'guest', true);
authorize(row('home_service_requests', $hs)['paymongo_payment_intent_id']);
check('home service Maya -> paid', $sync($hs, true)['state'] === 'paid');
$hsRow = row('home_service_requests', $hs);
$hsPays = $pdo->query("SELECT method FROM home_service_payments WHERE home_service_request_id = {$hs}")->fetchAll(PDO::FETCH_COLUMN);
check('home service fee recorded once as PayMongo-Maya', (int) $hsRow['deposit_paid'] === 1 && $hsRow['deposit_method'] === 'PayMongo-Maya' && $hsPays === ['PayMongo-Maya']);
$sync($hs, true);
check('home service repeat sync -> still one payment row', (int) $pdo->query("SELECT COUNT(*) FROM home_service_payments WHERE home_service_request_id = {$hs}")->fetchColumn() === 1);
$pdo->exec("INSERT INTO home_service_requests (reference_code, customer_id, address, event_type, preferred_date, preferred_time, status, payment_status, quote_price, deposit_amount, deposit_paid, online_payment_token)
    VALUES ('', 23, 'Test venue', 'Debut', CURDATE() + INTERVAL 60 DAY, '10:00:00', 'Payment Required', 'Payment Required', 5000, 1500, 0, '" . str_repeat('b', 32) . "')");
$hs2 = (int) $pdo->lastInsertId();
PayMongo::startPayment($pdo, 'PayMongo-GCash', $hs2, row('home_service_requests', $hs2)['reference_code'], 1500, 'guest', true);
setSource(row('home_service_requests', $hs2)['paymongo_source_id'], 'chargeable');
$sync($hs2, true);
check('home service GCash source -> charged and recorded once as PayMongo-GCash', $sync($hs2, true)['state'] === 'paid'
    && $pdo->query("SELECT method FROM home_service_payments WHERE home_service_request_id = {$hs2}")->fetchAll(PDO::FETCH_COLUMN) === ['PayMongo-GCash']);

// 6. Booking again while a booking waits for payment -> offered that booking instead of a second slot.
$pdo->exec("INSERT INTO customers (first_name, last_name, phone_number) VALUES ('Held', 'Test', '09170000999')");
$heldCustomer = (int) $pdo->lastInsertId();
check('no unpaid booking -> booking goes ahead', PayMongo::heldBooking($pdo, $heldCustomer, 'guest') === null);
$h = newAppointment('PayMongo-Maya', $heldCustomer);
PayMongo::startPayment($pdo, 'PayMongo-Maya', $h, $ref($h), 250, 'guest');
$held = PayMongo::heldBooking($pdo, $heldCustomer, 'guest');
check('unpaid booking -> its reference and payment page', ($held['heldBooking']['reference'] ?? '') === $ref($h)
    && str_contains($held['heldBooking']['resultUrl'], row('appointments', $h)['online_payment_token']));
$pdo->exec("UPDATE appointments SET status = 'Cancelled' WHERE id = {$h}");
check('released booking -> booking goes ahead', PayMongo::heldBooking($pdo, $heldCustomer, 'guest') === null);

// Helpers.
check('methods, labels and online check (legacy included, manual GCash not)', PayMongo::METHODS === ['PayMongo-GCash', 'PayMongo-Maya', 'PayMongo-Card']
    && PayMongo::isOnline('PayMongo') && PayMongo::isOnline('PayMongo-Card') && !PayMongo::isOnline('GCash')
    && PayMongo::isEwallet('PayMongo-Maya') && !PayMongo::isEwallet('PayMongo-Card') && PayMongo::label('PayMongo-Maya') === 'Maya (PayMongo)');

echo $failures ? "\n{$failures} check(s) failed.\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
