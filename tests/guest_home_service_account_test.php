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

$submit = function (string $contact, string $email) use ($package, $date) {
    return run_endpoint(['script' => 'backend/public/submitGuestHomeService.php', 'post' => [
        'fullname' => 'Test Guest', 'contact' => $contact, 'email' => $email,
        'structuredAddress' => json_encode(['province' => '050500000', 'city' => '050504000', 'barangay' => '050504025', 'house' => '12', 'street' => 'Rizal Street', 'subdivision' => '', 'landmark' => '']),
        'venueDetails' => 'Test venue', 'eventType' => 'Wedding', 'weddingPackage' => $package,
        'date' => $date, 'time' => '10:00', 'clients' => '2', 'agreedToTerms' => 'true', 'branchKey' => 'daraga',
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
finish();
