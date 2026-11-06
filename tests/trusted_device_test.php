<?php
/**
 * "Remember me" recognising a customer's browser after sign out.
 *
 * 1. Only the right cookie with the right email is recognised.
 * 2. It ends on expiry, password change, deactivation or "Not you?".
 * 3. Customers only, and no password or usable secret is stored.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_trusted_device_test');
$p = test_people($pdo);

$customer = $pdo->query("SELECT u.id, u.email, u.password FROM users u JOIN customers c ON c.user_id = u.id WHERE c.id = {$p['customerA']}")->fetch();
$other = $pdo->query("SELECT u.email FROM users u JOIN customers c ON c.user_id = u.id WHERE c.id = {$p['customerB']}")->fetchColumn();
$userId = (int) $customer['id'];
$email = $customer['email'];
$pdo->exec("UPDATE users SET is_active = 1 WHERE id = {$userId}");

$token = str_repeat('ab', 32);
$device = function (int $forUser, string $password, string $expires = 'NOW() + INTERVAL 30 DAY') use ($pdo, $token) {
    $pdo->exec('DELETE FROM trusted_devices');
    $pdo->prepare("INSERT INTO trusted_devices (user_id, token_hash, password_fingerprint, expires_at) VALUES (?, ?, ?, {$expires})")
        ->execute([$forUser, hash('sha256', $token), hash('sha256', $password)]);
};
$call = fn(string $action, string $mail, array $cookies, array $more = []) => run_endpoint([
    'script' => 'backend/auth/deviceLogin.php',
    'post' => ['action' => $action, 'email' => $mail] + $more,
    'cookies' => $cookies,
]);
$withCookie = ['LEO_DEVICE' => $token];
$recognised = fn(string $mail, array $cookies) => ($call('check', $mail, $cookies)['recognised'] ?? null) === true;

// ---- 1. Who is recognised -----------------------------------------------------
$device($userId, $customer['password']);
check('the right cookie and email is recognised', $recognised($email, $withCookie));
check('the same cookie with another email is not', !$recognised($other, $withCookie));
check('no cookie is not', !$recognised($email, []));
check('a wrong cookie is not', !$recognised($email, ['LEO_DEVICE' => str_repeat('cd', 32)]));
$r = $call('check', $other, $withCookie);
check('the answer reveals nothing about other accounts', array_keys($r) === ['recognised'] && $r['recognised'] === false);

$r = $call('login', $email, [], ['remember' => '1']);
check('signing in without the cookie is refused', ($r['success'] ?? true) === false);
$r = $call('login', $other, $withCookie, ['remember' => '1']);
check('signing in as someone else with the cookie is refused', ($r['success'] ?? true) === false);
$r = $call('login', $email, $withCookie, ['remember' => '1']);
check('the right cookie and email signs in, with no password sent', ($r['success'] ?? false) === true && ($r['portal'] ?? '') === 'customer' && str_contains($r['redirect'] ?? '', 'customer'));
check('the device is kept when Remember me stays ticked', $recognised($email, $withCookie));

$r = $call('login', $email, $withCookie);
check('signing in with the box unticked forgets the device', ($r['success'] ?? false) === true && !$recognised($email, $withCookie));

// ---- 2. When it ends ----------------------------------------------------------
$device($userId, $customer['password'], 'NOW() - INTERVAL 1 MINUTE');
check('an expired device is not recognised', !$recognised($email, $withCookie) && ($call('login', $email, $withCookie, ['remember' => '1'])['success'] ?? true) === false);

$device($userId, $customer['password']);
$pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash('Changed#123', PASSWORD_DEFAULT), $userId]);
check('changing the password ends it', !$recognised($email, $withCookie) && ($call('login', $email, $withCookie, ['remember' => '1'])['success'] ?? true) === false);
$pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$customer['password'], $userId]);

$device($userId, $customer['password']);
$pdo->exec("UPDATE users SET is_active = 0 WHERE id = {$userId}");
check('a deactivated account is not recognised', !$recognised($email, $withCookie));
$pdo->exec("UPDATE users SET is_active = 1 WHERE id = {$userId}");

$call('forget', '', $withCookie);
check('"Not you?" forgets the device', !$recognised($email, $withCookie) && (int) $pdo->query('SELECT COUNT(*) FROM trusted_devices')->fetchColumn() === 0);

// ---- 3. Customers only; nothing usable stored -----------------------------------
$staff = $pdo->query("SELECT u.id, u.email, u.password FROM users u JOIN employees e ON e.user_id = u.id JOIN roles r ON r.id = u.role_id WHERE r.role_name = 'Staff' AND u.is_active = 1 ORDER BY u.id LIMIT 1")->fetch();
$device((int) $staff['id'], $staff['password']);
check('a staff account is never recognised, even with a device row', !$recognised($staff['email'], $withCookie) && ($call('login', $staff['email'], $withCookie, ['remember' => '1'])['success'] ?? true) === false);

$columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trusted_devices'")->fetchAll(PDO::FETCH_COLUMN);
$row = $pdo->query('SELECT * FROM trusted_devices LIMIT 1')->fetch();
check('the table holds hashes, not the secret or the password', !in_array('password', $columns, true) && $row['token_hash'] !== $token && $row['password_fingerprint'] !== $staff['password']);

$known = 'Known#12345';
$pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($known, PASSWORD_DEFAULT), $staff['id']]);
$pdo->exec('DELETE FROM trusted_devices');
$pdo->exec('DELETE FROM rate_limit');
run_endpoint(['script' => 'backend/auth/login.php', 'post' => ['email' => $staff['email'], 'password' => $known, 'remember' => '1']]);
check('a staff login with Remember me ticked remembers no device', (int) $pdo->query('SELECT COUNT(*) FROM trusted_devices')->fetchColumn() === 0);

$pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($known, PASSWORD_DEFAULT), $userId]);
$pdo->exec('DELETE FROM rate_limit');
run_endpoint(['script' => 'backend/auth/login.php', 'post' => ['email' => $email, 'password' => $known, 'remember' => '1']]);
check('a customer login with Remember me ticked remembers the device', (int) $pdo->query("SELECT COUNT(*) FROM trusted_devices WHERE user_id = {$userId}")->fetchColumn() === 1);

$pdo->exec('DROP TABLE trusted_devices');
$pdo->exec('DELETE FROM rate_limit');
$r = run_endpoint(['script' => 'backend/auth/login.php', 'post' => ['email' => $email, 'password' => $known, 'remember' => '1']]);
check('if this table is missing, a normal login still works', ($r['success'] ?? false) === true);

finish();
