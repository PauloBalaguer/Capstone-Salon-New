<?php

/**
 * Public: Get Branch Services
 *
 * No login required powers the "Quick Guest Booking" service checkboxes
 * on the public landing page.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/booking/ServiceDuration.php';
require_once '../../backend/shared/config/payments/ReservationPayment.php';
require_once '../../backend/shared/config/booking/ServiceGroup.php';

sendCorsHeaders();
header('Content-Type: application/json');

$branchId = (int) ($_GET['branch'] ?? 0);

if ($branchId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid branch is required.']);
    exit();
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('
        SELECT s.id, s.service_name AS name, s.category, s.duration_label AS duration, s.duration_minutes AS durationMinutes, s.price, s.payment_requirement AS paymentRequirement,
               COALESCE(bc.bookings, 0) AS bookingCount
        FROM services s
        LEFT JOIN (
            SELECT aps.service_id, COUNT(*) AS bookings
            FROM appointment_services aps
            JOIN appointments a ON a.id = aps.appointment_id
            WHERE a.status <> "Cancelled"
            GROUP BY aps.service_id
        ) bc ON bc.service_id = s.id
        WHERE s.branch_id = ? AND s.is_active = 1
        ORDER BY bookingCount DESC, s.category, s.service_name
    ');
    $stmt->execute([$branchId]);

    $services = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['price'] = (float) $row['price'];
        $row['durationMinutes'] = (int) $row['durationMinutes'];
        $row['duration'] = ServiceDuration::format($row['durationMinutes']);
        $row['bookingCount'] = (int) $row['bookingCount'];
        $row['bestSeller'] = (bool) preg_match('/best ?sell/i', (string) $row['category']);
        $row['group'] = serviceDisplayGroup((string) $row['category'], (string) $row['name']);
        $preview = ReservationPayment::quote([['id'=>$row['id'], 'service_name'=>$row['name'], 'price'=>$row['price'], 'payment_requirement'=>$row['paymentRequirement']]]);
        $row['reservationAmount'] = $preview['amountDue'];
        $row['reservationLabel'] = $preview['reservationRequirement'];
        return $row;
    }, $stmt->fetchAll());

    echo json_encode(['success' => true, 'services' => $services]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getBranchServices error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading services.']);
}
