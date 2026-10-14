<?php

/**
 * Admin: Create/Edit Service (the base service catalog, distinct from
 * promotions which just discount an existing service).
 *
 * duration_label isn't part of the admin form -- it's derived from
 * durationMinutes so it always matches (e.g. 90 -> "1 hr 30 min").
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/Scheduling.php';

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

function formatDurationLabel(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours === 0) {
        return "{$mins} min";
    }
    $label = $hours . ' hr' . ($hours > 1 ? 's' : '');
    if ($mins > 0) {
        $label .= " {$mins} min";
    }
    return $label;
}

$serviceId = (int) ($_POST['id'] ?? 0);
$branchKey = trim($_POST['branchId'] ?? '');
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$category = trim($_POST['category'] ?? '');
$durationMinutes = (int) ($_POST['durationMinutes'] ?? 0);
$price = filter_var(trim($_POST['price'] ?? ''), FILTER_VALIDATE_FLOAT);
$active = filter_var($_POST['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

// Loyalty scoring weight -- 1.00 is the standard rate (1 point per PHP20);
// admins can raise this for services they want to reward more heavily
// (e.g. bridal packages) or lower it for discount add-ons. See
// backend/admin/completeCheckout.php and backend/cashier/payment.php for
// how this is applied at checkout.
$loyaltyMultiplierRaw = trim($_POST['loyaltyMultiplier'] ?? '1');
$loyaltyMultiplier = $loyaltyMultiplierRaw === '' ? 1.0 : filter_var($loyaltyMultiplierRaw, FILTER_VALIDATE_FLOAT);

// Reservation payment rule -- the single source of truth
// ReservationPayment::quote() reads at booking time, so the customer/guest
// booking flow and cashier logic never hardcode this separately.
$allowedPaymentRequirements = ['50% Down Payment', 'Full Payment', 'No Online Reservation'];
$paymentRequirementInput = trim($_POST['paymentRequirement'] ?? '50% Down Payment');
// 'Half Payment' is the legacy DB value for the same rule as '50% Down Payment'.
$paymentRequirement = $paymentRequirementInput === '50% Down Payment' ? 'Half Payment' : $paymentRequirementInput;
if (!in_array($paymentRequirementInput, $allowedPaymentRequirements, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid reservation payment rule.']);
    exit();
}

if ($durationMinutes > 0 && $durationMinutes < Scheduling::MIN_SERVICE_MINUTES) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A service must be at least ' . Scheduling::MIN_SERVICE_MINUTES . ' minutes long.']);
    exit();
}

if ($branchKey === '' || $name === '' || $durationMinutes <= 0 || $price === false || $price < 0
    || $loyaltyMultiplier === false || $loyaltyMultiplier <= 0 || $loyaltyMultiplier > 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Branch, name, a valid duration (in minutes), price, and a loyalty weight between 0.01 and 10 are required.']);
    exit();
}

$durationLabel = formatDurationLabel($durationMinutes);

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

    if ($serviceId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM services WHERE id = ?');
        $stmt->execute([$serviceId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Service not found.']);
            exit();
        }

        $pdo->prepare('
            UPDATE services SET branch_id = ?, service_name = ?, description = ?, category = ?,
                duration_minutes = ?, duration_label = ?, price = ?, loyalty_multiplier = ?, payment_requirement = ?, is_active = ?
            WHERE id = ?
        ')->execute([$branchId, $name, $description ?: null, $category ?: null, $durationMinutes, $durationLabel, $price, $loyaltyMultiplier, $paymentRequirement, $active ? 1 : 0, $serviceId]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "SERVICE", ?)')
            ->execute(["Service updated: {$name}."]);

        echo json_encode(['success' => true, 'message' => 'Service updated.']);
        exit();
    }

    $pdo->prepare('
        INSERT INTO services (branch_id, service_name, description, category, duration_minutes, duration_label, price, loyalty_multiplier, payment_requirement, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$branchId, $name, $description ?: null, $category ?: null, $durationMinutes, $durationLabel, $price, $loyaltyMultiplier, $paymentRequirement, $active ? 1 : 0]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "SERVICE", ?)')
        ->execute(["New service added: {$name}."]);

    echo json_encode(['success' => true, 'message' => 'Service created.']);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin saveService error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while saving the service.']);
}
