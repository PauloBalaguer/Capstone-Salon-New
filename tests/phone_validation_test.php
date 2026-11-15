<?php
/**
 * PH mobile numbers (panel item 5): only 09XXXXXXXXX or +639XXXXXXXXX are
 * accepted, with one clear message, and they're stored as 09XXXXXXXXX.
 * "1234" used to be accepted at registration and on the customer profile.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_phone_validation_test');
require_once dirname(__DIR__) . '/backend/shared/config/booking/BookingValidation.php';
$p = test_people($pdo);

PhoneNumber::selfCheck();
check('helper: accepts 09XXXXXXXXX and +639XXXXXXXXX, refuses 1234 and near misses', true);

$rules = ['contact' => ['mobile', true, 'a mobile number']];
check('booking forms: "1234" refused with the clear message', (BookingValidation::errors(['contact' => '1234'], $rules)['contact'] ?? '') === PhoneNumber::MESSAGE);
check('booking forms: +639 accepted', BookingValidation::errors(['contact' => '+639171234567'], $rules) === []);
$_POST = ['contact' => '+63 917 123 4567'];
BookingValidation::enforce($_POST, $rules);
check('booking forms: stored as 09XXXXXXXXX', $_POST['contact'] === '09171234567');

$r = run_endpoint(['script' => 'backend/auth/register.php', 'post' => ['first_name' => 'Test', 'last_name' => 'Person', 'email' => 'phone-test@example.com',
    'contact_number' => '1234', 'password' => 'Str0ng!Pass', 'confirm_password' => 'Str0ng!Pass']]);
check('registration: "1234" refused', !($r['success'] ?? true) && in_array(PhoneNumber::MESSAGE, $r['errors'] ?? [], true));

$profile = fn(string $phone) => run_endpoint(['script' => 'backend/customer/saveProfile.php', 'session' => $p['sessionA'],
    'post' => ['firstName' => 'Test', 'lastName' => 'Person', 'name' => 'Test Person', 'email' => 'phone-test@example.com', 'phone' => $phone]]);
$before = $pdo->query("SELECT phone_number FROM customers WHERE id = {$p['customerA']}")->fetchColumn();
$r = $profile('1234');
check('customer profile: "1234" refused, number unchanged', !($r['success'] ?? true) && ($r['message'] ?? '') === PhoneNumber::MESSAGE
    && $pdo->query("SELECT phone_number FROM customers WHERE id = {$p['customerA']}")->fetchColumn() === $before);
$r = $profile('+63 917 555 0101');
check('customer profile: +639 saved as 09XXXXXXXXX', ($r['success'] ?? false)
    && $pdo->query("SELECT phone_number FROM customers WHERE id = {$p['customerA']}")->fetchColumn() === '09175550101');

$r = run_endpoint(['script' => 'backend/admin/saveStaff.php', 'session' => $p['admin'], 'post' => ['firstName' => 'New', 'lastName' => 'Stylist', 'noMiddleName' => '1',
    'branchId' => 'daraga', 'role' => 'Hair Stylist', 'email' => 'new-stylist@example.com', 'phone' => '1234']]);
check('admin new staff: "1234" refused', !($r['success'] ?? true) && str_contains(($r['message'] ?? '') . json_encode($r['errors'] ?? []), '09XXXXXXXXX'));

$r = run_endpoint(['script' => 'backend/public/joinWaitlist.php', 'post' => ['employeeId' => 1, 'date' => date('Y-m-d', strtotime('+3 days')),
    'name' => 'Test Person', 'email' => 'phone-test@example.com', 'phone' => '1234']]);
check('waitlist: "1234" refused', !($r['success'] ?? true) && ($r['message'] ?? '') === PhoneNumber::MESSAGE);

finish();
