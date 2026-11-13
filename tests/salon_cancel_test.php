<?php
/**
 * Salon cancellation (panel item 1). Deposits stay non-refundable when the
 * customer cancels, but when the admin cancels they must choose: refund
 * (deposit -> Refund Due, then refunded with amount, reason, admin and date
 * on the payment record) or reschedule (booking stays open, deposit kept).
 * The customer gets an email and an SMS either way.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_salon_cancel_test');
require_once dirname(__DIR__) . '/backend/shared/config/booking/CancellationPolicy.php';
$p = test_people($pdo);

// Four future bookings with a verified ₱200 cash deposit, for a customer with an email and a mobile number.
$ids = $pdo->query("SELECT a.id FROM appointments a JOIN customers c ON c.id = a.customer_id
    LEFT JOIN users u ON u.id = c.user_id
    WHERE c.phone_number IS NOT NULL AND c.phone_number <> '' AND COALESCE(NULLIF(u.email, ''), c.email) IS NOT NULL ORDER BY a.id LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 4) exit("The dev database needs four bookings for a customer with a mobile number.\n");
$pdo->exec('UPDATE customers SET notify_sms = 1, notify_email = 1 WHERE id IN (SELECT customer_id FROM appointments WHERE id IN (' . implode(',', $ids) . '))');
$pdo->exec("UPDATE appointments SET status = 'Confirmed', payment_status = 'Down Payment Verified', deposit_paid = 1, deposit_amount = 200,
    deposit_method = 'Cash', deposit_reference = NULL, deposit_recorded_by = {$p['adminId']}, appointment_datetime = NOW() + INTERVAL 3 DAY,
    cancelled_at = NULL, cancel_reason = NULL WHERE id IN (" . implode(',', $ids) . ')');
$ref = fn(int $id) => $pdo->query("SELECT reference_code FROM appointments WHERE id = {$id}")->fetchColumn();
$row = fn(int $id) => $pdo->query("SELECT status, payment_status, cancel_reason, cancelled_by FROM appointments WHERE id = {$id}")->fetch();
$cancel = fn(int $id, array $extra) => run_endpoint(['script' => 'backend/admin/updateBookingStatus.php', 'session' => $p['admin'],
    'post' => ['id' => $ref($id), 'status' => 'Cancelled'] + $extra]);
[$a, $b, $c, $d] = array_map('intval', $ids);

check('admin cancel without a choice is refused', !($cancel($a, ['reason' => 'Stylist absent'])['success'] ?? false));
check('admin cancel without a reason is refused', !($cancel($a, ['salonAction' => 'refund', 'reason' => ' '])['success'] ?? false));
check('refused attempts change nothing', $row($a)['status'] === 'Confirmed');

$emailsBefore = (int) $pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();
$smsBefore = (int) $pdo->query('SELECT COUNT(*) FROM sms_outbox')->fetchColumn();
$r = $cancel($a, ['salonAction' => 'refund', 'reason' => 'Your stylist is absent today']);
$after = $row($a);
check('refund: booking cancelled, deposit becomes Refund Due', ($r['success'] ?? false) && $after['status'] === 'Cancelled' && $after['payment_status'] === 'Refund Due');
check('refund: reason and admin saved on the booking', $after['cancel_reason'] === 'Your stylist is absent today' && (int) $after['cancelled_by'] === (int) $p['adminId']);
$log = $pdo->query("SELECT amount, details, created_at FROM payment_transactions WHERE appointment_id = {$a} AND event = 'refund_due' ORDER BY id DESC LIMIT 1")->fetch();
$details = json_decode((string) ($log['details'] ?? ''), true) ?: [];
check('refund: payment record has amount, reason, admin and date', $log && (float) $log['amount'] === 200.0
    && ($details['reason'] ?? '') === 'Your stylist is absent today' && (int) ($details['by_user_id'] ?? 0) === (int) $p['adminId'] && $log['created_at']);
check('refund: customer emailed', (int) $pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() > $emailsBefore
    && (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE subject LIKE 'We had to cancel your appointment%' AND body LIKE '%refunded in full%'")->fetchColumn() === 1);
check('refund: customer texted', (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE type = 'SALON_CANCELLED'")->fetchColumn() === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM sms_outbox')->fetchColumn() > $smsBefore);

$r = run_endpoint(['script' => 'backend/admin/refundDeposit.php', 'session' => $p['admin'], 'post' => ['reference' => $ref($a)]]);
$done = $pdo->query("SELECT details FROM payment_transactions WHERE appointment_id = {$a} AND event = 'refunded' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('manual refund recorded as Refunded with the reason', ($r['success'] ?? false) && $row($a)['payment_status'] === 'Refunded'
    && (json_decode((string) $done, true)['reason'] ?? '') === 'Your stylist is absent today');
check('customer texted when the refund is done', (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE type = 'PAYMENT_REFUNDED'")->fetchColumn() === 1);

$r = $cancel($b, ['salonAction' => 'reschedule', 'reason' => 'Your stylist is absent today']);
$after = $row($b);
check('reschedule: booking stays open as Reschedule Requested (customer can move it), deposit kept',
    ($r['success'] ?? false) && $after['status'] === 'Reschedule Requested' && $after['payment_status'] === 'Down Payment Verified');
check('reschedule: customer texted', (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE type = 'RESCHEDULE'")->fetchColumn() >= 1);

// Cashier: same choice, plus "the customer asked" (deposit kept).
$cashier = fn(int $id, array $extra) => run_endpoint(['script' => 'backend/cashier/updateStatus.php', 'session' => $p['cashier'],
    'post' => ['id' => $ref($id), 'status' => 'Cancelled'] + $extra]);
$pdo->exec("UPDATE appointments SET branch_id = 1 WHERE id IN ({$c}, {$d})");
check('cashier cancel without a choice is refused', !($cashier($c, ['reason' => 'Stylist absent'])['success'] ?? false) && $row($c)['status'] === 'Confirmed');
$r = $cashier($c, ['cancelAction' => 'refund', 'reason' => 'Your stylist is absent today']);
check('cashier refund: Cancelled with the deposit Refund Due', ($r['success'] ?? false) && $row($c)['status'] === 'Cancelled' && $row($c)['payment_status'] === 'Refund Due');
$r = $cashier($d, ['cancelAction' => 'customer', 'reason' => 'Customer is travelling']);
check('cashier "customer asked": Cancelled, deposit forfeited', ($r['success'] ?? false) && $row($d)['status'] === 'Cancelled' && $row($d)['payment_status'] === 'Forfeited'
    && $row($d)['cancel_reason'] === 'Customer is travelling');

// The customer cancelling (or a no-show) still keeps the deposit.
$held = ['payment_status' => 'Down Payment Verified', 'deposit_method' => 'Cash', 'deposit_paid' => 1];
check('customer cancel: deposit still forfeited', CancellationPolicy::outcome($pdo, $held, CancellationPolicy::BY_CUSTOMER) === 'Forfeited');
check('no-show: deposit still forfeited', CancellationPolicy::outcome($pdo, $held, CancellationPolicy::NO_SHOW) === 'Forfeited');
finish();
