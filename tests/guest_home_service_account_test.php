<?php
/**
 * Guest home-service request with a mobile number that already has an account:
 * refused unless the email is that account's login email; then the request
 * goes into that account. Numbers without an account work as before.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_guest_hs_account_test');
$pdo->exec("DELETE FROM rate_limit");
$pdo->exec("DELETE FROM staff_leave");

$account = $pdo->query("SELECT c.id AS customer_id, c.phone_number, u.email FROM customers c JOIN users u ON u.id = c.user_id
    WHERE c.phone_number IS NOT NULL AND c.phone_number <> '' AND u.is_active = 1 ORDER BY c.id LIMIT 1")->fetch();
if (!$account) exit("The dev database needs a registered customer with a mobile number.\n");
$package = 'A'; // HomeServiceRequest::PACKAGES key
$date = free_day($pdo, 20);

$submit = function (string $contact, string $email, ?string $onDate = null, string $at = '10:00') use ($pdo, $package, $date) {
    $pdo->exec('DELETE FROM rate_limit'); // 5 tries per 30 s per IP: each check starts fresh
    return run_endpoint(['script' => 'backend/public/submitGuestHomeService.php', 'post' => [
        'fullname' => 'Test Guest', 'contact' => $contact, 'email' => $email,
        'structuredAddress' => json_encode(['province' => '050500000', 'city' => '050504000', 'barangay' => '050504025', 'house' => '12', 'street' => 'Rizal Street', 'subdivision' => '', 'landmark' => '']),
        'venueDetails' => 'Test venue', 'eventType' => 'Wedding', 'weddingPackage' => $package,
        'date' => $onDate ?? $date, 'time' => $at, 'clients' => '2', 'agreedToTerms' => 'true', 'branchKey' => 'daraga',
    ]]);
};
$requestsFor = fn(int $customerId) => (int) $pdo->query("SELECT COUNT(*) FROM home_service_requests WHERE customer_id = {$customerId}")->fetchColumn();

$before = $requestsFor((int) $account['customer_id']);
$r = $submit($account['phone_number'], 'someone.else@example.test');
check('account number + a different email is refused', ($r['success'] ?? true) === false && str_contains((string) ($r['message'] ?? ''), 'already has an account'));
check('nothing was attached to the account', $requestsFor((int) $account['customer_id']) === $before);

$r = $submit($account['phone_number'], strtoupper($account['email']));
check('account number + that account\'s email is accepted', ($r['success'] ?? false) === true);
check('the request went into that account', $requestsFor((int) $account['customer_id']) === $before + 1);

$r = $submit('09170000123', 'new.guest@example.test');
check('a number with no account still works', ($r['success'] ?? false) === true);
// Home service needs 3 days' notice (HomeServiceRequest::MIN_NOTICE_DAYS).
// Later today (not yet past): still refused.
$r = $submit('09170000124', 'same.day@example.test', date('Y-m-d'), '23:30');
check('a request for today is refused', ($r['success'] ?? true) === false && str_contains((string) ($r['message'] ?? ''), 'notice'));
$r = $submit('09170000126', 'two.days@example.test', date('Y-m-d', strtotime('+2 days')));
check('a request 2 days ahead is refused (3 days notice)', ($r['success'] ?? true) === false && str_contains((string) ($r['message'] ?? ''), 'notice'));
$r = $submit('09170000127', 'three.days@example.test', free_day($pdo, 3));
check('a request 3+ days ahead is accepted', ($r['success'] ?? false) === true);
$r = $submit('09170000125', 'past@example.test', date('Y-m-d', strtotime('-1 day')));
check('a request for yesterday is refused', ($r['success'] ?? true) === false);
finish();
