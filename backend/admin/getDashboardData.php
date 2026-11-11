<?php

/**
 * Admin Dashboard Data API Endpoint
 *
 * Returns everything every frontend/admin/*.html page needs in one request:
 * branches (with computed revenue/rating), all bookings, staff (with
 * computed rating/completedCount/status/attendanceRate), inventory,
 * promotions, feedback, notifications, the aggregated
 * customer directory, the admin's own profile, a per-branch service
 * catalog (used by the New Booking form), and the full service catalog
 * (active + inactive, used by the Service Menu manager). function/admin/shared-data.js
 * calls this once on load and again after every mutation to refresh the
 * Alpine store.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/booking/ServiceGroup.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/helpers/LoyaltyTier.php';
require_once '../../backend/shared/config/helpers/StaffMetrics.php';
require_once '../../backend/shared/config/payments/RevenueReport.php';
require_once '../../backend/shared/config/booking/Scheduling.php';
require_once '../../backend/shared/config/helpers/StaffShift.php';
require_once __DIR__ . '/../../backend/shared/config/helpers/Schema.php';

sendCorsHeaders();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as an administrator to view this page.']);
    exit();
}

try {
    $pdo = Database::getInstance();
    $userId = $_SESSION['user_id'];
    $selection = null;
    if (isset($_GET['bookingPage'])) {
        require_once '../../backend/shared/config/helpers/AdminBookingList.php';
        $selection = AdminBookingList::select($pdo, $_GET);
    }
    $appointmentWhere = $selection === null ? '' : 'WHERE a.id IN (' . implode(',', array_map('intval', $selection['salonIds']) ?: [0]) . ')';
    $homeServiceWhere = $selection === null ? '' : 'WHERE h.id IN (' . implode(',', array_map('intval', $selection['homeIds']) ?: [0]) . ')';

    if (!isset($_GET['bookingsOnly']) || $selection === null) {
    foreach ($pdo->query('SELECT id FROM branches')->fetchAll(PDO::FETCH_COLUMN) as $branchIdToSettle) {
        Scheduling::autoCloseBranchAttendance($pdo, (int) $branchIdToSettle);
    }
    $revenueTotals = RevenueReport::totals($pdo);
    // Migration 062 columns are optional: safe defaults until it is applied.
    $offersSql = Schema::select($pdo, 'branches', 'offers_home_service', 'br.offers_home_service', 'offersHomeService', '1');
    $apFollowSql = Schema::select($pdo, 'appointments', 'needs_followup', 'a.needs_followup', 'needsFollowup');
    $hsFollowSql = Schema::select($pdo, 'home_service_requests', 'needs_followup', 'h.needs_followup', 'needsFollowup');
    $wpReturnSql = Schema::select($pdo, 'wedding_packages', 'return_travel_minutes', 'return_travel_minutes', 'return_travel_minutes');
    $edReturnSql = Schema::select($pdo, 'event_type_durations', 'return_travel_minutes', 'return_travel_minutes', 'return_travel_minutes');
    $stmt = $pdo->query("
        SELECT
            br.id, br.branch_key AS branchKey, br.branch_name AS name,
            br.location, br.branch_type AS type,
            br.opening_time AS openingTime, br.closing_time AS closingTime,
            br.sunday_opening_time AS sundayOpeningTime, br.sunday_closing_time AS sundayClosingTime,
            br.slot_limit AS slotLimit,
            {$offersSql},
            0 AS revenue,
            (SELECT ROUND(AVG(f.rating), 1) FROM feedback f
                JOIN appointments ap2 ON ap2.id = f.appointment_id
                WHERE ap2.branch_id = br.id) AS rating,
            (SELECT COUNT(*) FROM feedback f
                JOIN appointments ap2b ON ap2b.id = f.appointment_id
                WHERE ap2b.branch_id = br.id) AS ratingCount
        FROM branches br
        WHERE br.is_active = 1
        ORDER BY br.id
    ");
    $branches = array_map(function ($row) use ($revenueTotals) {
        return [
            'id' => $row['branchKey'],
            'name' => $row['name'],
            'location' => $row['location'],
            'type' => $row['type'],
            'revenue' => (float) ($revenueTotals[$row['branchKey']] ?? 0),
            'rating' => $row['rating'] !== null ? (float) $row['rating'] : 0,
            'ratingCount' => (int) $row['ratingCount'],
            'slotLimit' => (int) $row['slotLimit'],
            'offersHomeService' => (bool) $row['offersHomeService'],
            'openingTime' => substr($row['openingTime'], 0, 5),
            'closingTime' => substr($row['closingTime'], 0, 5),
            'sundayOpeningTime' => $row['sundayOpeningTime'] ? substr($row['sundayOpeningTime'], 0, 5) : '',
            'sundayClosingTime' => $row['sundayClosingTime'] ? substr($row['sundayClosingTime'], 0, 5) : '',
        ];
    }, $stmt->fetchAll());

    }

    $stmt = $pdo->query("
        SELECT
            a.reference_code AS id,
            a.created_at AS createdAt, a.notes AS notes, c.email AS clientEmail,
            c.id AS customerId, (c.user_id IS NOT NULL) AS isRegistered,
            a.deposit_paid AS depositPaid, a.reservation_amount_due AS requiredAmount,
            br.branch_key AS branchId,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            c.phone_number AS clientPhone,
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS serviceName,
            a.employee_id AS staffId,
            COALESCE((SELECT GROUP_CONCAT(DISTINCT CONCAT(se.first_name, ' ', se.last_name) ORDER BY sx.id SEPARATOR ', ') FROM appointment_services sx JOIN employees se ON se.id = COALESCE(sx.employee_id, a.employee_id) WHERE sx.appointment_id = a.id), CONCAT(e.first_name, ' ', e.last_name)) AS staffName,
            DATE_FORMAT(a.appointment_datetime, '%Y-%m-%d') AS date,
            DATE_FORMAT(a.appointment_datetime, '%h:%i %p') AS time,
            a.appointment_datetime AS startDateTime,
            COALESCE(SUM(s.duration_minutes), 0) AS durationMinutes,
            a.status AS status,
            {$apFollowSql},
            a.payment_status AS paymentStatus,
            EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.id AND p.status = 'Paid') AS checkoutPaid,
            (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.appointment_id = a.id AND p.status = 'Paid')
                + CASE WHEN a.deposit_paid = 1 AND a.deposit_recorded_by IS NOT NULL
                    AND EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.id AND p.status = 'Paid')
                    THEN COALESCE(a.deposit_amount, 0) ELSE 0 END AS checkoutRevenue,
            a.total_price AS price,
            a.preferred_payment_method AS paymentMethod,
            a.deposit_amount AS depositAmount,
            a.deposit_method AS depositMethod,
            a.deposit_reference AS depositReference,
            a.deposit_recorded_by IS NOT NULL AS depositVerified,
            EXISTS (
                SELECT 1 FROM appointments a2
                WHERE a2.branch_id = a.branch_id AND a2.employee_id = a.employee_id
                  AND a2.appointment_datetime = a.appointment_datetime
                  AND a2.status = 'Confirmed' AND a2.id != a.id AND a.employee_id IS NOT NULL
            ) AS hasConflict
        FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        LEFT JOIN branches br ON br.id = a.branch_id
        LEFT JOIN employees e ON e.id = a.employee_id
        LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
        LEFT JOIN services s ON s.id = aps.service_id
        $appointmentWhere
        GROUP BY a.id
        ORDER BY a.created_at DESC, a.reference_code DESC
    ");
    $bookings = array_map(function ($row) {
        $row['staffId'] = $row['staffId'] !== null ? (string) $row['staffId'] : null;
        $row['price'] = (float) $row['price'];
        $row['depositAmount'] = $row['depositAmount'] !== null ? (float) $row['depositAmount'] : null;
        $row['depositVerified'] = (bool) $row['depositVerified'];
        $row['depositPaid'] = (bool) $row['depositPaid'];
        $row['remainingBalance'] = $row['checkoutPaid'] ? 0 : max(0, $row['price'] - ($row['depositPaid'] && $row['depositVerified'] ? (float) $row['depositAmount'] : 0));
        $row['checkoutPaid'] = (bool) $row['checkoutPaid'];
        $row['checkoutRevenue'] = (float) $row['checkoutRevenue'];
        $row['isHomeService'] = false;
        $row['customerId'] = (string) $row['customerId'];
        $row['customerType'] = $row['isRegistered'] ? 'Registered' : 'Guest';
        unset($row['isRegistered']);
        $row['bookingType'] = $row['depositMethod'] === 'Walk-in' ? 'Walk-In' : 'Appointment';
        $row['hasConflict'] = (bool) $row['hasConflict'];
        $row['durationMinutes'] = (int) $row['durationMinutes'];
        $endTime = new DateTime($row['startDateTime']);
        $endTime->modify('+' . $row['durationMinutes'] . ' minutes');
        $row['endTime'] = $endTime->format('h:i A');
        unset($row['startDateTime']);
        return $row;
    }, $stmt->fetchAll());

    // --- Home service requests, folded into the same bookings list (tagged
    // isHomeService) so the admin panel manages off-site jobs alongside
    // in-salon appointments instead of a separate screen. Not tied to a
    // branch/price/time the way an appointment is. Both booking types expose
    // their immutable public reference as id; internal keys stay private. ---
    $stmt = $pdo->query("
        SELECT
            h.reference_code AS id, h.submitted_at AS createdAt, c.email AS clientEmail,
            c.id AS customerId, (c.user_id IS NOT NULL) AS isRegistered,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName,
            c.phone_number AS clientPhone,
            h.event_type AS eventType,
            h.wedding_package AS weddingPackage,
            h.address AS address,
            h.requests AS requestNotes,
            h.quote_price AS quotePrice,
            h.employee_id AS staffId,
            COALESCE((SELECT GROUP_CONCAT(CONCAT(te.first_name, ' ', te.last_name) ORDER BY t.id SEPARATOR ', ') FROM home_service_staff t JOIN employees te ON te.id = t.employee_id WHERE t.home_service_request_id = h.id),
                     CASE WHEN e.id IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name) END) AS staffName,
            (SELECT GROUP_CONCAT(t2.employee_id ORDER BY t2.id) FROM home_service_staff t2 WHERE t2.home_service_request_id = h.id) AS teamIds,
            h.reschedule_count AS rescheduleCount,
            (SELECT COALESCE(SUM(hp.amount), 0) FROM home_service_payments hp WHERE hp.home_service_request_id = h.id) AS amountPaid,
            h.balance_checkout_url AS balancePayUrl,
            DATE_FORMAT(h.preferred_date, '%Y-%m-%d') AS date,
            DATE_FORMAT(h.preferred_time, '%h:%i %p') AS time,
            h.status AS status,
            {$hsFollowSql},
            hb.branch_key AS homeBranchKey,
            hb.branch_name AS homeBranchName,
            h.payment_status AS paymentStatus,
            h.deposit_amount AS depositAmount,
            h.deposit_method AS paymentMethod,
            h.deposit_reference AS depositReference,
            (h.deposit_recorded_by IS NOT NULL OR (h.deposit_paid = 1 AND h.deposit_method IN ('PayMongo', 'PayMongo-GCash', 'PayMongo-Maya', 'PayMongo-Card'))) AS depositVerified
        FROM home_service_requests h
        JOIN customers c ON c.id = h.customer_id
        LEFT JOIN employees e ON e.id = h.employee_id
        LEFT JOIN branches hb ON hb.id = h.branch_id
        $homeServiceWhere
        ORDER BY h.submitted_at DESC, h.reference_code DESC
    ");
    $homeServiceBookings = array_map(function ($row) {
        $quotePrice = $row['quotePrice'] !== null ? (float) $row['quotePrice'] : null;
        $depositAmount = $row['depositAmount'] !== null ? (float) $row['depositAmount'] : null;
        $depositVerified = (bool) $row['depositVerified'];
        $remainingBalance = $quotePrice !== null
            ? max(0, round($quotePrice - (float) $row['amountPaid'], 2)) // every payment received (home_service_payments)
            : null;
        return [
            'id' => $row['id'],
            'createdAt' => $row['createdAt'], 'clientEmail' => $row['clientEmail'],
            'customerId' => (string) $row['customerId'],
            'customerType' => $row['isRegistered'] ? 'Registered' : 'Guest',
            'branchId' => null,
            // Responsible branch (migration 062). Older rows: "Branch not set" for Admin to fix.
            'homeBranchKey' => $row['homeBranchKey'],
            'homeBranchName' => $row['homeBranchName'] ?: 'Branch not set',
            'needsFollowup' => $row['needsFollowup'],
            // No stylist yet: shown as "Pending assignment"; it reserves nobody.
            'assignmentLabel' => $row['staffId'] === null && !$row['teamIds'] ? 'Pending assignment'
                : (in_array($row['status'], ['Confirmed', 'Staff Assigned', 'Service in Progress', 'Completed'], true) ? 'Assigned' : 'Pre-assigned (not reserved until Confirmed)'),
            'clientName' => $row['clientName'],
            'clientPhone' => $row['clientPhone'],
            'serviceName' => $row['eventType'],
            'eventType' => $row['eventType'],
            'weddingPackage' => $row['weddingPackage'],
            'staffId' => $row['staffId'] !== null ? (string) $row['staffId'] : null,
            'staffName' => $row['staffName'],
            'date' => $row['date'],
            'time' => $row['time'] ?: '',
            'endTime' => '',
            'durationMinutes' => null,
            'status' => $row['status'],
            'paymentStatus' => $row['paymentStatus'],
            'bookingType' => 'Home Service',
            'price' => null,
            'quotePrice' => $quotePrice,
            'remainingBalance' => $remainingBalance,
            'depositAmount' => $depositAmount,
            'paymentMethod' => $row['paymentMethod'],
            'depositReference' => $row['depositReference'],
            'depositVerified' => $depositVerified,
            'isHomeService' => true,
            // The whole team (home_service_staff); staffId is the team lead.
            'staffIds' => $row['teamIds'] ? explode(',', $row['teamIds']) : ($row['staffId'] !== null ? [(string) $row['staffId']] : []),
            'rescheduleCount' => (int) $row['rescheduleCount'],
            'amountPaid' => (float) $row['amountPaid'],
            'balancePayUrl' => $row['balancePayUrl'],
            'hasConflict' => false,
            'address' => $row['address'],
            'venue' => $row['address'],
            'requests' => $row['requestNotes'],
        ];
    }, $stmt->fetchAll());

    $bookings = array_merge($bookings, $homeServiceBookings);
    usort($bookings, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? '') ?: strcmp($b['id'], $a['id']));
    if (isset($_GET['bookingsOnly']) && $selection !== null) {
        echo json_encode(['success'=>true, 'bookings'=>$bookings, 'bookingPagination'=>$selection['pagination']]);
        exit();
    }

    // Same live-status rule as the cashier/staff portal: settle forgotten
    // sign-outs first, then derive status from real attendance.
    foreach ($pdo->query('SELECT id FROM branches')->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        Scheduling::autoCloseBranchAttendance($pdo, (int) $bid);
    }

    $stmt = $pdo->query("
        SELECT
            e.id,
            CONCAT(e.first_name, ' ', e.last_name) AS name,
            e.first_name AS firstName, e.middle_name AS middleName, e.last_name AS lastName,
            br.branch_key AS branchId,
            e.position AS role, ue.email AS email,
            (SELECT COUNT(*) FROM appointments ap WHERE " . StaffMetrics::worksOnSql('ap', 'e.id') . " AND ap.status IN " . StaffMetrics::FINISHED_STATUSES_SQL . ") AS completedCount,
            (SELECT ROUND(AVG(f.rating), 1) FROM feedback f
                JOIN appointments ap2 ON ap2.id = f.appointment_id
                WHERE " . StaffMetrics::worksOnSql('ap2', 'e.id') . ") AS avgRating,
            " . StaffShift::liveStatusSql('e') . " AS liveStatus,
            " . StaffMetrics::attendedDaysSql('e') . " AS attendanceDays,
            " . StaffMetrics::branchOpenDaysSql('e') . " AS branchOpenDays,
            (SELECT COUNT(*) FROM attendance at3 WHERE at3.employee_id = e.id AND at3.notes IS NOT NULL
                AND at3.clock_in_time >= (NOW() - INTERVAL 30 DAY)) AS missedSignOuts,
            (SELECT GROUP_CONCAT(ss.service_id) FROM staff_services ss WHERE ss.employee_id = e.id) AS serviceIdsRaw,
            (SELECT GROUP_CONCAT(sv.service_name ORDER BY sv.id SEPARATOR ', ') FROM staff_services ss
                JOIN services sv ON sv.id = ss.service_id WHERE ss.employee_id = e.id) AS specialties
        FROM employees e
        LEFT JOIN users ue ON ue.id = e.user_id
        LEFT JOIN branches br ON br.id = e.branch_id
        WHERE e.is_active = 1
        ORDER BY e.id
    ");
    // Live status: the shared rule in StaffShift::liveStatusSql() -- a stable
    // key (available / busy / busy_home_service / on_break / off_shift) plus
    // its label and dot colour -- the same one every other panel uses.
    $staffList = array_map(function ($row) {
        $status = $row['liveStatus'];
        return [
            'id' => (string) $row['id'],
            'name' => $row['name'],
            'firstName' => $row['firstName'],
            'middleName' => $row['middleName'] ?? '',
            'lastName' => $row['lastName'],
            'branchId' => $row['branchId'],
            'role' => $row['role'],
            'email' => $row['email'],
            'completedCount' => (int) $row['completedCount'],
            'rating' => $row['avgRating'] !== null ? (float) $row['avgRating'] : 0,
            'status' => $status,
            'statusLabel' => StaffShift::statusLabel($status),
            'statusColor' => StaffShift::statusColor($status),
            'attendance' => StaffMetrics::attendanceRate((int) $row['attendanceDays'], (int) $row['branchOpenDays']),
            'missedSignOuts' => (int) $row['missedSignOuts'],
            'serviceIds' => $row['serviceIdsRaw'] ? explode(',', $row['serviceIdsRaw']) : [],
            'specialties' => $row['specialties'] ?: 'No specialties set',
        ];
    }, $stmt->fetchAll());

    // --- Cashier accounts (plain users rows locked to a branch -- no
    // employees profile, since a cashier is a front-desk login, not a
    // roster member tracked for attendance/performance) ---
    $stmt = $pdo->query("
        SELECT u.id, u.display_name AS name, u.email, br.branch_key AS branchId
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN branches br ON br.id = u.branch_id
        WHERE r.role_name = 'Cashier' AND u.is_active = 1
        ORDER BY u.id
    ");
    $cashierList = array_map(function ($row) {
        return [
            'id' => (string) $row['id'],
            'name' => $row['name'] ?: $row['email'],
            'email' => $row['email'],
            'branchId' => $row['branchId'],
        ];
    }, $stmt->fetchAll());

    $stmt = $pdo->query("
        SELECT i.id, i.product_name AS name, i.category AS category, br.branch_key AS branchId,
               i.quantity_on_hand AS stock, i.reorder_level AS minQty,
               i.cost_price AS costPrice, i.sale_price AS salePrice,
               i.supplier AS supplier, i.is_active AS isActive
        FROM inventory i
        LEFT JOIN branches br ON br.id = i.branch_id
        ORDER BY i.id
    ");
    $inventory = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['stock'] = (int) $row['stock'];
        $row['minQty'] = (int) $row['minQty'];
        $row['costPrice'] = (float) $row['costPrice'];
        $row['salePrice'] = $row['salePrice'] !== null ? (float) $row['salePrice'] : null;
        $row['isActive'] = (bool) $row['isActive'];
        return $row;
    }, $stmt->fetchAll());

    // --- Promotions (single branch + structured discount, shaped for the
    // existing display bindings: branchIds is a 1-item array, discount is a
    // pre-formatted label like "20% OFF" / "PHP 500 OFF") ---
    $stmt = $pdo->query("
        SELECT p.id, p.title, p.description, br.branch_key AS branchKey, p.service_id AS serviceId,
               p.discount_type AS discountType, p.discount_value AS discountValue,
               DATE_FORMAT(p.start_date, '%Y-%m-%d') AS startDate,
               DATE_FORMAT(p.end_date, '%Y-%m-%d') AS endDate,
               p.is_active AS active
        FROM promotions p
        LEFT JOIN branches br ON br.id = p.branch_id
        ORDER BY p.id
    ");
    $promotions = array_map(function ($row) {
        $value = (float) $row['discountValue'];
        $discount = $row['discountType'] === 'Percentage'
            ? rtrim(rtrim(number_format($value, 1), '0'), '.') . '% OFF'
            : 'PHP ' . number_format($value, 0) . ' OFF';
        return [
            'id' => (string) $row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'discount' => $discount,
            'discountType' => $row['discountType'],
            'discountValue' => $value,
            'branchId' => $row['branchKey'],
            'branchIds' => [$row['branchKey']],
            'serviceId' => $row['serviceId'] !== null ? (string) $row['serviceId'] : '',
            'startDate' => $row['startDate'],
            'endDate' => $row['endDate'],
            'active' => (bool) $row['active'],
        ];
    }, $stmt->fetchAll());

    $stmt = $pdo->query("
        SELECT id, package_name AS packageName, price, reservation_fee AS reservationFee,
               features, style, display_order AS displayOrder, is_active AS active,
               travel_minutes, setup_minutes, service_minutes, {$wpReturnSql}, durations_confirmed
        FROM wedding_packages
        ORDER BY display_order, id
    ");
    // Blank stays null so the admin can tell "not set" (120-minute fallback) from 0.
    $durationFields = fn($row) => [
        'travelMinutes' => $row['travel_minutes'] !== null ? (int) $row['travel_minutes'] : null,
        'setupMinutes' => $row['setup_minutes'] !== null ? (int) $row['setup_minutes'] : null,
        'serviceMinutes' => $row['service_minutes'] !== null ? (int) $row['service_minutes'] : null,
        // Blank = same as travel (Scheduling::homeServiceDurations).
        'returnTravelMinutes' => $row['return_travel_minutes'] !== null ? (int) $row['return_travel_minutes'] : null,
        'durationsConfirmed' => (bool) $row['durations_confirmed'],
    ];
    $weddingPackages = array_map(function ($row) use ($durationFields) {
        return [
            'id' => (string) $row['id'],
            'packageName' => $row['packageName'],
            'price' => (float) $row['price'],
            'reservationFee' => $row['reservationFee'] !== null ? (float) $row['reservationFee'] : null,
            'features' => $row['features'],
            'style' => $row['style'],
            'displayOrder' => (int) $row['displayOrder'],
            'active' => (bool) $row['active'],
        ] + $durationFields($row);
    }, $stmt->fetchAll());

    $stmt = $pdo->query("
        SELECT event_type, travel_minutes, setup_minutes, service_minutes, {$edReturnSql}, durations_confirmed
        FROM event_type_durations ORDER BY display_order, event_type
    ");
    $eventDurations = array_map(fn($row) => ['eventType' => $row['event_type']] + $durationFields($row), $stmt->fetchAll());

    $stmt = $pdo->query("
        SELECT f.id, br.branch_key AS branchId,
               COALESCE(ap.reference_code, hs.reference_code) AS bookingReference,
               COALESCE(
                   (SELECT GROUP_CONCAT(DISTINCT sv.service_name ORDER BY sv.id SEPARATOR ', ')
                       FROM appointment_services aps JOIN services sv ON sv.id = aps.service_id
                       WHERE aps.appointment_id = ap.id),
                   hs.event_type
               ) AS serviceName,
               CONCAT(c.first_name, ' ', c.last_name) AS clientName,
               COALESCE(ap.employee_id, hs.employee_id) AS staffId, f.rating, f.comments AS comment,
               f.admin_status AS adminStatus,
               DATE_FORMAT(f.created_at, '%Y-%m-%d') AS date
        FROM feedback f
        LEFT JOIN appointments ap ON ap.id = f.appointment_id
        LEFT JOIN home_service_requests hs ON hs.id = f.home_service_request_id
        JOIN customers c ON c.id = f.customer_id
        LEFT JOIN branches br ON br.id = ap.branch_id
        ORDER BY f.created_at DESC
    ");
    $feedback = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['staffId'] = $row['staffId'] !== null ? (string) $row['staffId'] : null;
        $row['rating'] = (int) $row['rating'];
        $row['branchId'] = $row['branchId'] ?: null;
        return $row;
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare("
        SELECT n.id, n.type, n.message, DATE_FORMAT(n.created_at, '%Y-%m-%d %h:%i %p') AS time,
            DATE_FORMAT(n.created_at, '%Y-%m-%dT%H:%i:%s+08:00') AS createdAt,
            EXISTS(SELECT 1 FROM admin_notification_reads r WHERE r.user_id = ? AND r.notification_id = n.id) AS isRead
        FROM notifications n
        WHERE n.user_id IS NULL OR n.user_id = ?
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 30
    ");
    $stmt->execute([$userId, $userId]);
    $notifications = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['isRead'] = (bool) $row['isRead'];
        return $row;
    }, $stmt->fetchAll());

    $stmt = $pdo->query("
        SELECT
            c.id, CONCAT(c.first_name, ' ', c.last_name) AS name, c.phone_number AS phone,
            COALESCE(c.email, (SELECT u2.email FROM users u2 WHERE u2.id = c.user_id)) AS email,
            c.user_id IS NOT NULL AS isRegistered,
            c.loyalty_points AS loyaltyPoints,
            (SELECT COUNT(*) FROM loyalty_stamps ls WHERE ls.customer_id = c.id) AS loyaltyStamps,
            (SELECT COUNT(*) FROM loyalty_rewards lr WHERE lr.customer_id = c.id AND lr.redeemed_at IS NULL) AS availableRewards,
            GROUP_CONCAT(DISTINCT br.branch_key) AS branchKeys,
            SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) AS visits,
            COUNT(a.id) AS bookings,
            SUM(CASE WHEN " . LoyaltyTier::VISIT_CONDITION . " THEN 1 ELSE 0 END) AS recentVisits,
            COALESCE(SUM(CASE WHEN a.status = 'Completed' THEN a.total_price ELSE 0 END), 0) AS totalSpent,
            MAX(a.appointment_datetime) AS lastVisitRaw
        FROM customers c
        JOIN appointments a ON a.customer_id = c.id
        LEFT JOIN branches br ON br.id = a.branch_id
        GROUP BY c.id
        ORDER BY lastVisitRaw DESC
    ");
    $customerDirectory = array_map(function ($row) {
        return [
            'id' => (string) $row['id'],
            'name' => $row['name'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            // A guest checkout (submitGuestBooking.php) creates a `customers`
            // row with no linked `users` account -- user_id IS NULL is the
            // real, structural signal for "Guest" vs "Registered", not a
            // guess or a label applied to everyone alike.
            'accountType' => $row['isRegistered'] ? 'Registered' : 'Guest',
            'branchIds' => $row['branchKeys'] ? explode(',', $row['branchKeys']) : [],
            // Completed visits (the same bookings Total spending counts);
            // bookings = every booking, including pending and cancelled.
            'visits' => (int) $row['visits'],
            'bookings' => (int) $row['bookings'],
            'loyaltyPoints' => (int) $row['loyaltyPoints'],
            'loyaltyStamps' => (int) $row['loyaltyStamps'] % 10,
            'availableRewards' => (int) $row['availableRewards'],
            // Frequency-based status (backend/shared/config/helpers/LoyaltyTier.php).
            'loyaltyTier' => LoyaltyTier::fromVisits((int) $row['recentVisits'])['tier'],
            'recentVisits' => (int) $row['recentVisits'],
            'totalSpent' => (float) $row['totalSpent'],
            'lastVisit' => $row['lastVisitRaw'] ? date('Y-m-d', strtotime($row['lastVisitRaw'])) : null,
        ];
    }, $stmt->fetchAll());

    $stmt = $pdo->prepare('
        SELECT display_name, email, notify_booking, notify_inventory, notify_order, notify_marketing,
               notify_payment, notify_home_service, notify_staff_conflict
        FROM users WHERE id = ? LIMIT 1
    ');
    $stmt->execute([$userId]);
    $me = $stmt->fetch() ?: [];
    $adminProfile = [
        'name' => $me['display_name'] ?? $_SESSION['user_role'],
        'email' => $me['email'] ?? null,
        'role' => 'Platform Administrator',
    ];
    $notificationPrefs = [
        'bookingAlerts' => (bool) ($me['notify_booking'] ?? false),
        'inventoryAlerts' => (bool) ($me['notify_inventory'] ?? false),
        'orderAlerts' => (bool) ($me['notify_order'] ?? false),
        'marketingAlerts' => (bool) ($me['notify_marketing'] ?? false),
        'paymentAlerts' => (bool) ($me['notify_payment'] ?? false),
        'homeServiceAlerts' => (bool) ($me['notify_home_service'] ?? false),
        'staffConflictAlerts' => (bool) ($me['notify_staff_conflict'] ?? false),
    ];

    $stmt = $pdo->query("
        SELECT br.branch_key, sv.id, sv.service_name AS name, sv.price, sv.category
        FROM services sv
        JOIN branches br ON br.id = sv.branch_id
        WHERE sv.is_active = 1
        ORDER BY br.branch_key, sv.id
    ");
    $servicesByBranch = [];
    foreach ($stmt->fetchAll() as $row) {
        $branchKey = $row['branch_key'];
        $servicesByBranch[$branchKey][] = [
            'id' => (string) $row['id'],
            'name' => $row['name'],
            'price' => (float) $row['price'],
            'group' => serviceDisplayGroup((string) $row['category'], (string) $row['name']),
        ];
    }

    $stmt = $pdo->query("
        SELECT sv.id, br.branch_key AS branchId, sv.service_name AS name, sv.description,
               sv.category, sv.duration_minutes AS durationMinutes, sv.duration_label AS durationLabel,
               sv.price, sv.loyalty_multiplier AS loyaltyMultiplier, sv.loyalty_treatment_eligible, sv.payment_requirement AS paymentRequirement,
               sv.is_active AS active,
               (SELECT COUNT(*) FROM appointment_services aps2 WHERE aps2.service_id = sv.id) AS bookingCount
        FROM services sv
        LEFT JOIN branches br ON br.id = sv.branch_id
        ORDER BY br.branch_key, sv.service_name
    ");
    $services = array_map(function ($row) {
        return [
            'id' => (string) $row['id'],
            'branchId' => $row['branchId'],
            'name' => $row['name'],
            'description' => $row['description'],
            'category' => $row['category'],
            // Simple group (Hair Services, Nails & Spa...), same as the guest page.
            'group' => serviceDisplayGroup((string) $row['category'], (string) $row['name']),
            'durationMinutes' => (int) $row['durationMinutes'],
            'durationLabel' => $row['durationLabel'],
            'price' => (float) $row['price'],
            'loyaltyMultiplier' => (float) $row['loyaltyMultiplier'],
            'loyaltyTreatmentEligible' => (bool) $row['loyalty_treatment_eligible'],
            // 'Half Payment' is the legacy DB value for the same rule as the
            // spec's '50% Down Payment' label -- normalized here so the
            // frontend only ever sees the three canonical rule names.
            'paymentRequirement' => $row['paymentRequirement'] === 'Half Payment' ? '50% Down Payment' : $row['paymentRequirement'],
            'active' => (bool) $row['active'],
            'bookingCount' => (int) $row['bookingCount'],
        ];
    }, $stmt->fetchAll());

    echo json_encode([
        'success' => true,
        'branches' => $branches,
        'collectedRevenue' => (float) array_sum($revenueTotals),
        'bookings' => $bookings,
        'bookingPagination' => $selection['pagination'] ?? null,
        'staffList' => $staffList,
        'cashierList' => $cashierList,
        'inventory' => $inventory,
        'promotions' => $promotions,
        'weddingPackages' => $weddingPackages,
        'eventDurations' => $eventDurations,
        'homeServiceDefaults' => [
            'fallbackServiceMinutes' => Scheduling::HOME_SERVICE_DURATION_MINUTES,
            'cleaningBufferMinutes' => Scheduling::cleaningBufferMinutes(),
        ],
        'feedback' => $feedback,
        'notifications' => $notifications,
        'notificationPrefs' => $notificationPrefs,
        'customerDirectory' => $customerDirectory,
        'adminProfile' => $adminProfile,
        'servicesByBranch' => $servicesByBranch,
        'services' => $services,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('admin getDashboardData error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading the dashboard.']);
}
