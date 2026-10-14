<?php

/**
 * Admin: Create/Edit Staff
 *
 * Editing only touches name/branch/position — rating, completedCount,
 * status, and attendance are computed in getDashboardData.php, not stored,
 * so they're not accepted here. Creating a new staff member needs a login
 * account (employees.user_id is NOT NULL + unique), so email + phone are
 * required for that path only; the account starts with an unusable random
 * password and an emailed setup link (see AccountSetup.php) instead of a
 * temp password relayed by the admin.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/url.php';
require_once '../config/mail.php';
require_once '../config/AccountSetup.php';

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

$staffId = (int) ($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$branchKey = trim($_POST['branchId'] ?? '');
$role = trim($_POST['role'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$serviceIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['services'] ?? [])))));

if ($name === '' || $branchKey === '' || $role === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, branch, and role are required.']);
    exit();
}

[$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, '');

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

    if (!empty($serviceIds)) {
        $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM services WHERE branch_id = ? AND id IN ($placeholders) AND is_active = 1");
        $stmt->execute(array_merge([$branchId], $serviceIds));
        $validServiceIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        if (count($validServiceIds) !== count($serviceIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'One or more selected services are invalid for this branch.']);
            exit();
        }
    }

    if ($staffId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = ?');
        $stmt->execute([$staffId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Staff member not found.']);
            exit();
        }

        $pdo->beginTransaction();

        $pdo->prepare('UPDATE employees SET first_name = ?, last_name = ?, position = ?, branch_id = ? WHERE id = ?')
            ->execute([$firstName, $lastName, $role, $branchId, $staffId]);

        $pdo->prepare('DELETE FROM staff_services WHERE employee_id = ?')->execute([$staffId]);
        if (!empty($serviceIds)) {
            $insServ = $pdo->prepare('INSERT INTO staff_services (employee_id, service_id) VALUES (?, ?)');
            foreach ($serviceIds as $serviceId) {
                $insServ->execute([$staffId, $serviceId]);
            }
        }

        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "STAFF", ?)')
            ->execute(["Staff profile updated for {$name}."]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Staff profile updated.']);
        exit();
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid email is required to create a new staff login.']);
        exit();
    }
    if ($phone === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A contact number is required.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit();
    }

    $stmt = $pdo->prepare('SELECT id FROM employees WHERE phone_number = ?');
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'A staff member with this contact number already exists.']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = 'Staff' LIMIT 1");
    $stmt->execute();
    $roleId = $stmt->fetchColumn();
    if (!$roleId) {
        throw new Exception("Default 'Staff' role not found in the database.");
    }

    $unusablePassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    $pdo->prepare('INSERT INTO users (role_id, email, password) VALUES (?, ?, ?)')
        ->execute([$roleId, $email, $unusablePassword]);
    $newUserId = $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO employees (user_id, first_name, last_name, phone_number, position, branch_id, hire_date) VALUES (?, ?, ?, ?, ?, ?, CURDATE())')
        ->execute([$newUserId, $firstName, $lastName, $phone, $role, $branchId]);
    $newEmployeeId = $pdo->lastInsertId();

    if (!empty($serviceIds)) {
        $insServ = $pdo->prepare('INSERT INTO staff_services (employee_id, service_id) VALUES (?, ?)');
        foreach ($serviceIds as $serviceId) {
            $insServ->execute([$newEmployeeId, $serviceId]);
        }
    }

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "STAFF", ?)')
        ->execute(["New staff member added: {$name}."]);

    $pdo->commit();

    AccountSetup::issueAndEmail($pdo, (int) $newUserId, $email, $name, 'Staff');

    echo json_encode([
        'success' => true,
        'message' => "Staff member added. An account setup email has been sent to {$email}.",
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('admin saveStaff error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving the staff member.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server configuration error occurred.']);
}
