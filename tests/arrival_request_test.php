<?php
/**
 * Late or absent customers: the stylist asks, the cashier decides.
 *
 * 1. A stylist can request a no-show only past the grace period, and the
 *    request changes neither the booking nor the payment.
 * 2. The request reaches this branch's cashiers, not other branches'.
 * 3. A cashier can mark a no-show only for their own branch, on a confirmed
 *    booking past the grace period. The verified deposit is kept once.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_arrival_test');
$p = test_people($pdo);
$grace = ['SALON_LATE_GRACE_MINUTES' => '15'];
$today = date('Y-m-d');
$pdo->exec("DELETE FROM daily_closures WHERE business_date = CURDATE()");

$staff = $pdo->query("SELECT id, user_id FROM employees WHERE branch_id = 1 AND user_id IS NOT NULL AND is_active = 1 ORDER BY id LIMIT 2")->fetchAll();
if (count($staff) < 2) exit("The dev database needs two Daraga stylists with logins.\n");
$session = fn(array $s) => ['user_id' => (int) $s['user_id'], 'user_role' => 'Staff', 'branch_id' => 1, 'user_email' => 'staff@example.test', 'csrf_token' => 'test-token'];
[$stylist, $other] = [$session($staff[0]), $session($staff[1])];

// Bookings borrowed from the copy, set to a known state.
$rows = $pdo->query('SELECT a.id, a.reference_code FROM appointments a JOIN appointment_services s ON s.appointment_id = a.id GROUP BY a.id ORDER BY a.id LIMIT 4')->fetchAll();
if (count($rows) < 4) exit("The dev database needs four appointments with services.\n");
$pdo->exec('DELETE FROM payments WHERE appointment_id IN (' . implode(',', array_column($rows, 'id')) . ')');
$prepare = $pdo->prepare("UPDATE appointments SET branch_id = 1, employee_id = ?, customer_id = ?, status = 'Confirmed', payment_status = 'Down Payment Verified',
    deposit_paid = 1, deposit_amount = 250, deposit_method = 'Cash', cancelled_at = NULL, appointment_datetime = NOW() + INTERVAL ? MINUTE WHERE id = ?");
$book = function (array $row, int $minutesFromNow) use ($prepare, $staff, $p) {
    $prepare->execute([$staff[0]['id'], $p['customerA'], $minutesFromNow, $row['id']]);
    return $row['reference_code'];
};
$within = $book($rows[0], -10);
$beyond = $book($rows[1], -30);
$future = $book($rows[2], 60);
$beyond2 = $book($rows[3], -45);

$state = function (string $ref) use ($pdo) {
    $stmt = $pdo->prepare('SELECT status, payment_status FROM appointments WHERE reference_code = ?');
    $stmt->execute([$ref]);
    return $stmt->fetch();
};
$ask = fn(array $session, string $ref, string $kind) => run_endpoint(['script' => 'backend/staff/requestArrivalHelp.php', 'session' => $session, 'post' => ['id' => $ref, 'kind' => $kind, 'csrfToken' => 'test-token'], 'env' => $grace]);
$cashierMark = fn(array $session, string $ref) => run_endpoint(['script' => 'backend/cashier/updateStatus.php', 'session' => $session, 'post' => ['id' => $ref, 'status' => 'No-Show'], 'env' => $grace]);
$noticeCount = function (int $userId, string $ref) use ($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'ARRIVAL_REQUEST' AND message LIKE ?");
    $stmt->execute([$userId, "%{$ref}%"]);
    return (int) $stmt->fetchColumn();
};

// ---- 1. Stylist request ------------------------------------------------------
$r = run_endpoint(['script' => 'backend/staff/requestArrivalHelp.php', 'session' => $stylist, 'post' => ['id' => $beyond, 'kind' => 'no_show'], 'env' => $grace]);
check('a request without the CSRF token -> refused', ($r['csrfExpired'] ?? false) === true);
preg_match_all("/requestArrivalHelp\(appt\.id, '(\w+)'\)/", file_get_contents(dirname(__DIR__) . '/frontend/staff/dashboard.html'), $pageKinds);
check('the Staff Portal only sends request types the server accepts', $pageKinds[1] && !array_diff($pageKinds[1], ['no_show', 'reschedule']));

$r = $ask($stylist, $within, 'no_show');
check('10 minutes late, 15-minute grace -> no-show request refused', ($r['success'] ?? true) === false);
$r = $ask($stylist, $future, 'reschedule');
check('a booking that has not started -> request refused', ($r['success'] ?? true) === false);
$r = $ask($other, $beyond, 'no_show');
check('a stylist not on the booking -> request refused', ($r['success'] ?? true) === false);
$r = $ask($stylist, $beyond, 'bad_kind');
check('an unknown request type -> refused', ($r['success'] ?? true) === false);

$r = $ask($stylist, $beyond, 'no_show');
check('30 minutes late -> no-show request accepted', ($r['success'] ?? false) === true);
$s = $state($beyond);
check('the request changes neither booking nor payment', $s['status'] === 'Confirmed' && $s['payment_status'] === 'Down Payment Verified');
$r = $ask($stylist, $beyond, 'no_show');
check('sending the same request twice -> refused', ($r['success'] ?? true) === false);
$r = $ask($stylist, $within, 'reschedule');
check('a reschedule request is allowed inside the grace period', ($r['success'] ?? false) === true);

// ---- 2. Who hears about it ---------------------------------------------------
check('this branch\'s cashier is notified once', $noticeCount($p['cashier']['user_id'], $beyond) === 1);
$away = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.role_name = 'Cashier' AND u.branch_id != 1 AND u.branch_id IS NOT NULL ORDER BY u.id LIMIT 1")->fetchColumn();
if ($away) check('another branch\'s cashier is not notified', $noticeCount((int) $away, $beyond) === 0);

$dash = run_endpoint(['script' => 'backend/staff/getDashboardData.php', 'method' => 'GET', 'session' => $stylist, 'env' => $grace]);
$mine = array_column($dash['appointments'] ?? [], null, 'id');
check('the stylist sees the lateness and the sent request', ($mine[$beyond]['minutesLate'] ?? 0) >= 30 && ($mine[$beyond]['arrivalRequests'] ?? []) === ['no_show'] && ($dash['lateGraceMinutes'] ?? null) === 15);

$desk = run_endpoint(['script' => 'backend/cashier/getDashboardData.php', 'method' => 'GET', 'session' => $p['cashier'], 'env' => $grace]);
$queue = array_column($desk['bookings'] ?? [], null, 'id');
check('the cashier sees the request', ($queue[$beyond]['arrivalRequests'] ?? []) === ['no_show'] && ($queue[$beyond]['canMarkNoShow'] ?? false) === true);
check('Mark No-Show is not offered inside the grace period or before the start', ($queue[$within]['canMarkNoShow'] ?? true) === false && ($queue[$future]['canMarkNoShow'] ?? true) === false);

// ---- 3. Cashier decision -----------------------------------------------------
$r = run_endpoint(['script' => 'backend/staff/updateAppointment.php', 'session' => $stylist, 'post' => ['id' => $beyond, 'status' => 'No-Show', 'csrfToken' => 'test-token'], 'env' => $grace]);
check('a stylist still cannot mark a no-show directly', ($r['success'] ?? true) === false && $state($beyond)['status'] === 'Confirmed');
$r = $cashierMark($p['cashier'], $within);
check('cashier: inside the grace period -> refused', ($r['success'] ?? true) === false && $state($within)['status'] === 'Confirmed');
$r = $cashierMark($p['cashier'], $future);
check('cashier: a future booking -> refused', ($r['success'] ?? true) === false && $state($future)['status'] === 'Confirmed');
if ($away) {
    $r = $cashierMark(['user_id' => (int) $away, 'user_role' => 'Cashier', 'branch_id' => (int) $pdo->query("SELECT branch_id FROM users WHERE id = " . (int) $away)->fetchColumn()], $beyond);
    check('cashier: another branch\'s booking -> refused', ($r['success'] ?? true) === false && $state($beyond)['status'] === 'Confirmed');
}

$r = $cashierMark($p['cashier'], $beyond);
$s = $state($beyond);
check('cashier: past the grace period -> No-Show, deposit kept', ($r['success'] ?? false) === true && $s['status'] === 'No-Show' && $s['payment_status'] === 'Forfeited');
$cashierMark($p['cashier'], $beyond);
$logged = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE event = 'deposit_forfeited' AND reference_code = ?");
$logged->execute([$beyond]);
check('marking it twice keeps the deposit only once', (int) $logged->fetchColumn() === 1);
$told = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'SCHEDULE_CHANGED' AND message LIKE ?");
$told->execute([$staff[0]['user_id'], "%{$beyond}%"]);
check('the stylist is told the time is free again', (int) $told->fetchColumn() === 1);

$pdo->prepare("INSERT INTO payments (appointment_id, amount, payment_method, status) VALUES ((SELECT id FROM appointments WHERE reference_code = ?), 500, 'Cash', 'Paid')")->execute([$beyond2]);
$r = $cashierMark($p['cashier'], $beyond2);
check('cashier: a visit already checked out -> refused', ($r['success'] ?? true) === false && $state($beyond2)['status'] === 'Confirmed');

finish();
