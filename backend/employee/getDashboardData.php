<?php

/**
 * Staff Dashboard Data API Endpoint
 *
 * Returns everything every pages/staff/*.html page needs in one request:
 * the logged-in staff member's profile, every appointment ever assigned to
 * them, their attendance log, a compact performance summary, their most
 * recent client feedback, and their branch's inventory (for the "prepare
 * supplies" / "log usage" flows). scripts/staff/shared-data.js calls this
 * once on load and again after every mutation to refresh the Alpine store.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../config/Scheduling.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Staff') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a staff member to view this page.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('
        SELECT e.id, e.first_name, e.last_name, e.phone_number, e.profile_picture, e.position, e.hire_date, e.branch_id, br.branch_name
        FROM employees e
        LEFT JOIN branches br ON br.id = e.branch_id
        WHERE e.user_id = ?
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    $employee = $stmt->fetch();

    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No staff profile is linked to this account.']);
        exit();
    }

    $employeeId = (int) $employee['id'];
    $branchId = $employee['branch_id'];

    // No cron/background worker exists in this project and browser-close
    // events are unreliable, so Time Out is settled here (checked on every
    // dashboard load) instead -- see Scheduling::autoCloseAttendance().
    Scheduling::autoCloseAttendance($pdo, $employeeId, $branchId);

    $profile = [
        'employeeId' => $employeeId,
        'name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
        'firstName' => $employee['first_name'],
        'lastName' => $employee['last_name'],
        'phone' => $employee['phone_number'],
        'profilePicture' => $employee['profile_picture'],
        'email' => $_SESSION['user_email'],
        'role' => $employee['position'] ?: 'Staff',
        'branchId' => $branchId,
        'branchName' => $employee['branch_name'] ?? 'Unassigned Branch',
        'hireDate' => $employee['hire_date'],
    ];

    // --- Notification preferences (same users.notify_* columns the admin
    // side reads/writes -- reused here for the staff profile page). ---
    $stmt = $pdo->prepare('SELECT notify_booking, notify_inventory, notify_order, notify_marketing FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $notifyRow = $stmt->fetch();
    $notificationPrefs = [
        'bookingAlerts' => (bool) ($notifyRow['notify_booking'] ?? true),
        'inventoryAlerts' => (bool) ($notifyRow['notify_inventory'] ?? true),
        'orderAlerts' => (bool) ($notifyRow['notify_order'] ?? true),
        'marketingAlerts' => (bool) ($notifyRow['notify_marketing'] ?? false),
    ];

    $stmt = $pdo->prepare("
        SELECT
            a.reference_code AS id,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            c.phone_number AS clientPhone,
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS serviceName,
            DATE_FORMAT(a.appointment_datetime, '%Y-%m-%d') AS date,
            DATE_FORMAT(a.appointment_datetime, '%h:%i %p') AS time,
            a.appointment_datetime AS startDateTime,
            COALESCE(SUM(s.duration_minutes), 0) AS durationMinutes,
            a.status AS status,
            a.total_price AS price
        FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services s ON s.id = aps.service_id
        WHERE a.employee_id = ? OR EXISTS (SELECT 1 FROM appointment_services mine WHERE mine.appointment_id = a.id AND mine.employee_id = ?)
        GROUP BY a.id
        ORDER BY a.appointment_datetime DESC
    ");
    $stmt->execute([$employeeId, $employeeId]);
    // Per-service stylists: services run back-to-back in booking order, each
    // by its own stylist (appointment_services.employee_id, NULL = the
    // booking's main stylist). Show this staff member only their part --
    // their services and their own time window -- plus who else is on it.
    $serviceRowsStmt = $pdo->prepare("
        SELECT aps.employee_id, a.employee_id AS main_id, s.service_name, s.duration_minutes,
               CONCAT(e.first_name, ' ', e.last_name) AS stylist
        FROM appointment_services aps
        JOIN appointments a ON a.id = aps.appointment_id
        JOIN services s ON s.id = aps.service_id
        LEFT JOIN employees e ON e.id = COALESCE(aps.employee_id, a.employee_id)
        WHERE a.reference_code = ?
        ORDER BY aps.id
    ");
    $appointments = array_map(function ($row) use ($serviceRowsStmt, $employeeId) {
        $row['price'] = (float) $row['price'];
        $row['durationMinutes'] = (int) $row['durationMinutes'];
        $start = new DateTime($row['startDateTime']);
        $endTime = (clone $start)->modify('+' . $row['durationMinutes'] . ' minutes');
        $row['endTime'] = $endTime->format('h:i A');
        $row['isMainStylist'] = true;
        $row['otherStylists'] = '';

        $serviceRowsStmt->execute([$row['id']]);
        $parts = $serviceRowsStmt->fetchAll();
        if ($parts) {
            $mainId = (int) $parts[0]['main_id'];
            $row['isMainStylist'] = $mainId === $employeeId;
            $cursor = clone $start;
            $mine = [];
            $myStart = null;
            $myEnd = null;
            $others = [];
            foreach ($parts as $part) {
                $minutes = (int) $part['duration_minutes'] > 0 ? (int) $part['duration_minutes'] : 30;
                $segmentEnd = (clone $cursor)->modify("+{$minutes} minutes");
                $doneBy = $part['employee_id'] !== null ? (int) $part['employee_id'] : $mainId;
                if ($doneBy === $employeeId) {
                    $mine[] = $part['service_name'];
                    $myStart = $myStart ?? clone $cursor;
                    $myEnd = $segmentEnd;
                } elseif ($part['stylist']) {
                    $others[$part['stylist']] = true;
                }
                $cursor = $segmentEnd;
            }
            if ($mine && count($mine) < count($parts)) {
                // Shared booking: narrow to this stylist's own services and window.
                $row['serviceName'] = implode(', ', $mine);
                $row['time'] = $myStart->format('h:i A');
                $row['endTime'] = $myEnd->format('h:i A');
                $row['durationMinutes'] = (int) (($myEnd->getTimestamp() - $myStart->getTimestamp()) / 60);
            }
            $row['otherStylists'] = implode(', ', array_keys($others));
        }
        unset($row['startDateTime']);
        return $row;
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare("
        SELECT
            id,
            DATE_FORMAT(clock_in_time, '%Y-%m-%d') AS date,
            DATE_FORMAT(clock_in_time, '%h:%i %p') AS clockIn,
            clock_out_time,
            DATE_FORMAT(clock_out_time, '%h:%i %p') AS clockOut,
            notes
        FROM attendance
        WHERE employee_id = ?
        ORDER BY clock_in_time DESC
        LIMIT 30
    ");
    $stmt->execute([$employeeId]);
    $attendance = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['isOpen'] = $row['clock_out_time'] === null;
        unset($row['clock_out_time']);
        return $row;
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE employee_id = ? AND status = 'Completed'");
    $stmt->execute([$employeeId]);
    $completedCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments
        WHERE employee_id = ? AND status = 'Completed'
        AND MONTH(appointment_datetime) = MONTH(CURDATE()) AND YEAR(appointment_datetime) = YEAR(CURDATE())
    ");
    $stmt->execute([$employeeId]);
    $completedThisMonth = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT ROUND(AVG(f.rating), 1) AS avgRating, COUNT(*) AS totalReviews
        FROM feedback f
        JOIN appointments a ON a.id = f.appointment_id
        WHERE a.employee_id = ?
    ");
    $stmt->execute([$employeeId]);
    $ratingRow = $stmt->fetch();

    // --- Attendance rate (last 30 days), same calc as the admin staff list
    // (backend/admin/getDashboardData.php) so the number matches everywhere
    // it's shown. ---
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT DATE(clock_in_time)) FROM attendance
        WHERE employee_id = ? AND clock_in_time >= (NOW() - INTERVAL 30 DAY)
    ");
    $stmt->execute([$employeeId]);
    $attendanceDays = (int) $stmt->fetchColumn();

    $performance = [
        'completedCount' => $completedCount,
        'completedThisMonth' => $completedThisMonth,
        'avgRating' => $ratingRow['avgRating'] !== null ? (float) $ratingRow['avgRating'] : 0,
        'totalReviews' => (int) $ratingRow['totalReviews'],
        'attendanceDays' => $attendanceDays,
        'attendanceRate' => min(100, (int) round(($attendanceDays / 30) * 100)),
    ];

    $stmt = $pdo->prepare("
        SELECT
            f.id,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            f.rating,
            f.comments AS comment,
            DATE_FORMAT(f.created_at, '%Y-%m-%d') AS date,
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS serviceName
        FROM feedback f
        JOIN appointments a ON a.id = f.appointment_id
        JOIN customers c ON c.id = f.customer_id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services s ON s.id = aps.service_id
        WHERE a.employee_id = ?
        GROUP BY f.id
        ORDER BY f.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$employeeId]);
    $recentFeedback = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['rating'] = (int) $row['rating'];
        return $row;
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare("
        SELECT
            ia.id,
            a.reference_code AS bookingReference,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS serviceName,
            i.product_name AS itemName,
            i.quantity_on_hand AS availableQuantity,
            ia.quantity AS quantityUsed,
            DATE_FORMAT(ia.created_at, '%Y-%m-%d %h:%i %p') AS loggedAt
        FROM inventory_adjustments ia
        JOIN inventory i ON i.id = ia.inventory_id
        LEFT JOIN appointments a ON a.id = ia.appointment_id
        LEFT JOIN customers c ON c.id = a.customer_id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services s ON s.id = aps.service_id
        WHERE ia.adjusted_by = ? AND ia.adjustment_type = 'sub'
        GROUP BY ia.id
        ORDER BY ia.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$userId]);
    $supplyUsage = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['availableQuantity'] = (int) $row['availableQuantity'];
        $row['quantityUsed'] = (int) $row['quantityUsed'];
        return $row;
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare("
        SELECT id, type, message, is_read AS isRead, DATE_FORMAT(created_at, '%M %e, %Y %h:%i %p') AS time
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $notifications = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['isRead'] = (bool) $row['isRead'];
        return $row;
    }, $stmt->fetchAll());

    $inventory = [];
    if ($branchId) {
        $stmt = $pdo->prepare("
            SELECT id, product_name AS name, sku, type, quantity_on_hand AS stock, reorder_level AS minQty
            FROM inventory
            WHERE branch_id = ? AND is_active = 1
            ORDER BY product_name ASC
        ");
        $stmt->execute([$branchId]);
        $inventory = array_map(function ($row) {
            $row['id'] = (string) $row['id'];
            $row['stock'] = (int) $row['stock'];
            $row['minQty'] = (int) $row['minQty'];
            return $row;
        }, $stmt->fetchAll());
    }

    echo json_encode([
        'success' => true,
        'profile' => $profile,
        'notificationPrefs' => $notificationPrefs,
        'appointments' => $appointments,
        'attendance' => $attendance,
        'performance' => $performance,
        'recentFeedback' => $recentFeedback,
        'inventory' => $inventory,
        'supplyUsage' => $supplyUsage,
        'notifications' => $notifications,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('employee getDashboardData error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading your dashboard.']);
}
