<?php
/**
 * Live sync: one staff status everywhere, and availability that follows it.
 *
 * Drives a stylist through Shift In -> Break -> back -> with a client ->
 * Shift Out using the real endpoints, and after every step checks that the
 * Admin dashboard, the Cashier dashboard and the public roster report the
 * same status (On Shift / On Break / Busy / Off Shift), that the live-sync
 * version moved (so every open panel reloads), and that customer booking
 * offers the stylist for an immediate booking only while they are actually
 * on shift and not on a break, while future bookings follow the schedule.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_live_sync_test');
$p = test_people($pdo);
$pdo->exec("UPDATE branches SET opening_time = '00:00:00', closing_time = '23:59:59', sunday_opening_time = '00:00:00', sunday_closing_time = '23:59:59' WHERE id = 1");
$pdo->exec('DELETE FROM attendance');

$staff = $pdo->query("SELECT e.id, e.user_id, (SELECT MIN(ss.service_id) FROM staff_services ss JOIN services s ON s.id = ss.service_id
        WHERE ss.employee_id = e.id AND s.is_active = 1 AND s.branch_id = 1) AS service_id
    FROM employees e JOIN users u ON u.id = e.user_id JOIN roles r ON r.id = u.role_id
    WHERE e.branch_id = 1 AND e.is_active = 1 AND r.role_name = 'Staff'
    HAVING service_id IS NOT NULL ORDER BY e.id LIMIT 1")->fetch();
if (!$staff) exit("The dev database needs a Daraga stylist with a login and a service.\n");
$staffId = (int) $staff['id'];
$serviceId = (int) $staff['service_id'];
// A clean slate for this stylist: no schedule limits, nothing booked.
$pdo->exec("DELETE FROM staff_schedules WHERE employee_id = {$staffId}");
$pdo->exec("UPDATE appointments SET status = 'Cancelled' WHERE employee_id = {$staffId}
    OR id IN (SELECT appointment_id FROM appointment_services WHERE employee_id = {$staffId})");
$pdo->exec("UPDATE home_service_requests SET status = 'Cancelled' WHERE employee_id = {$staffId}");

$me = ['user_id' => (int) $staff['user_id'], 'user_role' => 'Staff', 'branch_id' => 1, 'user_email' => 'staff@example.test', 'csrf_token' => 'test-token'];
$shift = fn(string $action) => run_endpoint(['script' => 'backend/staff/shiftAction.php', 'session' => $me, 'post' => ['action' => $action, 'csrfToken' => 'test-token']]);
$get = fn(string $script, array $query = [], array $session = []) => run_endpoint(['script' => $script, 'method' => 'GET', 'get' => $query, 'session' => $session]);
$find = fn(array $list, $key = 'id') => array_values(array_filter($list, fn($s) => (string) $s[$key] === (string) $staffId))[0] ?? null;

/** [admin status, cashier status, cashier availability, roster status] */
$statuses = function () use ($get, $p, $find) {
    $admin = $find($get('backend/admin/getDashboardData.php', [], $p['admin'])['staffList'] ?? []);
    $cashier = $find($get('backend/cashier/getDashboardData.php', [], $p['cashier'])['staffList'] ?? []);
    $roster = null;
    foreach ($get('backend/public/getStaffRoster.php', ['branchKey' => 'daraga'])['roles'] ?? [] as $role) {
        $roster = $roster ?? $find($role['staff']);
    }
    return [$admin['status'] ?? null, $cashier['status'] ?? null, $cashier['availability'] ?? null, $roster['status'] ?? null];
};
$sameEverywhere = function (string $expected) use ($statuses) {
    [$admin, $cashier, , $roster] = $statuses();
    return $admin === $expected && $cashier === $expected && $roster === $expected;
};
$version = fn() => $get('backend/public/getSyncVersion.php', ['branch' => 'daraga'])['version'] ?? null;

$now = $pdo->query('SELECT NOW()')->fetchColumn();
$soon = date('H:i', strtotime($now) + 10 * 60);              // immediate booking
$future = free_day($pdo, 2);                                 // a later day
$offered = function (string $date, string $time) use ($get, $serviceId, $find) {
    $r = $get('backend/public/getAvailableStaff.php', ['branch' => 1, 'date' => $date, 'time' => $time, 'services' => [$serviceId]]);
    return $find($r['staff'] ?? []) !== null;
};
$today = substr($now, 0, 10);

// ---- Off Shift -----------------------------------------------------------------
check('before Shift In: Admin, Cashier and roster all say Off Shift', $sameEverywhere('Off Shift'));
check('off shift: not offered for an immediate booking', !$offered($today, $soon));
check('off shift: not offered for a future booking either', !$offered($future, '10:00'));

