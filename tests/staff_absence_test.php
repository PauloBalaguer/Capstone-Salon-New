<?php
/**
 * Staff absence (panel item 2). Admin marks a stylist on leave for a date,
 * sees that stylist's bookings for the date, and reassigns each one to a
 * free same-branch stylist (or cancels it with the item 1 flow). A stylist
 * on leave can't be booked.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_staff_absence_test');
require_once dirname(__DIR__) . '/backend/shared/config/booking/Scheduling.php';
$p = test_people($pdo);

// Branch 1: absent stylist X, stand-in Y (both can do service S, branch hours), Z at another branch.
[$x, $y] = array_map('intval', $pdo->query('SELECT id FROM employees WHERE branch_id = 1 AND is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
$z = (int) $pdo->query('SELECT id FROM employees WHERE branch_id <> 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$s = (int) $pdo->query('SELECT id FROM services WHERE duration_minutes BETWEEN 20 AND 90 ORDER BY id LIMIT 1')->fetchColumn();
if (!$x || !$y || !$z || !$s) exit("The dev database needs two stylists at branch 1, one elsewhere, and a service.\n");
$pdo->exec("DELETE FROM staff_schedules WHERE employee_id IN ({$x}, {$y})");
$pdo->exec("DELETE FROM staff_leave WHERE employee_id IN ({$x}, {$y})");
foreach ([$x, $y, $z] as $e) $pdo->exec("INSERT IGNORE INTO staff_services (employee_id, service_id) VALUES ({$e}, {$s})");
$day = free_day($pdo, 5, 1);

// Two bookings with X at 10:00 that day: A names X on its service row, B only as main stylist.
$customer = $p['customerA'];
$pdo->exec("UPDATE customers SET email = 'absence-test@example.com', notify_email = 1, notify_sms = 1, phone_number = '09171234567' WHERE id = {$customer}");
$pdo->exec("UPDATE users u JOIN customers c ON c.user_id = u.id SET u.email = 'absence-test@example.com' WHERE c.id = {$customer}");
[$a, $b] = array_map('intval', $pdo->query('SELECT id FROM appointments ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
$pdo->exec("UPDATE appointments SET branch_id = 1, customer_id = {$customer}, employee_id = {$x}, status = 'Confirmed',
    appointment_datetime = '{$day} 10:00:00' WHERE id IN ({$a}, {$b})");
$pdo->exec("UPDATE appointments SET status = 'Pending' WHERE id = {$b}");
$pdo->exec("DELETE FROM appointment_services WHERE appointment_id IN ({$a}, {$b})");
$pdo->exec("INSERT INTO appointment_services (appointment_id, service_id, employee_id) VALUES ({$a}, {$s}, {$x}), ({$b}, {$s}, NULL)");
$ref = fn(int $id) => $pdo->query("SELECT reference_code FROM appointments WHERE id = {$id}")->fetchColumn();
$call = fn(array $post) => run_endpoint(['script' => 'backend/admin/staffSchedule.php', 'session' => $p['admin'], 'post' => $post]);

$r = $call(['action' => 'addLeave', 'employeeId' => $x, 'startDate' => $day, 'endDate' => $day, 'reason' => 'Sick', 'confirmed' => 1]);
check('leave saved; both bookings (Confirmed and Pending) flagged for follow-up', ($r['success'] ?? false)
    && (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE id IN ({$a}, {$b}) AND needs_followup IS NOT NULL")->fetchColumn() === 2);

$avail = Scheduling::staffAvailability($pdo, $x, "{$day} 10:00:00", 30);
check('absent stylist is not bookable that day (On leave)', !$avail['available'] && $avail['code'] === 'leave');

$r = $call(['action' => 'leaveBookings', 'employeeId' => $x, 'startDate' => $day, 'endDate' => $day]);
$list = array_column($r['bookings'] ?? [], null, 'reference');
$optionIds = fn(array $list, string $reference) => array_map('intval', array_column($list[$reference]['replacements'] ?? [], 'id'));
check('list shows the absent stylist\'s bookings for the date', isset($list[$ref($a)], $list[$ref($b)]));
check('stand-in from the same branch is offered; the absent one and other branches are not',
    in_array($y, $optionIds($list, $ref($a)), true) && !in_array($x, $optionIds($list, $ref($a)), true) && !in_array($z, $optionIds($list, $ref($a)), true));
check('listing changes nothing', (int) $pdo->query("SELECT employee_id FROM appointments WHERE id = {$a}")->fetchColumn() === $x);

// Should fail
$r = $call(['action' => 'reassignLeaveBooking', 'employeeId' => $x, 'reference' => $ref($a), 'staffId' => $z]);
check('reassigning to a stylist from another branch is refused', !($r['success'] ?? true)
    && (int) $pdo->query("SELECT employee_id FROM appointments WHERE id = {$a}")->fetchColumn() === $x);
$r = $call(['action' => 'reassignLeaveBooking', 'employeeId' => $x, 'reference' => $ref($a), 'staffId' => $x]);
check('reassigning to the absent stylist is refused', !($r['success'] ?? true));

$emails = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE subject LIKE 'Your stylist has changed%'")->fetchColumn();
$r = $call(['action' => 'reassignLeaveBooking', 'employeeId' => $x, 'reference' => $ref($a), 'staffId' => $y]);
$row = $pdo->query("SELECT employee_id, needs_followup FROM appointments WHERE id = {$a}")->fetch();
check('reassign: booking and its service row move to the stand-in, follow-up cleared', ($r['success'] ?? false)
    && (int) $row['employee_id'] === $y && $row['needs_followup'] === null
    && (int) $pdo->query("SELECT employee_id FROM appointment_services WHERE appointment_id = {$a}")->fetchColumn() === $y);
check('reassign: customer emailed and texted', (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE subject LIKE 'Your stylist has changed%'")->fetchColumn() === $emails + 1
    && (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE type = 'STYLIST_CHANGED'")->fetchColumn() >= 1);

$r = $call(['action' => 'leaveBookings', 'employeeId' => $x, 'startDate' => $day, 'endDate' => $day]);
$list = array_column($r['bookings'] ?? [], null, 'reference');
check('reassigned booking leaves the list', !isset($list[$ref($a)]) && isset($list[$ref($b)]));
check('stand-in now busy at 10:00, so not offered for the other booking', !in_array($y, $optionIds($list, $ref($b)), true));
$r = $call(['action' => 'reassignLeaveBooking', 'employeeId' => $x, 'reference' => $ref($b), 'staffId' => $y]);
check('double-booking the stand-in is refused', !($r['success'] ?? true)
    && (int) $pdo->query("SELECT employee_id FROM appointments WHERE id = {$b}")->fetchColumn() === $x);

finish();
