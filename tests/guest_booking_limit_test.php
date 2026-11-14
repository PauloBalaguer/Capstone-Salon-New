<?php
require __DIR__ . '/support/harness.php';
require_once __DIR__ . '/../backend/shared/config/booking/GuestBookingLimit.php';

$pdo = clone_database('capstone_guest_limit_test');
$phone = '09990007661';
$email = 'guest.limit@example.test';
$day = free_day($pdo, 30);
$service = $pdo->prepare('SELECT id FROM services WHERE branch_id = ? AND is_active = 1 AND duration_minutes = 60 ORDER BY id LIMIT 1');
$service->execute([1]);
$serviceId = (int) $service->fetchColumn();
$staff = $pdo->prepare('SELECT ss.employee_id FROM staff_services ss JOIN employees e ON e.id = ss.employee_id WHERE ss.service_id = ? AND e.is_active = 1 AND e.branch_id = ? ORDER BY e.id LIMIT 1');
$staff->execute([$serviceId, 1]);
$salon = ['fullname' => 'Limit Tester', 'contact' => $phone, 'email' => $email, 'branch' => '1',
    'services' => [(string) $serviceId], 'staff_id' => (string) $staff->fetchColumn(),
    'appointment_date' => $day, 'time_slot' => '10:00', 'otp_code' => '123456',
    'payment_method' => 'Cash', 'agreedToTerms' => '1'];
$home = ['fullname' => 'Limit Tester', 'contact' => $phone, 'email' => $email,
    'structuredAddress' => json_encode(['province' => '050500000', 'city' => '050504000', 'barangay' => '050504025', 'house' => '12', 'street' => 'Rizal Street', 'subdivision' => '', 'landmark' => '']),
    'eventType' => 'Wedding', 'weddingPackage' => 'A', 'date' => $day,
    'time' => '14:00', 'clients' => '2', 'agreedToTerms' => '1', 'branchKey' => 'daraga'];
$run = function (string $endpoint, array $post) use ($pdo): array {
    $pdo->prepare('DELETE FROM rate_limit WHERE identifier = ?')->execute(['127.0.0.1']);
    return run_endpoint(['script' => "backend/public/{$endpoint}.php", 'post' => $post]);
};
$pdo->prepare('INSERT INTO booking_verifications (email, code, expires_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE)')->execute([$email, '123456']);
$r = $run('submitGuestBooking', $salon);
check('first guest salon booking succeeds', ($r['success'] ?? false) === true);
$pdo->prepare("UPDATE appointments SET status = 'Cancelled' WHERE reference_code = ?")->execute([$r['reference'] ?? '']);
check('cancellation still counts; matching phone and email counts the booking once', GuestBookingLimit::count($pdo, $phone, $email) === 1);
$r = $run('submitGuestHomeService', $home);
check('second guest booking can be home service', ($r['success'] ?? false) === true);
check('salon and home service share the limit', GuestBookingLimit::count($pdo, $phone, $email) === GuestBookingLimit::LIMIT);

foreach ([[$phone, 'different@example.test'], ['09990007662', strtoupper($email)], ['+639990007661', ''], [$phone, $email]] as [$mobile, $address]) {
    $r = $run('sendBookingOtp', ['phone' => $mobile, 'email' => $address]);
    check("OTP stops at the limit for {$mobile} / {$address}", ($r['registrationRequired'] ?? false) === true && !$r['success']);
}
$r = $run('submitGuestBooking', $salon);
check('direct salon submit cannot bypass limit', ($r['registrationRequired'] ?? false) === true);
$r = $run('submitGuestHomeService', $home);
check('direct home service submit cannot bypass limit', ($r['registrationRequired'] ?? false) === true);
check('rejected requests create no bookings', GuestBookingLimit::count($pdo, $phone, $email) === 2);
check('unrelated identity has no bookings', GuestBookingLimit::count($pdo, '09990007663', 'unrelated@example.test') === 0);

// Updating the customer email must not erase the email recorded on an earlier appointment.
$pdo->prepare('UPDATE customers SET email = ? WHERE phone_number = ?')->execute(['changed@example.test', $phone]);
check('original booking email still counts after customer email changes', GuestBookingLimit::count($pdo, '09990007664', $email) === 2);

// Two simultaneous home requests share the last remaining guest booking.
$racePhone = '09990007665';
$raceEmail = 'guest.race@example.test';
$pdo->prepare('INSERT INTO customers (first_name, last_name, phone_number, email) VALUES (?, ?, ?, ?)')->execute(['Race', 'Tester', $racePhone, $raceEmail]);
$raceCustomer = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO appointments (customer_id, employee_id, branch_id, appointment_datetime, status) VALUES (?, ?, ?, ?, 'Cancelled')")
    ->execute([$raceCustomer, $salon['staff_id'], 1, $day . ' 09:00:00']);
$pdo->prepare('DELETE FROM rate_limit WHERE identifier = ?')->execute(['127.0.0.1']);
$race = array_replace($home, ['contact' => $racePhone, 'email' => $raceEmail, 'date' => free_day($pdo, 40)]);
$spec = ['script' => 'backend/public/submitGuestHomeService.php', 'post' => $race, 'startAt' => microtime(true) + 1];
$a = start_endpoint($spec);
$spec['post']['contact'] = '09990007666';
$b = start_endpoint($spec);
$results = [endpoint_result($a), endpoint_result($b)];
check('parallel requests with a shared email allow exactly one last booking', count(array_filter($results, fn($r) => $r['success'] ?? false)) === 1
    && count(array_filter($results, fn($r) => $r['registrationRequired'] ?? false)) === 1
    && GuestBookingLimit::count($pdo, $racePhone, $raceEmail) === 2);

finish();
