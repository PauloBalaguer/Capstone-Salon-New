<?php
/**
 * Signing up with a mobile number that already has guest bookings.
 *
 * 1. A number with its own account stays blocked.
 * 2. A guest number can sign up, but nothing joins the account until the email
 *    code AND a texted code are right.
 * 3. Only someone holding the token and the code can finish.
 * 4. Coming back through login resumes the step; a number taken meanwhile, or
 *    one that can't be proven, can finish with a different number.
 *
 * No SMS key here, so texts fail; the checks put the code in place themselves.
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_guest_signup_test');
$password = 'TestPass#123';

$auth = fn(string $file, array $post) => run_endpoint(['script' => "backend/auth/{$file}.php", 'post' => $post]);
$signup = function (string $email, string $phone) use ($auth, $password, $pdo) {
    $pdo->exec('DELETE FROM rate_limit');
    return $auth('register', ['first_name' => 'New', 'last_name' => 'Member', 'email' => $email, 'contact_number' => $phone, 'password' => $password, 'confirm_password' => $password, 'notify_sms' => '1']);
};
$userOf = function (string $email) use ($pdo) {
    $stmt = $pdo->prepare('SELECT id, is_active, otp_code FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return $stmt->fetch();
};
$customerOf = function (string $phone) use ($pdo) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE phone_number = ?');
    $stmt->execute([$phone]);
    return $stmt->fetch();
};
$emailCode = fn(string $email) => $auth('verifyOtp', ['email' => $email, 'otp' => $userOf($email)['otp_code']]);
$setTextCode = fn(int $userId, string $code) => $pdo->exec("UPDATE guest_account_claims SET code = '{$code}', code_expires_at = NOW() + INTERVAL 10 MINUTE, code_attempts = 0 WHERE user_id = {$userId}");
$phoneStep = fn(string $email, string $token, string $action, array $more = []) => $auth('verifyGuestPhone', ['email' => $email, 'claimToken' => $token, 'action' => $action] + $more);

// A guest record with a booking and loyalty points.
$guestPhone = '09990002201';
$pdo->exec("INSERT INTO customers (first_name, last_name, phone_number, loyalty_points) VALUES ('Guest', 'Booker', '{$guestPhone}', 40)");
$guestId = (int) $pdo->lastInsertId();
$booking = (int) $pdo->query('SELECT id FROM appointments ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("UPDATE appointments SET customer_id = {$guestId} WHERE id = {$booking}");

// ---- 1. A number with an account --------------------------------------------
$taken = $pdo->query('SELECT phone_number FROM customers WHERE user_id IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn();
$r = $signup('blocked@example.test', $taken);
check('a number with its own account is refused', ($r['success'] ?? true) === false && str_contains($r['message'] ?? '', 'sign in') && !$userOf('blocked@example.test'));

// ---- 2. Signing up with a guest number ---------------------------------------
$email = 'new.member@example.test';
$r = $signup($email, $guestPhone);
$user = $userOf($email);
$userId = (int) $user['id'];
check('a guest number can sign up', ($r['success'] ?? false) === true && $user && (int) $user['is_active'] === 0);
check('nothing is linked yet', $customerOf($guestPhone)['user_id'] === null && (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE user_id = {$userId}")->fetchColumn() === 0);
$texts = fn() => (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE type = 'ACCOUNT_OTP'")->fetchColumn();
check('no text before the email is verified', $texts() === 0);

$r = $auth('login', ['email' => $email, 'password' => $password]);
check('signing in before verifying the email still asks for the email code', ($r['needs_verification'] ?? false) === true && !isset($r['claimToken']));
$r = $auth('verifyOtp', ['email' => $email, 'otp' => '000000' === $user['otp_code'] ? '111111' : '000000']);
check('a wrong email code gets nowhere', ($r['success'] ?? true) === false && !isset($r['claimToken']));

$r = $emailCode($email);
$token = $r['claimToken'] ?? '';
check('the right email code leads to the text step', ($r['needsPhoneCode'] ?? false) === true && $token !== '' && ($r['phoneHint'] ?? '') === '2201');
check('only the last 4 digits of the number are shown', !str_contains(json_encode($r), $guestPhone));
check('a text was attempted, with the code left out of the log', $texts() === 1 && str_contains((string) $pdo->query("SELECT message FROM sms_outbox WHERE type = 'ACCOUNT_OTP' ORDER BY id DESC LIMIT 1")->fetchColumn(), '******'));
check('the page is told the text failed', ($r['codeSent'] ?? true) === false);
check('the account is still inactive', (int) $userOf($email)['is_active'] === 0 && $customerOf($guestPhone)['user_id'] === null);
$stored = $pdo->query("SELECT token_hash FROM guest_account_claims WHERE user_id = {$userId}")->fetchColumn();
check('the token is stored only as a hash', $stored !== $token && $stored === hash('sha256', $token));

// ---- 3. Who can finish -------------------------------------------------------
$setTextCode($userId, '123456');
$pdo->exec('DELETE FROM rate_limit');
foreach (['no token' => '', 'a wrong token' => str_repeat('a', 64)] as $label => $badToken) {
    $r = $phoneStep($email, $badToken, 'verify', ['code' => '123456']);
    check("the right code with {$label} is refused", ($r['sessionExpired'] ?? false) === true && $customerOf($guestPhone)['user_id'] === null);
}
$r = $phoneStep($email, str_repeat('a', 64), 'different_number', ['phone' => '09990002299']);
check('a different number with a wrong token is refused', ($r['sessionExpired'] ?? false) === true && !$customerOf('09990002299'));
$pdo->exec('DELETE FROM rate_limit');

$r = $phoneStep($email, $token, 'verify', ['code' => '654321']);
check('a wrong text code is refused', ($r['success'] ?? true) === false && $customerOf($guestPhone)['user_id'] === null);
$pdo->exec("UPDATE guest_account_claims SET code_attempts = 5 WHERE user_id = {$userId}");
$r = $phoneStep($email, $token, 'verify', ['code' => '123456']);
check('after 5 wrong tries even the right code is refused', ($r['success'] ?? true) === false && str_contains($r['message'] ?? '', 'new one'));
$setTextCode($userId, '123456');
$pdo->exec("UPDATE guest_account_claims SET code_expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id = {$userId}");
$r = $phoneStep($email, $token, 'verify', ['code' => '123456']);
check('an expired code is refused', ($r['success'] ?? true) === false && str_contains($r['message'] ?? '', 'expired'));

$pdo->exec('DELETE FROM rate_limit');
$r = $auth('login', ['email' => $email, 'password' => 'Wrong#12345']);
check('a wrong password doesn\'t reach the text step', !isset($r['claimToken']));
$r = $auth('login', ['email' => $email, 'password' => $password]);
$newToken = $r['claimToken'] ?? '';
check('signing in resumes the text step', ($r['needsPhoneCode'] ?? false) === true && $newToken !== '' && $newToken !== $token && ($r['email'] ?? '') === $email);
$setTextCode($userId, '123456');
$r = $phoneStep($email, $token, 'verify', ['code' => '123456']);
check('the older token no longer works', ($r['sessionExpired'] ?? false) === true);

$pdo->exec('DELETE FROM rate_limit');
$r = $phoneStep($email, $newToken, 'verify', ['code' => '123456']);
$linked = $customerOf($guestPhone);
check('the right code links the guest record', ($r['success'] ?? false) === true && (int) $linked['user_id'] === $userId && (int) $linked['id'] === $guestId);
check('the account is active and uses the name from signup', (int) $userOf($email)['is_active'] === 1 && $linked['first_name'] === 'New' && $linked['last_name'] === 'Member');
check('the booking and loyalty points came along', (int) $linked['loyalty_points'] === 40 && (int) $pdo->query("SELECT customer_id FROM appointments WHERE id = {$booking}")->fetchColumn() === $guestId);
check('no second customer record was made', (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE user_id = {$userId}")->fetchColumn() === 1);
check('the waiting signup is gone', (int) $pdo->query("SELECT COUNT(*) FROM guest_account_claims WHERE user_id = {$userId}")->fetchColumn() === 0);
$r = $phoneStep($email, $newToken, 'verify', ['code' => '123456']);
check('the code and token can\'t be used twice', ($r['sessionExpired'] ?? false) === true);
$r = $signup('another@example.test', $guestPhone);
check('that number now counts as having an account', ($r['success'] ?? true) === false && !$userOf('another@example.test'));

// ---- 4. Two signups race for one guest number; a different number -----------
$racePhone = '09990002202';
$pdo->exec("INSERT INTO customers (first_name, last_name, phone_number) VALUES ('Race', 'Guest', '{$racePhone}')");
$start = function (string $mail) use ($signup, $emailCode, $racePhone) { $signup($mail, $racePhone); return $emailCode($mail)['claimToken'] ?? ''; };
$first = $start('race.one@example.test');
$second = $start('race.two@example.test');
$setTextCode((int) $userOf('race.one@example.test')['id'], '111222');
$setTextCode((int) $userOf('race.two@example.test')['id'], '333444');
check('the first to prove the number gets it', ($phoneStep('race.one@example.test', $first, 'verify', ['code' => '111222'])['success'] ?? false) === true);
$r = $phoneStep('race.two@example.test', $second, 'verify', ['code' => '333444']);
check('the second is told it is taken and stays inactive', ($r['numberTaken'] ?? false) === true && (int) $userOf('race.two@example.test')['is_active'] === 0);

$r = $phoneStep('race.two@example.test', $second, 'different_number', ['phone' => $taken]);
check('a different number that is in use is refused', ($r['success'] ?? true) === false && (int) $userOf('race.two@example.test')['is_active'] === 0);
$r = $phoneStep('race.two@example.test', $second, 'different_number', ['phone' => '12345']);
check('an invalid number is refused', ($r['success'] ?? true) === false);
$free = '09990002203';
$r = $phoneStep('race.two@example.test', $second, 'different_number', ['phone' => $free]);
$row = $customerOf($free);
check('a free number finishes the signup', ($r['success'] ?? false) === true && $row && (int) $row['user_id'] === (int) $userOf('race.two@example.test')['id'] && (int) $userOf('race.two@example.test')['is_active'] === 1);

// ---- A brand new number is unchanged ------------------------------------------
$plainPhone = '09990002204';
$signup('plain@example.test', $plainPhone);
$plain = $userOf('plain@example.test');
check('a new number signs up as before', $customerOf($plainPhone) && (int) $customerOf($plainPhone)['user_id'] === (int) $plain['id']);
$r = $emailCode('plain@example.test');
check('and only needs the email code', ($r['success'] ?? false) === true && !isset($r['needsPhoneCode']) && (int) $userOf('plain@example.test')['is_active'] === 1);

finish();
