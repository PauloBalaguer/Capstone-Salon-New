<?php
// Staff can't change their own password; only the admin sets it (saveStaff.php).
// Runs the real endpoint against an isolated database; never changes live accounts.
require __DIR__ . '/support/harness.php';
$pdo = clone_database('capstone_staff_password_test');
$p = test_people($pdo);
$staff = $pdo->query("SELECT e.id, e.user_id, e.first_name, e.last_name, e.position, b.branch_key
    FROM employees e JOIN branches b ON b.id = e.branch_id JOIN users u ON u.id = e.user_id
    WHERE e.is_active = 1 ORDER BY e.id LIMIT 1")->fetch();
$userId = (int) $staff['user_id'];
$base = ['id' => $staff['id'], 'firstName' => $staff['first_name'], 'lastName' => $staff['last_name'], 'branchId' => $staff['branch_key'], 'role' => $staff['position'] ?: 'Stylist'];
$password = 'Test-Staff!928';
$change = ['newPassword' => $password, 'confirmPassword' => $password];
$call = fn(array $session, array $fields) => run_endpoint(['script' => 'backend/admin/saveStaff.php', 'session' => $session, 'post' => $fields + $base]);
$hash = fn() => $pdo->query("SELECT password FROM users WHERE id = {$userId}")->fetchColumn();
$old = $hash();
$staffSession = ['user_id' => $userId, 'user_role' => 'Staff', 'branch_id' => 1];

check('staff cannot change a password via the admin endpoint', !($call($staffSession, $change)['success'] ?? false));
check('staff self-service password endpoint is gone', !is_file(dirname(__DIR__) . '/backend/staff/changePassword.php'));
check('mismatched confirmation rejected', !($call($p['admin'], ['confirmPassword' => 'Different-1!x'] + $change)['success'] ?? false));
check('weak password rejected', !($call($p['admin'], ['newPassword' => 'weak', 'confirmPassword' => 'weak'])['success'] ?? false));
check('failed requests leave the password unchanged', $hash() === $old);
check('admin can save without changing the password', ($call($p['admin'], [])['success'] ?? false) && $hash() === $old);
$result = $call($p['admin'], $change);
check('admin can set a new staff password', ($result['success'] ?? false) === true);
check('new password stored as a verifiable hash', $hash() !== $password && password_verify($password, $hash()));
check('password absent from response', strpos(json_encode($result), $password) === false);
finish();
