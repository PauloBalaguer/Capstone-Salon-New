<?php
/**
 * One-time CLI helper: creates a new Cashier login locked to one branch, or
 * promotes/updates an existing account to a branch-locked Cashier. There is
 * no public-facing way to become a cashier by design (backend/auth/register.php
 * always assigns the Customer role), so this is the supported way to add a
 * cashier terminal account. Each Cashier account is tied to exactly one
 * branch (users.branch_id) -- see database/migrations/007_cashier_branch_lock.sql
 * and backend/auth/login.php, which stores it in the session at login
 * and every backend/cashier/*.php endpoint enforces from there.
 *
 * Run from the command line:
 *   php database/create_cashier.php cashier.daraga@leomejillanosalon.ph "SomeStrongPass1!" daraga "Daraga Front Desk"
 *
 * <branch> must be one of: daraga, yashano, cabangan (branches.branch_key).
 */

require_once __DIR__ . '/../backend/config/database.php';

[, $email, $password, $branchKey, $displayName] = array_pad($argv, 5, null);

if (!$email || !$password || !$branchKey) {
    fwrite(STDERR, "Usage: php database/create_cashier.php <email> <password> <branch> [display name]\n");
    fwrite(STDERR, "  <branch> must be one of: daraga, yashano, cabangan\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "\"$email\" is not a valid email address.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$pdo = Database::getInstance();

$roleId = $pdo->query("SELECT id FROM roles WHERE role_name = 'Cashier'")->fetchColumn();
if (!$roleId) {
    fwrite(STDERR, "No 'Cashier' role found in the `roles` table — run the database migrations first.\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT id, branch_name FROM branches WHERE branch_key = ? LIMIT 1');
$stmt->execute([$branchKey]);
$branch = $stmt->fetch();
if (!$branch) {
    fwrite(STDERR, "\"$branchKey\" is not a known branch key. Expected one of: daraga, yashano, cabangan.\n");
    exit(1);
}
$branchId = (int) $branch['id'];

$displayName = $displayName ?: "{$branch['branch_name']} Cashier";

$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$existingId = $stmt->fetchColumn();

if ($existingId) {
    $pdo->prepare('UPDATE users SET role_id = ?, branch_id = ?, password = ?, display_name = ? WHERE id = ?')
        ->execute([$roleId, $branchId, $hashed, $displayName, $existingId]);
    echo "Updated existing account #{$existingId} to Cashier @ {$branch['branch_name']} -> email: {$email}\n";
} else {
    $pdo->prepare('INSERT INTO users (role_id, branch_id, email, password, display_name) VALUES (?, ?, ?, ?, ?)')
        ->execute([$roleId, $branchId, $email, $hashed, $displayName]);
    echo "Created new Cashier @ {$branch['branch_name']} -> email: {$email}\n";
}