// ---- Shift In --------------------------------------------------------------------
$v = $version();
check('Shift In succeeds', ($shift('shift_in')['success'] ?? false) === true);
check('Shift In moves the live-sync version (every panel reloads)', $version() !== $v);
check('after Shift In: all panels say On Shift', $sameEverywhere('On Shift'));
check('on shift: offered for an immediate booking', $offered($today, $soon));
check('on shift: offered for a future booking', $offered($future, '10:00'));

// ---- Break -----------------------------------------------------------------------
$v = $version();
check('Start Break succeeds', ($shift('break_start')['success'] ?? false) === true);
check('Start Break moves the live-sync version', $version() !== $v);
check('on break: all panels say On Break (was "Available" before)', $sameEverywhere('On Break'));
check('on break: Cashier availability reads On Break', $statuses()[2] === 'On Break');
check('on break: not offered for an immediate booking', !$offered($today, $soon));
check('on break: not offered for a future booking either', !$offered($future, '10:00'));

$v = $version();
check('End Break succeeds', ($shift('break_end')['success'] ?? false) === true);
check('End Break moves the live-sync version', $version() !== $v);
check('back from break: all panels say On Shift', $sameEverywhere('On Shift'));

// ---- With a client (per-service part of someone else's booking) ----------------
$appointment = $pdo->query("SELECT a.id FROM appointments a JOIN appointment_services s ON s.appointment_id = a.id
    WHERE a.branch_id = 1 GROUP BY a.id HAVING COUNT(*) = 1 ORDER BY a.id LIMIT 1")->fetchColumn();
if (!$appointment) exit("The dev database needs a Daraga booking with one service.\n");
$other = (int) $pdo->query("SELECT id FROM employees WHERE branch_id = 1 AND id <> {$staffId} ORDER BY id LIMIT 1")->fetchColumn();
$v = $version();
$pdo->exec("UPDATE appointments SET status = 'In Progress', employee_id = {$other}, appointment_datetime = NOW() - INTERVAL 5 MINUTE WHERE id = {$appointment}");
$pdo->exec("UPDATE appointment_services SET employee_id = {$staffId} WHERE appointment_id = {$appointment}");
check('a booking assigned to the stylist moves the live-sync version', $version() !== $v);
check('doing a per-service part of a booking: all panels say Busy', $sameEverywhere('Busy'));
check('Cashier shows how long they are busy', str_starts_with((string) $statuses()[2], 'Busy until'));

$v = $version();
$pdo->exec("UPDATE appointments SET status = 'Cancelled' WHERE id = {$appointment}");
check('a cancellation moves the live-sync version', $version() !== $v);
check('after the cancellation: back to On Shift everywhere', $sameEverywhere('On Shift'));

// ---- Shift Out --------------------------------------------------------------------
$v = $version();
check('Shift Out succeeds', ($shift('shift_out')['success'] ?? false) === true);
check('Shift Out moves the live-sync version', $version() !== $v);
check('after Shift Out: all panels say Off Shift', $sameEverywhere('Off Shift'));

// ---- Cashier clock-out during a break ends the break too -------------------------
$shift('shift_in');
$shift('break_start');
sleep(1); // the clock-out must be after the clock-in (whole seconds)
$r = run_endpoint(['script' => 'backend/cashier/clockOutStaff.php', 'session' => $p['cashier'], 'post' => ['staffId' => $staffId]]);
$openBreaks = (int) $pdo->query("SELECT COUNT(*) FROM attendance_breaks b JOIN attendance a ON a.id = b.attendance_id
    WHERE a.employee_id = {$staffId} AND b.break_end IS NULL")->fetchColumn();
check('cashier clock-out during a break closes the break as well', ($r['success'] ?? false) === true && $openBreaks === 0);
check('after cashier clock-out: all panels say Off Shift', $sameEverywhere('Off Shift'));

// ---- A shift that started yesterday still counts (crossed midnight) -------------
$pdo->exec("INSERT INTO attendance (employee_id, clock_in_time) VALUES ({$staffId}, NOW() - INTERVAL 1 DAY)");
$pdo->exec("UPDATE branches SET closing_time = '23:59:59' WHERE id = 1");
$statusNow = $pdo->query("SELECT " . (function () { require_once dirname(__DIR__) . '/backend/shared/config/helpers/StaffShift.php'; return StaffShift::liveStatusSql('e'); })() . " FROM employees e WHERE e.id = {$staffId}")->fetchColumn();
check('an open shift from yesterday counts as On Shift (not only today\'s)', $statusNow === 'On Shift');

// ---- Responses can't be cached ----------------------------------------------------
$cors = file_get_contents(dirname(__DIR__) . '/backend/shared/config/setup/cors.php');
check('every endpoint sends Cache-Control: no-store', str_contains($cors, "Cache-Control: no-store"));

finish();
