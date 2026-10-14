<?php

/**
 * Cancel Appointment API Endpoint
 *
 * Marks one of the logged-in customer's own appointments as Cancelled and
 * applies the deposit policy (backend/config/CancellationPolicy.php): a held
 * deposit is non-refundable and is kept, but the cancelled booking can still
 * be rescheduled later to use it (backend/config/Reschedule.php).
 * Ownership is enforced by joining through the session's customer_id —
 * a customer can never cancel someone else's appointment by guessing an id.
 *
 * POST preview=1 returns what would happen to the deposit without
 * cancelling, so the cancel dialog can warn the customer first.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/AuditLog.php';
require_once '../config/CancellationPolicy.php';
require_once '../config/StaffNotifier.php';

sendCorsHeaders();
AuditLog::captureRequest();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to manage your appointments.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$referenceCode = trim($_POST['appointment_id'] ?? '');
$previewOnly = filter_var($_POST['preview'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($referenceCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Appointment ID was not provided.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        SELECT a.' . str_replace(', ', ', a.', CancellationPolicy::COLUMNS) . '
        FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        WHERE a.reference_code = ? AND c.user_id = ? AND a.status NOT IN ("Cancelled", "Completed", "Reviewed", "No-Show")
        FOR UPDATE
    ');
    $stmt->execute([$referenceCode, $_SESSION['user_id']]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment not found or it can no longer be cancelled.']);
        exit();
    }

    $outcome = CancellationPolicy::outcome($pdo, $appointment, CancellationPolicy::BY_CUSTOMER);
    if ($previewOnly) {
        $pdo->rollBack();
        echo json_encode([
            'success' => true,
            'depositOutcome' => $outcome, // null | 'Refund Due' | 'Forfeited'
            'depositAmount' => (float) $appointment['deposit_amount'],
            'freeCancelHours' => CancellationPolicy::FREE_CANCEL_HOURS,
        ]);
        exit();
    }

    $pdo->prepare('UPDATE appointments SET status = "Cancelled" WHERE id = ?')->execute([$appointment['id']]);
    CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'CANCELLED', "You cancelled your appointment {$referenceCode}.");
    $depositNote = CancellationPolicy::apply($pdo, $appointment, CancellationPolicy::BY_CUSTOMER);
    if ($appointment['employee_id']) {
        StaffNotifier::notify($pdo, (int) $appointment['employee_id'], 'APPOINTMENT_CANCELLED', "Appointment {$referenceCode} was cancelled by the customer.");
    }
    // Branch-wide log (admin/cashier notification bell) -- without this the
    // salon never heard about customer cancellations, including deposits
    // that now need refunding.
    $when = date('M j, Y g:i A', strtotime($appointment['appointment_datetime']));
    $depositLine = match ($outcome) {
        'Refund Due' => ' Deposit of ₱' . number_format((float) $appointment['deposit_amount'], 2) . ' is Refund Due — process it in Admin > Appointments.',
        'Forfeited' => ' Deposit of ₱' . number_format((float) $appointment['deposit_amount'], 2) . ' kept (non-refundable) — the customer can reschedule to use it.',
        default => '',
    };
    $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
        ->execute(["Booking {$referenceCode} ({$when}) was cancelled by the customer.{$depositLine}"]);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => trim("Appointment #{$referenceCode} has been successfully cancelled. {$depositNote}"),
        'paymentStatus' => $outcome,
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('cancelAppointment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while cancelling your appointment.']);
}
