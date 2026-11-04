<?php
/**
 * Guest booking verification, through the real endpoints
 * (backend/public/sendBookingOtp.php and submitGuestBooking.php).
 *
 *   C:\xampp\php\php.exe tests\guest_otp_test.php
 *
 * A guest who gives no email must verify the mobile number with a texted code
 * before the booking is created (and before any PayMongo redirect); with an
 * email, the emailed code is used as before. Clones the dev database into a
 * throwaway one, prints one line per check, exits 1 on any failure. No keys
 * needed: with no textbee key the text fails, which is one of the checks.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_guest_otp_test');
$service = (int) $pdo->query("SELECT id FROM services WHERE branch_id = 1 AND is_active = 1 AND duration_minutes = 60 ORDER BY id LIMIT 1")->fetchColumn();
$staff = (int) $pdo->query("SELECT ss.employee_id FROM staff_services ss JOIN employees e ON e.id = ss.employee_id
    WHERE ss.service_id = {$service} AND e.is_active = 1 AND e.branch_id = 1 ORDER BY e.id LIMIT 1")->fetchColumn();
$day = free_day($pdo, 30);
$phone = '09171234567';
$pdo->exec("DELETE FROM booking_verifications WHERE email LIKE 'sms:%'");

$book = fn(string $time, string $code, string $email = '') => run_endpoint(['script' => 'backend/public/submitGuestBooking.php', 'post' => [
    'fullname' => 'Guest Tester', 'contact' => $GLOBALS['phone'], 'branch' => '1', 'services' => [(string) $service], 'staff_id' => (string) $staff,
    'appointment_date' => $day, 'time_slot' => $time, 'email' => $email, 'otp_code' => $code, 'payment_method' => 'Cash', 'agreedToTerms' => '1']]);
$bookings = fn() => (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_datetime) = '{$day}' AND branch_id = 1")->fetchColumn();

$r = run_endpoint(['script' => 'backend/public/sendBookingOtp.php', 'post' => ['email' => '', 'phone' => $phone]]);
check('no email -> code goes by text; SMS not configured -> clear error, no code stored', ($r['success'] ?? true) === false
    && str_contains($r['message'], 'text a code') && !$pdo->query("SELECT 1 FROM booking_verifications WHERE email = 'sms:+639171234567'")->fetchColumn());

$r = $book('10:00', '');
check('no email and no code -> refused, asks for the code sent to the mobile number', ($r['success'] ?? true) === false
    && str_contains($r['message'], 'mobile number') && $bookings() === 0);

// What sendBookingOtp.php stores once the text goes out.
$pdo->exec("INSERT INTO booking_verifications (email, code, expires_at) VALUES ('sms:+639171234567', '123456', NOW() + INTERVAL 10 MINUTE)");
$r = $book('10:00', '654321');
check('wrong texted code -> refused, nothing booked', ($r['success'] ?? true) === false && str_contains($r['message'], 'Invalid') && $bookings() === 0);
$r = $book('10:00', '123456');
check('right texted code -> booked', ($r['success'] ?? false) === true && $bookings() === 1);
check('the code is used up', !$pdo->query("SELECT 1 FROM booking_verifications WHERE email = 'sms:+639171234567'")->fetchColumn());
$r = $book('14:00', '123456');
check('reusing the same code -> refused', ($r['success'] ?? true) === false && $bookings() === 1);

// With an email, the emailed code still decides; a texted code for the number doesn't count.
$pdo->exec("INSERT INTO booking_verifications (email, code, expires_at) VALUES ('sms:+639171234567', '111111', NOW() + INTERVAL 10 MINUTE),
    ('guest.tester@example.com', '222222', NOW() + INTERVAL 10 MINUTE)");
$r = $book('14:00', '111111', 'guest.tester@example.com');
check('email given -> the texted code is not accepted', ($r['success'] ?? true) === false && $bookings() === 1);
$r = $book('14:00', '222222', 'guest.tester@example.com');
check('email given -> the emailed code books it', ($r['success'] ?? false) === true && $bookings() === 2);

// A number that belongs to a registered account: a texted code proves the guest owns it.
$account = $pdo->query("SELECT c.id, c.phone_number, u.email FROM customers c JOIN users u ON u.id = c.user_id
    WHERE c.phone_number REGEXP '^09[0-9]{9}$' ORDER BY c.id LIMIT 1")->fetch();
$phone = $account['phone_number'];
$key = 'sms:+63' . substr($phone, 1);
$pdo->exec("INSERT INTO booking_verifications (email, code, expires_at) VALUES ('{$key}', '333333', NOW() + INTERVAL 10 MINUTE)");
$r = $book('16:00', '333333');
$owner = (int) $pdo->query("SELECT customer_id FROM appointments WHERE reference_code = " . $pdo->quote($r['reference'] ?? '') )->fetchColumn();
check('registered number + texted code -> booked onto that account', ($r['success'] ?? false) === true && $owner === (int) $account['id']);
$pdo->exec("INSERT INTO booking_verifications (email, code, expires_at) VALUES ('someone.else@example.com', '444444', NOW() + INTERVAL 10 MINUTE)");
$r = $book('18:00', '444444', 'someone.else@example.com');
check('registered number + a different (emailed) address -> refused, told to leave the email blank', ($r['success'] ?? true) === false
    && str_contains($r['message'], 'leave the email blank'));

finish();
