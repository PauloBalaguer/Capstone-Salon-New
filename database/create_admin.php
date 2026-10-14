<?php
/**
 * One-time CLI helper: creates a new Admin login, or promotes/updates an
 * existing account to Admin. There is no public-facing way to become an
 * admin by design (backend/auth/register.php always assigns the Customer
 * role), so this is the supported way to add another admin account.
 *
 * Run from the command line:
 *   php database/create_admin.php admin2@leomejillanosalon.ph "SomeStrongPass1!" "Second Admin"
 */

require_once __DIR__ . '/../backend/config/database.php';

[, $email, $password, $displayName] = array_pad($argv, 4, null);

if (!$email || !$password) {
    fwrite(STDERR, "Usage: php database/create_admin.php <email> <password> [display name]\n");
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

$displayName = $displayName ?: 'Platform Administrator';

$pdo = Database::getInstance();

$roleId = $pdo->query("SELECT id FROM roles WHERE role_name = 'Admin'")->fetchColumn();
if (!$roleId) {
    fwrite(STDERR, "No 'Admin' role found in the `roles` table — run the database migrations first.\n");
    exit(1);
}

$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$existingId = $stmt->fetchColumn();

if ($existingId) {
    $pdo->prepare('UPDATE users SET role_id = ?, password = ?, display_name = ? WHERE id = ?')
        ->execute([$roleId, $hashed, $displayName, $existingId]);
    echo "Updated existing account #{$existingId} to Admin -> email: {$email}\n";
} else {
    $pdo->prepare('INSERT INTO users (role_id, email, password, display_name) VALUES (?, ?, ?, ?)')
        ->execute([$roleId, $email, $hashed, $displayName]);
    echo "Created new Admin -> email: {$email}\n";
}
