<?php
require __DIR__ . '/support/harness.php';
require_once __DIR__ . '/../backend/shared/config/helpers/StaffShift.php';
$pdo = clone_database('capstone_walkin_clockin_test');
$p = test_people($pdo);
$staff = $pdo->prepare("SELECT e.id, e.user_id, ss.service_id FROM employees e JOIN users u ON u.id=e.user_id
    JOIN roles r ON r.id=u.role_id JOIN staff_services ss ON ss.employee_id=e.id
    WHERE e.branch_id=? AND e.is_active=1 AND u.is_active=1 AND r.role_name='Staff' LIMIT 1");
$staff->execute([1]);
$staff = $staff->fetch();
$pdo->prepare('UPDATE attendance a JOIN employees e ON e.id=a.employee_id SET a.clock_out_time=NOW() WHERE e.branch_id=? AND a.clock_out_time IS NULL')->execute([1]);
$post = ['clientName'=>'Walkin Test', 'clientPhone'=>'09990007881', 'stylistId'=>$staff['id'], 'serviceIds'=>[$staff['service_id']]];
$r = run_endpoint(['script'=>'backend/cashier/createWalkIn.php','session'=>$p['cashier'],'post'=>$post]);
check('walk-in with nobody clocked in fails with Shift In instructions', !($r['success'] ?? true) && str_contains($r['message'] ?? '', 'tap Shift In'));
$pdo->prepare("INSERT INTO appointments (customer_id,employee_id,branch_id,appointment_datetime,status) VALUES (?,?,?,NOW()-INTERVAL 1 MINUTE,'Confirmed')")
    ->execute([$p['customerA'],$staff['id'],1]);
$stmt=$pdo->prepare('SELECT reference_code FROM appointments WHERE id=?');$stmt->execute([$pdo->lastInsertId()]);$ref=$stmt->fetchColumn();
$r=run_endpoint(['script'=>'backend/cashier/getQualifiedStaffForBooking.php','method'=>'GET','session'=>$p['cashier'],'get'=>['id'=>$ref]]);
check('immediate staff assignment explains nobody is clocked in', ($r['success']??false) && $r['staff']===[] && str_contains($r['message']??'', 'tap Shift In'));
$pdo->prepare("UPDATE branches SET opening_time='00:00:00',closing_time='23:59:59' WHERE id=?")->execute([1]);
$session=['user_id'=>$staff['user_id'],'user_role'=>'Staff','branch_id'=>1,'csrf_token'=>'clockin-test'];
$call=fn($post)=>run_endpoint(['script'=>'backend/staff/shiftAction.php','session'=>$session,'post'=>$post]);
$r=$call(['action'=>'shift_in','csrfToken'=>'wrong']);
check('Shift In rejects an invalid CSRF token', !($r['success']??true));
$r=$call(['action'=>'shift_in','csrfToken'=>'clockin-test']);
check('Staff Portal Shift In creates the active shift', ($r['success']??false) && StaffShift::branchHasClockedInStaff($pdo,1));
$r=$call(['action'=>'shift_in','csrfToken'=>'clockin-test']);
check('double tapping Shift In is refused', !($r['success']??true));
$r=$call(['action'=>'break_start','csrfToken'=>'clockin-test']);
check('on-break staff stay clocked in but cannot take walk-ins', ($r['success']??false) && StaffShift::branchHasClockedInStaff($pdo,1)
    && StaffShift::liveStatus($pdo,(int)$staff['id'])===StaffShift::STATUS_BREAK);
$page=file_get_contents(__DIR__.'/../frontend/staff/dashboard.html');
$css=file_get_contents(__DIR__.'/../frontend/staff/staff-portal.css');
check('mobile Shift In retains viewport and large touch control', str_contains($page,'width=device-width') && str_contains($page,'@click="shiftIn()"') && str_contains($css,'min-height: 54px'));
finish();
