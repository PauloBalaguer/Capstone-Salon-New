<?php
/**
 * Payment status wording (panel item 3): Unpaid / Deposit paid · ₱X balance
 * due / Fully paid, the same in emails (PHP) and on every screen (JS).
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_payment_wording_test');
require_once dirname(__DIR__) . '/backend/shared/config/notifications/CustomerNotifier.php';
$p = test_people($pdo);

check('unpaid', PaymentStatus::label('Payment Required') === 'Unpaid');
check('deposit paid shows the balance', PaymentStatus::label('Down Payment Verified', 1250) === 'Deposit paid · ₱1,250.00 balance due');
check('deposit paid with nothing left has no balance text', PaymentStatus::label('Down Payment Verified', 0) === 'Deposit paid');
check('fully paid', PaymentStatus::label('Fully Paid', 500) === 'Fully paid');

// The browser table (portal-presentation.js) must say exactly the same.
$js = file_get_contents(dirname(__DIR__) . '/function/shared/portal-presentation.js');
preg_match('/PAYMENT_LABELS: \{(.*?)\}/s', $js, $m);
preg_match_all("/'([^']+)': '([^']+)'/", $m[1] ?? '', $pairs, PREG_SET_ORDER);
check('screens and emails use the same words', array_column($pairs, 2, 1) === PaymentStatus::LABELS);

// Email for a booking with a ₱500 verified deposit on ₱1,500.
$id = (int) $pdo->query('SELECT id FROM appointments ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("UPDATE appointments SET customer_id = {$p['customerA']} WHERE id = {$id}");
$pdo->exec("UPDATE customers SET email = 'wording-test@example.com', notify_email = 1 WHERE id = {$p['customerA']}");
$pdo->exec("UPDATE users u JOIN customers c ON c.user_id = u.id SET u.email = 'wording-test@example.com' WHERE c.id = {$p['customerA']}");
$pdo->exec("UPDATE appointments SET status = 'Confirmed', appointment_datetime = NOW() + INTERVAL 2 DAY, payment_status = 'Down Payment Verified', total_price = 1500, deposit_paid = 1,
    deposit_amount = 500, deposit_recorded_by = {$p['adminId']} WHERE id = {$id}");
$ref = $pdo->query("SELECT reference_code FROM appointments WHERE id = {$id}")->fetchColumn();
CustomerNotifier::notify($pdo, $p['customerA'], 'CONFIRMED', "Your appointment {$ref} is now Confirmed.", $ref);
$body = (string) $pdo->query("SELECT body FROM email_outbox WHERE to_email = 'wording-test@example.com' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('email shows "Deposit paid · ₱1,000.00 balance due"', str_contains($body, 'Payment: Deposit paid · ₱1,000.00 balance due'));

$pdo->exec("UPDATE appointments SET payment_status = 'Fully Paid', deposit_amount = 1500 WHERE id = {$id}");
CustomerNotifier::notify($pdo, $p['customerA'], 'REMINDER', "Reminder for {$ref}.", $ref);
$body = (string) $pdo->query("SELECT body FROM email_outbox WHERE to_email = 'wording-test@example.com' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('email shows "Fully paid" with no balance', str_contains($body, 'Payment: Fully paid') && !str_contains($body, 'balance due'));

// Cashier list gets the balance the label needs.
$pdo->exec("UPDATE appointments SET payment_status = 'Down Payment Verified', deposit_amount = 500, branch_id = 1, appointment_datetime = NOW() + INTERVAL 1 HOUR WHERE id = {$id}");
$r = run_endpoint(['script' => 'backend/cashier/getDashboardData.php', 'method' => 'GET', 'session' => $p['cashier']]);
$row = array_values(array_filter($r['bookings'] ?? [], fn($b) => ($b['id'] ?? '') === $ref))[0] ?? null;
check('cashier list includes the balance due', $row && (float) $row['remainingBalance'] === 1000.0);

finish();
