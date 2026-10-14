<?php

/**
 * Public: Get Staff Roster
 *
 * No login required — powers the Stylists & Team page's per-branch roster,
 * including each employee's live shift_status (kept up to date by the
 * automatic staff login/logout attendance system, see backend/auth/login.php
 * and Scheduling::autoCloseAttendance). Front Desk roles (Cashier, Stock
 * Clerk) are included for the team photo but marked not bookable, matching
 * getAvailableStaff.php which only ever offers Hair Stylist / Nail
 * Technician / Aesthetics Specialist for an appointment.
 */

require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../config/Scheduling.php';

sendCorsHeaders();
header('Content-Type: application/json');

$branchId = (int) ($_GET['branch'] ?? 0);
$branchKey = trim($_GET['branchKey'] ?? '');

if ($branchId <= 0 && $branchKey === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A branch is required.']);
    exit();
}

// Bookable roles group under the exact title getAvailableStaff.php's
// "role" column already shows on a booking; Front Desk staff are shown to
// visitors but never appear in that booking flow.
$roleGroups = [
    'Hair Stylist' => 'Hair',
    'Nail Technician' => 'Nails',
    'Aesthetics Specialist' => 'Aesthetics',
    'Cashier' => 'Front Desk',
    'Stock Clerk' => 'Front Desk',
];
$bookableGroups = ['Hair', 'Nails', 'Aesthetics'];

try {
    $pdo = Database::getInstance();

    if ($branchId > 0) {
        $stmt = $pdo->prepare('SELECT id, branch_name, branch_key FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$branchId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, branch_name, branch_key FROM branches WHERE branch_key = ? LIMIT 1');
        $stmt->execute([$branchKey]);
    }
    $branch = $stmt->fetch();
    if (!$branch) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid branch selected.']);
        exit();
    }

    // Anyone who forgot to sign out is clocked out once closing time passes.
    Scheduling::autoCloseBranchAttendance($pdo, (int) $branch['id']);

    // Live status comes from real attendance, not employees.shift_status
    // (nothing updates that column on login anymore -- same derivation as
    // backend/cashier/getDashboardData.php): clocked in today and not out
    // = on duty; on duty and mid-appointment right now = with a client.
    // Uses MySQL's own NOW(), which stamped both clock-ins and bookings.
    $stmt = $pdo->prepare('
        SELECT e.id, e.first_name, e.last_name, e.position, e.profile_picture, e.hire_date,
            CASE
                WHEN NOT EXISTS (
                    SELECT 1 FROM attendance att WHERE att.employee_id = e.id
                      AND DATE(att.clock_in_time) = CURDATE() AND att.clock_out_time IS NULL
                ) THEN "Off Shift"
                WHEN EXISTS (
                    SELECT 1 FROM appointments ap WHERE ap.employee_id = e.id AND (
                        (ap.status = "In Progress" AND DATE(ap.appointment_datetime) = CURDATE())
                        OR (ap.status = "Confirmed" AND NOW() >= ap.appointment_datetime
                            AND NOW() < ap.appointment_datetime + INTERVAL (
                                SELECT COALESCE(NULLIF(SUM(s.duration_minutes), 0), 30)
                                FROM appointment_services aps JOIN services s ON s.id = aps.service_id
                                WHERE aps.appointment_id = ap.id
                            ) MINUTE)
                    )
                ) THEN "With Client"
                ELSE "On Duty"
            END AS live_status
        FROM employees e
        WHERE e.branch_id = ? AND e.is_active = 1
        ORDER BY FIELD(e.position, "Hair Stylist", "Nail Technician", "Aesthetics Specialist", "Cashier", "Stock Clerk"), e.first_name, e.last_name
    ');
    $stmt->execute([$branch['id']]);
    $employees = $stmt->fetchAll();

    $groups = [];
    foreach ($employees as $e) {
        $groupTitle = $roleGroups[$e['position']] ?? 'Team';
        if (!isset($groups[$groupTitle])) {
            $groups[$groupTitle] = ['title' => $groupTitle, 'bookable' => in_array($groupTitle, $bookableGroups, true), 'staff' => []];
        }
        $groups[$groupTitle]['staff'][] = [
            'id' => (string) $e['id'],
            'name' => trim($e['first_name'] . ' ' . $e['last_name']),
            'position' => $e['position'] ?: $groupTitle,
            'photo' => $e['profile_picture'] ?: null,
            'status' => $e['live_status'],
            'hireDate' => $e['hire_date'],
        ];
    }

    // Fixed display order regardless of which roles happen to have staff.
    // array_search's 0 index (Hair) is falsy, so ?: would wrongly push it
    // to the end -- compare against false explicitly instead.
    $order = ['Hair', 'Nails', 'Aesthetics', 'Front Desk'];
    $rank = fn($title) => ($i = array_search($title, $order)) !== false ? $i : 99;
    uksort($groups, fn($a, $b) => $rank($a) <=> $rank($b));

    echo json_encode([
        'success' => true,
        'branch' => ['name' => $branch['branch_name'], 'key' => $branch['branch_key']],
        'roles' => array_values($groups),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getStaffRoster error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading the team roster.']);
}
