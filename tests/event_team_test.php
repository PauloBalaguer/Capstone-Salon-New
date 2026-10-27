<?php
/**
 * Two events that need the same team on the same day, through the real admin
 * staff-assignment endpoint (backend/admin/assignStaff.php), the customer
 * event reschedule endpoint and the public availability endpoint.
 *
 *   C:\xampp\php\php.exe tests\event_team_test.php
 *
 * Uses the per-event durations from migration 054 (travel + setup before the
 * booked time, service + cleaning buffer after it), so it also checks that
 * the team is blocked for the real window, not the old flat 120 minutes.
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure. No API keys needed.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_event_team_test');
require_once dirname(__DIR__) . '/backend/shared/config/booking/HomeServiceRequest.php';
$p = test_people($pdo);

// Two Daraga stylists qualified for event Hair Styling; everyone else is switched off in
// this throwaway copy, so "is a team free?" depends only on these two.
$skills = [];
foreach ($pdo->query('SELECT ss.employee_id, s.service_name FROM staff_services ss JOIN services s ON s.id = ss.service_id
    JOIN employees e ON e.id = ss.employee_id WHERE s.is_active = 1 AND e.is_active = 1 AND e.branch_id = 1 ORDER BY ss.employee_id') as $r) {
    if (in_array('Hair Styling', HomeServiceRequest::serviceSkills($r['service_name']), true)) $skills[(int) $r['employee_id']] = true;
}
[$x, $y] = array_slice(array_keys($skills), 0, 2) + [null, null];
if (!$x || !$y) exit("The dev data needs two Daraga stylists with a hair styling service.\n");
$pdo->exec("UPDATE employees SET is_active = 0 WHERE id NOT IN ({$x}, {$y})");

$day = free_day($pdo, 40);
$newEvent = function (string $time) use ($pdo, $p, $day): array {
    $pdo->prepare("INSERT INTO home_service_requests (reference_code, customer_id, address, event_type, preferred_date, preferred_time,
            requests, status, payment_status, quote_price, deposit_amount, deposit_paid)
        VALUES ('', ?, 'Test venue, Daraga, Albay', 'Debut', ?, ?, 'Requested services: Hair Styling', 'Confirmed', 'Down Payment Verified', 5000, 1500, 1)")
        ->execute([$p['customerA'], $day, $time . ':00']);
    return row($pdo, 'home_service_requests', (int) $pdo->lastInsertId());
};
$assign = fn(array $event, array $team) => run_endpoint(['script' => 'backend/admin/assignStaff.php', 'session' => $p['admin'],
    'post' => ['id' => $event['reference_code'], 'staffIds' => array_map('strval', $team)]]);
// Refused for the right reason (the team's availability), not e.g. a login or validation error.
$refused = fn(array $r) => ($r['success'] ?? true) === false && preg_match('/available|not free/i', $r['message'] ?? '') === 1;
$team = function (array $event) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT employee_id FROM home_service_staff WHERE home_service_request_id = ? ORDER BY employee_id');
    $stmt->execute([$event['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

$a = $newEvent('09:00');
$window = Scheduling::homeServiceWindow($pdo, $a);
$lead = $window['travel'] + $window['setup'];
$debut = array_map('intval', $pdo->query("SELECT travel_minutes, setup_minutes, service_minutes FROM event_type_durations WHERE event_type = 'Debut'")->fetch(PDO::FETCH_NUM));
check("Debut blocks its team for its own times ({$window['travel']} travel + {$window['setup']} setup + {$window['service']} service + buffer)",
    [$window['travel'], $window['setup'], $window['service']] === $debut && $lead > 0);
$r = $assign($a, [$x]);
check('event A (09:00) gets stylist X', ($r['success'] ?? false) === true && $team($a) === [$x]
    && row($pdo, 'home_service_requests', (int) $a['id'])['status'] === 'Staff Assigned');

// Event B starts after A's service + buffer, but X must leave for B (travel + setup) before A is over.
$aEnds = $window['end']->format('H:i');
$bTime = plus_minutes($aEnds, $lead - 30);
$b = $newEvent($bTime);
check("event B at {$bTime} would have fit the old flat 2-hour block after A", strtotime("{$day} {$bTime}") >= strtotime("{$day} 09:00") + (120 + Scheduling::cleaningBufferMinutes()) * 60);
$r = $assign($b, [$x]);
check("B -> X refused: X is still at A until {$aEnds} and must leave for B earlier", $refused($r) && $team($b) === []);
$r = $assign($b, [$x, $y]);
check('B -> team X + Y refused (the whole team must be free)', $refused($r) && $team($b) === []);
$r = $assign($b, [$y]);
check('B -> Y assigned instead', ($r['success'] ?? false) === true && $team($b) === [$y]);

// Back to back: X can take event C only if leaving for C starts once A (incl. buffer) is over.
$tooEarly = $newEvent(plus_minutes($aEnds, $lead - 5));
$r = $assign($tooEarly, [$x]);
check('C 5 minutes too early for X -> refused', $refused($r));
$pdo->exec("UPDATE home_service_requests SET status = 'Cancelled' WHERE id = {$tooEarly['id']}");
$c = $newEvent(plus_minutes($aEnds, $lead));
$r = $assign($c, [$x]);
check('C exactly when X is free again -> assigned', ($r['success'] ?? false) === true && $team($c) === [$x]);

// The same window blocks salon bookings for those stylists.
check('X cannot take a salon appointment while travelling to A', Scheduling::staffHasConflict($pdo, $x, "{$day} " . $window['start']->format('H:i:s'), 30));
check('Y can still take a morning salon appointment before leaving for B', !Scheduling::staffHasConflict($pdo, $y, "{$day} 08:00:00", 30));

// Rescheduling keeps the team: moving C onto A's time is refused because X is busy then.
$r = run_endpoint(['script' => 'backend/customer/rescheduleHomeService.php', 'session' => $p['sessionA'],
    'post' => ['id' => $c['reference_code'], 'date' => $day, 'time' => '10:00']]);
check('customer moves C to 10:00 (team X busy with A) -> refused, C not moved',
    $refused($r) && substr(row($pdo, 'home_service_requests', (int) $c['id'])['preferred_time'], 0, 5) === substr($c['preferred_time'], 0, 5));

// What a new customer sees before submitting (backend/public/getHomeServiceAvailability.php).
$check = fn(string $time) => run_endpoint(['script' => 'backend/public/getHomeServiceAvailability.php', 'method' => 'GET',
    'get' => ['date' => $day, 'time' => $time, 'eventType' => 'Debut', 'weddingPackage' => '', 'services' => ['Hair Styling']]]);
$r = $check('10:00');
check('a new Debut at 10:00 that day -> shown as unavailable (X and Y both busy)', ($r['success'] ?? false) === true && $r['available'] === false);
$r = $check('19:00');
check('a new Debut in the evening -> shown as available', ($r['success'] ?? false) === true && $r['available'] === true);
$r = run_endpoint(['script' => 'backend/public/getHomeServiceBookedDates.php', 'method' => 'GET',
    'get' => ['eventType' => 'Debut', 'services' => ['Hair Styling']]]);
check('the day is not listed as fully booked (an evening start is still free)', ($r['success'] ?? false) === true && !in_array($day, $r['fullyBooked'], true));

finish();
