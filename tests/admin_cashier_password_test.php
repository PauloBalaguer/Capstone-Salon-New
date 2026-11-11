<?php
// Run the real endpoint against an isolated database; never change live accounts.
require __DIR__ . '/support/harness.php';
$pdo = clone_database('capstone_cashier_password_test');
$p = test_people($pdo);
$cashier = $pdo->query("SELECT u.*, b.branch_key FROM users u JOIN branches b ON b.id = u.branch_id WHERE u.id = " . (int) $p['cashier']['user_id'])->fetch();
$id = (int) $cashier['id'];
$base = ['id' => $id, 'name' => $cashier['display_name'], 'branchId' => $cashier['branch_key']];
$password = 'Test-Cashier!928';
$change = ['newPassword' => $password, 'confirmPassword' => $password];
$call = fn(array $session, array $fields) => run_endpoint(['script' => 'backend/admin/saveCashier.php', 'session' => $session, 'post' => $fields + $base]);
$hash = fn() => $pdo->query("SELECT password FROM users WHERE id = {$id}")->fetchColumn();
$old = $hash();
check('cashier cannot change password via admin endpoint', !($call($p['cashier'], $change)['success'] ?? false));
check('guest cannot change password', !($call([], $change)['success'] ?? false));
check('mismatched confirmation rejected', !($call($p['admin'], ['confirmPassword' => 'different'] + $change)['success'] ?? false));
check('weak password rejected', !($call($p['admin'], ['newPassword' => 'weak', 'confirmPassword' => 'weak'])['success'] ?? false));
check('failed requests leave password unchanged', $hash() === $old);
check('admin can save without changing password', ($call($p['admin'], [])['success'] ?? false) && $hash() === $old);
check('admin cannot target another role', !($call($p['admin'], ['id' => $p['adminId']] + $change)['success'] ?? false));
$pdo->prepare('INSERT INTO account_setup_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY)) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash)')->execute([$id, hash('sha256', 'test-setup')]);
$result = $call($p['admin'], $change);
check('admin can change cashier password', ($result['success'] ?? false) === true);
check('new password stored as a verifiable hash', $hash() !== $password && password_verify($password, $hash()));
check('old setup token removed', (int) $pdo->query("SELECT COUNT(*) FROM account_setup_tokens WHERE user_id = {$id}")->fetchColumn() === 0);
check('password absent from response', strpos(json_encode($result), $password) === false);
finish();
