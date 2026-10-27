<?php
/**
 * Double-booking checks for salon appointments, through the real customer
 * booking endpoint (backend/customer/submitBooking.php).
 *
 *   C:\xampp\php\php.exe tests\booking_concurrency_test.php
 *
 * 1. Two customers book the same stylist and slot at the same time, from two
 *    parallel PHP processes: exactly one booking may succeed.
 * 2. Overlapping windows (not just identical start times), the 30-minute
 *    cleaning buffer, and several services booked back to back.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure. No API keys needed.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_concurrency_test');
require_once dirname(__DIR__) . '/backend/shared/config/booking/Scheduling.php';
$p = test_people($pdo);

// A 60-minute Daraga service at least two stylists can do, and a second service the first stylist can also do.
$qualified = 'SELECT e.id FROM employees e JOIN staff_services ss ON ss.employee_id = e.id WHERE ss.service_id = ? AND e.is_active = 1 AND e.branch_id = 1 ORDER BY e.id';
$service = (int) $pdo->query("SELECT s.id FROM services s WHERE s.branch_id = 1 AND s.is_active = 1 AND s.duration_minutes = 60
    AND (SELECT COUNT(*) FROM staff_services ss JOIN employees e ON e.id = ss.employee_id WHERE ss.service_id = s.id AND e.is_active = 1 AND e.branch_id = 1) >= 2
    ORDER BY s.id LIMIT 1")->fetchColumn();
$stmt = $pdo->prepare($qualified);
$stmt->execute([$service]);
[$x, $y] = array_map('intval', array_slice($stmt->fetchAll(PDO::FETCH_COLUMN), 0, 2));
$stmt = $pdo->prepare('SELECT s.id, s.duration_minutes FROM services s JOIN staff_services ss ON ss.service_id = s.id
    WHERE ss.employee_id = ? AND s.id != ? AND s.branch_id = 1 AND s.is_active = 1 AND s.duration_minutes BETWEEN 30 AND 90 ORDER BY s.id LIMIT 1');
$stmt->execute([$x, $service]);
$second = $stmt->fetch();
if (!$service || !$x || !$y || !$second) exit("The dev data needs a 60-minute Daraga service with two qualified stylists.\n");
$secondId = (int) $second['id'];
$secondMinutes = (int) $second['duration_minutes'];

$book = fn(array $session, int $staff, array $services, string $date, string $time, float $startAt = 0) => [
    'script' => 'backend/customer/submitBooking.php', 'session' => $session, 'startAt' => $startAt,
    'post' => ['branch' => 'daraga', 'serviceIds' => array_map('strval', $services), 'date' => $date, 'time' => $time, 'staffId' => (string) $staff,
        'customerName' => 'Test Customer', 'customerPhone' => '09171234567', 'paymentMethod' => 'Cash', 'agreedToTerms' => '1'],
];
$staffBookings = function (int $staff, string $date) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE employee_id = ? AND DATE(appointment_datetime) = ? AND status NOT IN ('Cancelled', 'No-Show')");
    $stmt->execute([$staff, $date]);
    return (int) $stmt->fetchColumn();
};
$oneWinner = function (array $results): bool {
    $won = array_filter($results, fn($r) => ($r['success'] ?? false) === true);
    $lost = array_filter($results, fn($r) => ($r['success'] ?? true) === false && ($r['conflict'] ?? false) === true
        && $r['message'] === Scheduling::SLOT_UNAVAILABLE_MESSAGE);
    return count($won) === 1 && count($lost) === 1;
};

// ---- 1. Same stylist, same slot, two parallel requests ----------------------
// The test holds the branch row lock that every booking takes first, starts
// both requests, and waits until BOTH are blocked inside their booking
// transaction -- so they really are in flight at the same moment -- then lets go.
$day = free_day($pdo, 30);
$pdo->beginTransaction();
$pdo->query('SELECT id FROM branches WHERE id = 1 FOR UPDATE')->fetchAll();
$first = start_endpoint($book($p['sessionA'], $x, [$service], $day, '10:00'));
$secondReq = start_endpoint($book($p['sessionB'], $x, [$service], $day, '10:00'));
$waiting = 0;
for ($deadline = microtime(true) + 20; $waiting < 2 && microtime(true) < $deadline; usleep(100000)) {
    $waiting = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS')->fetchColumn();
}
check('both booking requests are in their transaction at the same time', $waiting >= 2);
$pdo->commit();
$results = [endpoint_result($first), endpoint_result($secondReq)];
check('same stylist + same slot in parallel -> exactly one booked, the other told the slot is unavailable', $oneWinner($results));
check('the database holds one appointment for that stylist that day', $staffBookings($x, $day) === 1);

// Free-running races (no held lock), started on the same instant: identical and overlapping starts.
$day2 = free_day($pdo, 31);
foreach ([['08:30', '09:00'], ['11:00', '11:45'], ['14:00', '14:10']] as [$timeA, $timeB]) {
    $startAt = microtime(true) + 1.5;
    $handles = [start_endpoint($book($p['sessionA'], $x, [$service], $day2, $timeA, $startAt)),
        start_endpoint($book($p['sessionB'], $x, [$service], $day2, $timeB, $startAt))];
    $results = array_map('endpoint_result', $handles);
    check("parallel {$timeA} vs {$timeB} on the same stylist -> exactly one booked", $oneWinner($results));
}
check('three races -> three appointments for that stylist, no double booking', $staffBookings($x, $day2) === 3);

// Different stylists at the same time are not a conflict: both go through.
$startAt = microtime(true) + 1.5;
$h1 = start_endpoint($book($p['sessionA'], $x, [$service], $day2, '17:00', $startAt));
$h2 = start_endpoint($book($p['sessionB'], $y, [$service], $day2, '17:00', $startAt));
$r1 = endpoint_result($h1);
$r2 = endpoint_result($h2);
check('parallel bookings for two different stylists at 17:00 -> both booked', ($r1['success'] ?? false) && ($r2['success'] ?? false));

// ---- 2. Overlapping windows, cleaning buffer, back-to-back services --------
$buffer = Scheduling::cleaningBufferMinutes();
$day3 = free_day($pdo, 32);
$a = run_endpoint($book($p['sessionA'], $x, [$service], $day3, '10:00'));
check('customer A books the stylist 10:00-11:00', ($a['success'] ?? false) === true);
foreach ([
    ['10:30', 'starting halfway through A'],
    ['09:30', 'ending halfway through A'],
    ['09:00', 'ending exactly when A starts, before the cleaning buffer'],
    [plus_minutes('10:00', 60), 'right when A ends, inside the cleaning buffer'],
] as [$time, $why]) {
    $r = run_endpoint($book($p['sessionB'], $x, [$service], $day3, $time));
    check("B at {$time} ({$why}) -> refused", ($r['success'] ?? true) === false && ($r['conflict'] ?? false) === true);
}
$r = run_endpoint($book($p['sessionB'], $x, [$service], $day3, plus_minutes('10:00', 60 + $buffer)));
check('B right after A + the ' . $buffer . '-minute buffer -> booked (back to back)', ($r['success'] ?? false) === true);
$r = run_endpoint($book($p['sessionB'], $y, [$service], $day3, '10:30'));
check('B at 10:30 with another stylist -> booked (only the busy stylist is blocked)', ($r['success'] ?? false) === true);

// Several services in one booking run back to back, so the stylist is busy for their total.
$multi = run_endpoint($book($p['sessionA'], $x, [$service, $secondId], $day3, '14:00'));
check('A books two services at 14:00 -> one booking of ' . (60 + $secondMinutes) . ' minutes',
    ($multi['success'] ?? false) === true && (int) $multi['appointment']['durationMinutes'] === 60 + $secondMinutes);
$r = run_endpoint($book($p['sessionB'], $x, [$service], $day3, plus_minutes('14:00', 60)));
check('B when A\'s first service ends (stylist now on the second) -> refused', ($r['success'] ?? true) === false && ($r['conflict'] ?? false) === true);
$r = run_endpoint($book($p['sessionB'], $x, [$service], $day3, plus_minutes('14:00', 60 + $secondMinutes)));
check('B when both services end, inside the buffer -> refused', ($r['success'] ?? true) === false && ($r['conflict'] ?? false) === true);
$r = run_endpoint($book($p['sessionB'], $x, [$service], $day3, plus_minutes('14:00', 60 + $secondMinutes + $buffer)));
check('B after both services + buffer -> booked', ($r['success'] ?? false) === true);

finish();
