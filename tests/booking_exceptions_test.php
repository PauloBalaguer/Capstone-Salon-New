<?php
/**
 * Exception cases around payments and attendance, through the real endpoints.
 *
 *   C:\xampp\php\php.exe tests\booking_exceptions_test.php
 *
 * 1. A PayMongo (GCash) payment fails, the customer retries, staff verify,
 *    the cashier collects the balance -- then the revenue report must match
 *    the payment log and the cashier payment exactly.
 * 2. No-show: the deposit is kept (Forfeited) and logged; never automatic.
 * 3. Late arrival within / beyond the SALON_LATE_GRACE_MINUTES grace period:
 *    the arrival review says so, and changes nothing by itself.
 * 4. Cancellation with a deposit: the non-refundable policy is applied once,
 *    only to money the salon actually holds.
 *
 * PayMongo is the mock API in tests/paymongo_methods_test.php (started here
 * as a local php -S server), so no real keys are used. Clones the dev
 * database into a throwaway one, prints one line per check, exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_exceptions_test');
$root = dirname(__DIR__);
require_once $root . '/backend/shared/config/booking/Scheduling.php';
require_once $root . '/backend/shared/config/payments/RevenueReport.php';
require_once $root . '/backend/shared/config/payments/PayMongo.php';
require_once $root . '/backend/shared/config/notifications/CustomerNotifier.php';
$p = test_people($pdo);
$pdo->exec("UPDATE customers SET notify_email = 1 WHERE id = {$p['customerA']}");

// ---- Mock PayMongo API -------------------------------------------------------
const MOCK_API = 'http://127.0.0.1:8766';
$stateFile = sys_get_temp_dir() . '/paymongo_mock_state.json';
@unlink($stateFile);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8766', __DIR__ . '/paymongo_methods_test.php'], [['pipe', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']], $pipes);
usleep(500000);
register_shutdown_function(function () use ($server, $stateFile) { proc_terminate($server); @unlink($stateFile); });
(new ReflectionProperty(PayMongo::class, 'credentials'))->setValue(null, ['secret_key' => 'sk_test_mock', 'api_base' => MOCK_API]);
function mock_edit(callable $change): void
{
    global $stateFile;
    $state = json_decode(file_get_contents($stateFile), true);
    $change($state);
    file_put_contents($stateFile, json_encode($state));
}
// What PayMongo records when the customer cancels on / authorizes on the GCash page.
function mock_payment(string $intentId, bool $paid, string $message = ''): void
{
    mock_edit(function (&$s) use ($intentId, $paid, $message) {
        $payId = 'pay_' . (count($s['payments']) + 1);
        $intent = &$s['intents'][$intentId];
        $s['payments'][$payId] = ['amount' => $intent['amount'], 'currency' => 'PHP', 'status' => $paid ? 'paid' : 'failed', 'fee' => 250,
            'payment_intent_id' => $intentId, 'source' => ['id' => 'src_x', 'type' => 'gcash'], 'description' => $intent['description'] ?? '']
            + ($paid ? [] : ['failed_code' => 'CLOSED', 'failed_message' => $message]);
        $intent['status'] = $paid ? 'succeeded' : 'awaiting_payment_method';
        $intent['payment_ids'][] = $payId;
    });
}
function log_count(PDO $pdo, string $event, string $reference): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_transactions WHERE event = ? AND reference_code = ?');
    $stmt->execute([$event, $reference]);
    return (int) $stmt->fetchColumn();
}

// ---- 1. Failed online payment -> retry -> completed visit -> revenue matches ----
$service = (int) $pdo->query("SELECT id FROM services WHERE branch_id = 1 AND is_active = 1 AND price >= 500 AND payment_requirement = 'Half Payment' ORDER BY id LIMIT 1")->fetchColumn();
$day = free_day($pdo, 50);
$totalsBefore = RevenueReport::totals($pdo);
$r = run_endpoint(['script' => 'backend/customer/submitBooking.php', 'session' => $p['sessionA'], 'paymongo' => MOCK_API,
    'post' => ['branch' => 'daraga', 'serviceIds' => [(string) $service], 'date' => $day, 'time' => '10:00',
        'customerName' => 'Test Customer', 'customerPhone' => '09171234567', 'paymentMethod' => 'PayMongo-GCash', 'agreedToTerms' => '1']]);
check('customer books with GCash -> sent to the GCash page, booking unpaid', ($r['success'] ?? false) === true
    && str_starts_with((string) $r['checkoutUrl'], 'https://secure-authentication.paymongo.com/'));
$ref = $r['reference'] ?? '';
$appt = $pdo->query("SELECT * FROM appointments WHERE reference_code = " . $pdo->quote($ref))->fetch();
$id = (int) $appt['id'];
$status = fn() => run_endpoint(['script' => 'backend/public/paymongoStatus.php', 'method' => 'GET', 'paymongo' => MOCK_API,
    'get' => ['ref' => $ref, 'token' => $appt['online_payment_token']]]);

mock_payment($appt['paymongo_payment_intent_id'], false, 'The payment was cancelled by the customer.');
$r = $status();
$now = row($pdo, 'appointments', $id);
check('payment cancelled on the GCash page -> result page shows "failed" with the reason', ($r['state'] ?? '') === 'failed'
    && $r['reason'] === 'The payment was cancelled by the customer.');
check('failed payment -> booking still Pending and unpaid, nothing collected', $now['status'] === 'Pending' && (int) $now['deposit_paid'] === 0
    && $now['payment_status'] === 'Payment Required' && !array_filter(RevenueReport::rows($pdo, []), fn($row) => $row['reference'] === $ref));
check('failure logged once', log_count($pdo, 'payment_failed', $ref) === 1);

$r = run_endpoint(['script' => 'backend/public/paymongoPay.php', 'paymongo' => MOCK_API,
    'post' => ['ref' => $ref, 'token' => $appt['online_payment_token'], 'method' => 'PayMongo-GCash']]);
$retry = row($pdo, 'appointments', $id);
check('"Try again" -> a new GCash attempt for the same booking', ($r['success'] ?? false) === true
    && $retry['paymongo_payment_intent_id'] !== $appt['paymongo_payment_intent_id']
    && (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE reference_code = " . $pdo->quote($ref))->fetchColumn() === 1);
mock_payment($retry['paymongo_payment_intent_id'], true);
$r = $status();
$status();
$paid = row($pdo, 'appointments', $id);
check('retry paid -> deposit recorded as PayMongo-GCash, awaiting staff verification', ($r['state'] ?? '') === 'paid' && (int) $paid['deposit_paid'] === 1
    && $paid['deposit_method'] === 'PayMongo-GCash' && $paid['payment_status'] === 'Awaiting Verification');
check('paid once in the log, however often the result page reloads', log_count($pdo, 'payment_paid', $ref) === 1);

$r = run_endpoint(['script' => 'backend/cashier/updateStatus.php', 'session' => $p['cashier'],
    'post' => ['id' => $ref, 'status' => 'Confirmed', 'depositAmount' => (string) $paid['deposit_amount'], 'depositMethod' => 'PayMongo-GCash']]);
check('cashier verifies the deposit and confirms', ($r['success'] ?? false) === true && row($pdo, 'appointments', $id)['payment_status'] === 'Down Payment Verified');
$balance = round((float) $paid['total_price'] - (float) $paid['deposit_amount'], 2);
$r = run_endpoint(['script' => 'backend/cashier/payment.php', 'session' => $p['cashier'],
    'post' => ['reference' => $ref, 'paymentMethod' => 'Cash', 'cashReceived' => (string) $balance, 'tip' => '0', 'products' => '[]']]);
$done = row($pdo, 'appointments', $id);
check('cashier collects the balance -> Completed, Fully Paid', ($r['success'] ?? false) === true && $done['status'] === 'Completed' && $done['payment_status'] === 'Fully Paid');

$report = run_endpoint(['script' => 'backend/admin/getRevenueReport.php', 'method' => 'GET', 'session' => $p['admin'], 'get' => []]);
$log = run_endpoint(['script' => 'backend/admin/getPaymentLog.php', 'method' => 'GET', 'session' => $p['admin'], 'get' => ['reference' => $ref]]);
$rows = array_values(array_filter($report['collections'] ?? [], fn($row) => $row['reference'] === $ref));
$byKind = array_column($rows, 'amount', 'kind');
$logPaid = array_values(array_filter($log['events'] ?? [], fn($e) => $e['event'] === 'payment_paid'));
$cashier = (float) $pdo->query("SELECT SUM(amount) FROM payments WHERE appointment_id = {$id} AND status = 'Paid'")->fetchColumn();
check('revenue report: one reservation row + one checkout row, the failed attempt not counted', count($rows) === 2
    && isset($byKind['Reservation payment'], $byKind['Cashier checkout']));
check('reservation row = the payment log\'s paid amount = the verified deposit', count($logPaid) === 1
    && abs($byKind['Reservation payment'] - (float) $logPaid[0]['amount']) < 0.01 && abs($byKind['Reservation payment'] - (float) $paid['deposit_amount']) < 0.01);
check('checkout row = the cashier payment record', abs($byKind['Cashier checkout'] - $cashier) < 0.01);
$totalsAfter = RevenueReport::totals($pdo);
check('report total for the booking = the service price; branch total grew by exactly that',
    abs(array_sum($byKind) - (float) $done['total_price']) < 0.01
    && abs(((float) ($totalsAfter['daraga'] ?? 0) - (float) ($totalsBefore['daraga'] ?? 0)) - (float) $done['total_price']) < 0.01);

// ---- Fixture: a confirmed booking with a staff-verified ₱250 cash deposit ----
$staff = (int) $pdo->query("SELECT ss.employee_id FROM staff_services ss JOIN employees e ON e.id = ss.employee_id
    WHERE ss.service_id = {$service} AND e.is_active = 1 AND e.branch_id = 1 ORDER BY e.id LIMIT 1")->fetchColumn();
$booking = function (string $when, array $set = []) use ($pdo, $p, $staff, $service): array {
    $pdo->exec("INSERT INTO appointments (reference_code, customer_id, employee_id, branch_id, appointment_datetime, total_price, preferred_payment_method,
            deposit_paid, deposit_amount, deposit_method, deposit_recorded_by, deposit_recorded_at, status, payment_status, reservation_amount_due)
        VALUES ('', {$p['customerA']}, {$staff}, 1, {$when}, 500, 'Cash', 1, 250, 'Cash', {$p['adminId']}, NOW(), 'Confirmed', 'Down Payment Verified', 250)");
    $id = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO appointment_services (appointment_id, service_id) VALUES ({$id}, {$service})");
    foreach ($set as $column => $value) $pdo->prepare("UPDATE appointments SET {$column} = ? WHERE id = ?")->execute([$value, $id]);
    return row($pdo, 'appointments', $id);
};
$adminStatus = fn(array $a, string $status) => run_endpoint(['script' => 'backend/admin/updateBookingStatus.php', 'session' => $p['admin'],
    'post' => ['id' => $a['reference_code'], 'status' => $status]]);
$review = fn(array $a, array $env = []) => run_endpoint(['script' => 'backend/cashier/updateStatus.php', 'session' => $p['cashier'], 'env' => $env,
    'post' => ['id' => $a['reference_code'], 'status' => 'In Progress', 'arrivalReview' => '1']])['review'] ?? [];
$unchanged = fn(array $a) => ($now = row($pdo, 'appointments', (int) $a['id'])) && $now['status'] === $a['status'] && $now['payment_status'] === $a['payment_status'];

// ---- 2. No-show -------------------------------------------------------------
$late3h = $booking('NOW() - INTERVAL 3 HOUR');
PayMongo::releaseExpiredHolds($pdo); // the only routine that cancels anything on its own (unpaid online holds)
$review($late3h, ['SALON_LATE_GRACE_MINUTES' => '15']);
check('3 hours late and not marked by staff -> still Confirmed, deposit untouched (nothing is automatic)', $unchanged($late3h));
$future = $booking('NOW() + INTERVAL 1 DAY');
$r = $adminStatus($future, 'No-Show');
check('a future booking cannot be marked No-Show', ($r['success'] ?? true) === false && $unchanged($future));
$r = $adminStatus($late3h, 'No-Show');
$ns = row($pdo, 'appointments', (int) $late3h['id']);
check('staff mark No-Show -> No-Show, verified deposit Forfeited (non-refundable)', ($r['success'] ?? false) === true
    && $ns['status'] === 'No-Show' && $ns['payment_status'] === 'Forfeited');
$adminStatus($late3h, 'No-Show');
check('forfeit logged once with the deposit amount, no refund recorded', log_count($pdo, 'deposit_forfeited', $ns['reference_code']) === 1
    && (float) $pdo->query("SELECT amount FROM payment_transactions WHERE event = 'deposit_forfeited' AND reference_code = '{$ns['reference_code']}'")->fetchColumn() === 250.0
    && log_count($pdo, 'refunded', $ns['reference_code']) === 0);

// ---- 3. Late arrival and the grace period -----------------------------------
$grace = ['SALON_LATE_GRACE_MINUTES' => '15'];
$within = $booking('NOW() - INTERVAL 10 MINUTE');
$beyond = $booking('NOW() - INTERVAL 25 MINUTE');
$early = $booking('NOW() + INTERVAL 20 MINUTE');
$r = $review($within, $grace);
check('10 minutes late, 15-minute grace -> "within"', ($r['graceStatus'] ?? '') === 'within' && in_array($r['minutesLate'], [10, 11], true) && $r['graceMinutes'] === 15);
$r = $review($beyond, $grace);
check('25 minutes late, 15-minute grace -> "beyond"', ($r['graceStatus'] ?? '') === 'beyond' && $r['minutesLate'] >= 25);
check('booking not started yet -> "on_time"', ($review($early, $grace)['graceStatus'] ?? '') === 'on_time');
check('no grace period configured -> "unset", no minutes shown', ($review($beyond)['graceStatus'] ?? '') === 'unset' && $review($beyond)['graceMinutes'] === null);
check('arrival reviews change no booking or payment', $unchanged($within) && $unchanged($beyond) && $unchanged($early));
$policy = fn(array $env) => run_endpoint(['script' => 'backend/public/getBookingPolicy.php', 'method' => 'GET', 'env' => $env]);
check('customers see the rule with the configured minutes, and no rule while unset',
    str_contains((string) ($policy($grace)['lateArrivalRule'] ?? ''), '15 minutes') && ($unset = $policy([])) && $unset['success'] && $unset['lateArrivalRule'] === null);
$emails = fn(array $a) => (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE reference_code = '{$a['reference_code']}' AND body LIKE '%Late arrival:%'")->fetchColumn();
putenv('SALON_LATE_GRACE_MINUTES=15');
CustomerNotifier::notify($pdo, $p['customerA'], 'CONFIRMED', "Your appointment {$future['reference_code']} is now Confirmed.", $future['reference_code']);
putenv('SALON_LATE_GRACE_MINUTES');
CustomerNotifier::notify($pdo, $p['customerA'], 'CONFIRMED', "Your appointment {$early['reference_code']} is now Confirmed.", $early['reference_code']);
check('confirmation email includes the late-arrival rule only when a grace period is set', $emails($future) === 1 && $emails($early) === 0);

// ---- 4. Cancellation with a deposit ------------------------------------------
$cancel = fn(array $a, bool $preview = false) => run_endpoint(['script' => 'backend/customer/cancelAppointment.php', 'session' => $p['sessionA'],
    'post' => ['appointment_id' => $a['reference_code']] + ($preview ? ['preview' => '1'] : [])]);
$held = $booking('NOW() + INTERVAL 5 DAY');
$r = $cancel($held, true);
check('cancel preview warns the verified deposit will be kept, and changes nothing', ($r['depositOutcome'] ?? '') === 'Forfeited' && $unchanged($held));
$r = $cancel($held);
$c = row($pdo, 'appointments', (int) $held['id']);
check('customer cancels -> Cancelled, deposit Forfeited, told it is non-refundable but can be rescheduled',
    ($r['success'] ?? false) === true && $c['status'] === 'Cancelled' && $c['payment_status'] === 'Forfeited'
    && str_contains($r['message'], 'non-refundable') && str_contains($r['message'], 'reschedule'));
$cancel($held);
check('cancelling twice -> policy applied once, no refund recorded', log_count($pdo, 'deposit_forfeited', $held['reference_code']) === 1
    && log_count($pdo, 'refunded', $held['reference_code']) === 0);
check('the kept deposit still counts as collected revenue', (bool) array_filter(RevenueReport::rows($pdo, []),
    fn($row) => $row['reference'] === $held['reference_code'] && $row['kind'] === 'Reservation payment' && abs($row['amount'] - 250) < 0.01));
$unverified = $booking('NOW() + INTERVAL 5 DAY', ['deposit_recorded_by' => null, 'payment_status' => 'Awaiting Verification']);
$cancel($unverified);
$u = row($pdo, 'appointments', (int) $unverified['id']);
check('cancel with an unverified cash deposit (never collected) -> payment status left alone, nothing forfeited',
    $u['status'] === 'Cancelled' && $u['payment_status'] === 'Awaiting Verification' && log_count($pdo, 'deposit_forfeited', $unverified['reference_code']) === 0);
$online = $booking('NOW() + INTERVAL 5 DAY', ['deposit_recorded_by' => null, 'payment_status' => 'Awaiting Verification', 'deposit_method' => 'PayMongo-GCash']);
$cancel($online);
check('cancel after a PayMongo deposit (money held, not yet verified) -> Forfeited', row($pdo, 'appointments', (int) $online['id'])['payment_status'] === 'Forfeited');

finish();
