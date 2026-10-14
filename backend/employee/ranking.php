<?php

/**
 * Staff performance deep-dive: a 6-month completed/rating trend plus the
 * full feedback history for the logged-in staff member. The dashboard's
 * getDashboardData.php only ships a 5-item feedback preview + running
 * totals; this is fetched lazily when the "My Performance" panel is opened
 * so the main dashboard load stays lean.
 */

require_once '../config/cors.php';
require_once '../config/session.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || ($_SESSION['user_role'] ?? null) !== 'Staff') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a staff member.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT id FROM employees WHERE user_id = ?');
    $stmt->execute([$userId]);
    $employeeId = $stmt->fetchColumn();
    if (!$employeeId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No staff profile is linked to this account.']);
        exit();
    }

    // --- Completed count + average rating + days attended per month, last 6
    // months. The 6 month buckets are generated in PHP (not derived from
    // GROUP BY on either table) so a month with attendance but zero
    // appointments -- or vice versa -- still gets its own row instead of
    // silently disappearing from the chart. ---
    $monthKeys = [];
    for ($i = 5; $i >= 0; $i--) {
        $monthKeys[] = date('Y-m', strtotime("-{$i} months"));
    }

    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(a.appointment_datetime, '%Y-%m') AS ym,
            SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) AS completed,
            ROUND(AVG(f.rating), 1) AS avgRating
        FROM appointments a
        LEFT JOIN feedback f ON f.appointment_id = a.id
        WHERE a.employee_id = ? AND a.appointment_datetime >= (CURDATE() - INTERVAL 6 MONTH)
        GROUP BY ym
    ");
    $stmt->execute([$employeeId]);
    $completedByMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $completedByMonth[$row['ym']] = [
            'completed' => (int) $row['completed'],
            'avgRating' => $row['avgRating'] !== null ? (float) $row['avgRating'] : 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(clock_in_time, '%Y-%m') AS ym, COUNT(DISTINCT DATE(clock_in_time)) AS daysAttended
        FROM attendance
        WHERE employee_id = ? AND clock_in_time >= (CURDATE() - INTERVAL 6 MONTH)
        GROUP BY ym
    ");
    $stmt->execute([$employeeId]);
    $attendanceByMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $attendanceByMonth[$row['ym']] = (int) $row['daysAttended'];
    }

    $monthlyTrend = array_map(function ($ym) use ($completedByMonth, $attendanceByMonth) {
        return [
            'label' => date('M Y', strtotime($ym . '-01')),
            'completed' => $completedByMonth[$ym]['completed'] ?? 0,
            'avgRating' => $completedByMonth[$ym]['avgRating'] ?? 0,
            'daysAttended' => $attendanceByMonth[$ym] ?? 0,
        ];
    }, $monthKeys);

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT DATE(clock_in_time)) FROM attendance
        WHERE employee_id = ? AND clock_in_time >= (NOW() - INTERVAL 30 DAY)
    ");
    $stmt->execute([$employeeId]);
    $attendanceDays = (int) $stmt->fetchColumn();
    $attendanceRate = min(100, (int) round(($attendanceDays / 30) * 100));

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
    ");
    $stmt->execute([$employeeId]);
    $allFeedback = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['rating'] = (int) $row['rating'];
        return $row;
    }, $stmt->fetchAll());

    echo json_encode([
        'success' => true,
        'monthlyTrend' => $monthlyTrend,
        'allFeedback' => $allFeedback,
        'attendanceDays' => $attendanceDays,
        'attendanceRate' => $attendanceRate,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('employee ranking error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading performance data.']);
}
