<?php

/**
 * Admin: Create/Edit Cashier
 *
 * Cashier accounts are plain `users` rows (role Cashier) locked to a single
 * branch via users.branch_id -- unlike staff they have no `employees` row,
 * since a cashier is a front-desk login rather than a roster member tracked
 * for attendance/performance. Editing supports name/branch and an optional password change; email is
 * fixed after creation. Creating a new cashier needs a login account, so
 * it starts with an unusable random password and an emailed setup link
 * (see AccountSetup.php) instead of a temp password relayed by the admin.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/setup/url.php';
require_once '../../backend/shared/config/setup/mail.php';
require_once '../../backend/shared/config/security/AccountSetup.php';
require_once '../../backend/shared/config/security/PasswordPolicy.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$cashierId = (int) ($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$branchKey = trim($_POST['branchId'] ?? '');
$email = trim($_POST['email'] ?? '');
$newPassword = $_POST['newPassword'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';
if (!is_string($newPassword) || !is_string($confirmPassword)
    || (($newPassword !== '' || $confirmPassword !== '')
        && ($cashierId <= 0 || $newPassword !== $confirmPassword
            || strlen($newPassword) > 72 || !PasswordPolicy::isValid($newPassword)))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Passwords must match and contain no more than 72 bytes. ' . PasswordPolicy::MESSAGE]);
    exit();
}

if ($name === '' || $branchKey === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name and branch are required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id FROM branches WHERE branch_key = ? LIMIT 1');
    $stmt->execute([$branchKey]);
    $branchId = $stmt->fetchColumn();
    if (!$branchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }

    if ($cashierId > 0) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email FROM users u JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? AND r.role_name = 'Cashier' AND u.is_active = 1
        ");
        $stmt->execute([$cashierId]);
        $cashier = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cashier) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Cashier account not found.']);
            exit();
        }

        $pdo->beginTransaction();
        if ($newPassword !== '') {
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $cashierId]);
            $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$cashier['email']]);
            $pdo->prepare('DELETE FROM account_setup_tokens WHERE user_id = ?')->execute([$cashierId]);
            $pdo->prepare('DELETE FROM password_change_verifications WHERE user_id = ?')->execute([$cashierId]);
        }
        $pdo->prepare('UPDATE users SET display_name = ?, branch_id = ? WHERE id = ?')
            ->execute([$name, $branchId, $cashierId]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "STAFF", ?)')
            ->execute(["Cashier account updated for {$name}."]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => $newPassword !== '' ? 'Cashier account and password updated.' : 'Cashier account updated.']);
        exit();
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid email is required to create a new cashier login.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = 'Cashier' LIMIT 1");
    $stmt->execute();
    $roleId = $stmt->fetchColumn();
    if (!$roleId) {
        throw new Exception("Default 'Cashier' role not found in the database.");
    }

    $unusablePassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $pdo->prepare('INSERT INTO users (role_id, branch_id, email, display_name, password) VALUES (?, ?, ?, ?, ?)')
        ->execute([$roleId, $branchId, $email, $name, $unusablePassword]);
    $newUserId = $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "STAFF", ?)')
        ->execute(["New cashier account added: {$name}."]);

    AccountSetup::issueAndEmail($pdo, (int) $newUserId, $email, $name, 'Cashier');

    echo json_encode([
        'success' => true,
        'message' => "Cashier account created. An account setup email has been sent to {$email}.",
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('admin saveCashier error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving the cashier account.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server configuration error occurred.']);
}
