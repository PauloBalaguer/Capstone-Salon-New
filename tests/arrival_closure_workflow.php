<?php
// Session-local clocks and temporary SQL tables: no application records are changed.
$cases=['on_time','late_review','late_paid','all_staff_busy','start_available','protect_next','protect_next_buffer','start_reassigned','start_without_review','no_show','future_no_show','no_show_in_progress','duplicate_no_show','cancel_verified','duplicate_cancel','customer_duplicate_cancel','customer_cancel_in_progress','customer_cancel_verified','cancel_completed','policy_idempotent','reschedule_free','reschedule_conflict','no_show_releases_staff','cashier_other_branch','admin_review','cashier_review','staff_review','staff_start_blocked','staff_start_available','legacy_checkout_closed'];
if(!isset($argv[1])) { foreach($cases as $case) { passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$exit); if($exit) exit($exit); } exit(); }
$case=$argv[1];
require __DIR__.'/../backend/config/database.php';
require __DIR__.'/../backend/config/CancellationPolicy.php';
function check($ok,$message) { if(!$ok) throw new Exception('FAIL '.$message); }
$p=Database::getInstance();
foreach(['branches','appointments','appointment_services','services','staff_services','employees','customers','users','notifications','audit_log','home_service_requests','daily_closures','stylist_waitlist','payments','payment_transactions'] as $table) {
 $ddl=$p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1]; $ddl=preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s','',$ddl); $p->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$p->exec("SET timestamp = UNIX_TIMESTAMP('2030-01-01 15:05:00')");
$p->exec("INSERT INTO branches (id,branch_key,branch_name,opening_time,closing_time,slot_limit) VALUES (1,'daraga','Test','08:00','20:00',3)");
$p->exec("INSERT INTO customers (id,user_id,first_name,last_name,phone_number,notify_email) VALUES (1,99,'Test','Customer','09000000000',0)");
$p->exec("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number,is_active) VALUES (1,1,1,'One','Test','1',1),(2,2,1,'Two','Test','2',1),(3,3,1,'Unqualified','Test','3',1)");
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price) VALUES (1,1,'Service',60,1000)");
$p->exec('INSERT INTO staff_services (employee_id,service_id) VALUES (1,1),(2,1)');
$p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,employee_id,appointment_datetime,total_price,status,payment_status,deposit_paid,deposit_amount,deposit_method,deposit_recorded_by,reservation_amount_due) VALUES
 (1,'ARRIVAL-A',1,1,1,'2030-01-01 14:30',1000,'Confirmed','Fully Paid',1,1000,'Cash',99,500),
 (2,'RESERVED-B',1,1,1,'2030-01-01 16:00',1000,'Confirmed','Down Payment Verified',1,500,'Cash',99,500)");
$p->exec('INSERT INTO appointment_services (id,appointment_id,service_id,employee_id) VALUES (1,1,1,1),(2,2,1,1)');
if($case==='on_time') $p->exec("SET timestamp = UNIX_TIMESTAMP('2030-01-01 14:30:00')");
if(in_array($case,['start_available','staff_start_available'])) $p->exec("UPDATE appointments SET appointment_datetime='2030-01-01 18:00' WHERE id=2");
if($case==='protect_next_buffer') $p->exec("UPDATE appointments SET appointment_datetime='2030-01-01 16:20' WHERE id=2");
if($case==='all_staff_busy') {
 $p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,employee_id,appointment_datetime,status) VALUES (3,'BUSY-C',1,1,2,'2030-01-01 15:00','Confirmed')");
 $p->exec('INSERT INTO appointment_services (id,appointment_id,service_id,employee_id) VALUES (3,3,1,2)');
}
if(in_array($case,['duplicate_cancel','customer_duplicate_cancel','duplicate_no_show'])) $p->exec("UPDATE appointments SET status='".($case==='duplicate_no_show'?'No-Show':'Cancelled')."',payment_status='Forfeited',cancelled_at=NOW() WHERE id=1");
if(in_array($case,['no_show_in_progress','customer_cancel_in_progress'])) $p->exec("UPDATE appointments SET status='In Progress' WHERE id=1");
if($case==='cancel_completed') $p->exec("UPDATE appointments SET status='Completed' WHERE id=1");
if($case==='legacy_checkout_closed') $p->exec("UPDATE appointments SET status='Cancelled',payment_status='Forfeited' WHERE id=1");
if($case==='future_no_show') $p->exec("UPDATE appointments SET appointment_datetime='2030-01-01 15:05:30' WHERE id=1");
$before=$p->query('SELECT * FROM appointments WHERE id=1')->fetch(); $next=$p->query('SELECT * FROM appointments WHERE id=2')->fetch();
if(in_array($case,['on_time','late_review','late_paid','all_staff_busy','no_show_releases_staff','policy_idempotent','reschedule_free','reschedule_conflict'])) {
 if(str_starts_with($case,'reschedule_')) {
  require __DIR__.'/../backend/config/Reschedule.php'; $a=Reschedule::load($p,'ARRIVAL-A');
  try { Reschedule::apply($p,$a,'2030-01-01',$case==='reschedule_free'?'18:00':'15:30',false,'Arrival review',1); $success=true; } catch(RuntimeException $e) { $success=false; }
  check($success===($case==='reschedule_free'),$case);
 } elseif($case==='policy_idempotent') {
  $p->beginTransaction(); $a=Scheduling::lockAppointment($p,1); $p->exec("UPDATE appointments SET status='Cancelled' WHERE id=1");
  CancellationPolicy::apply($p,$a,CancellationPolicy::BY_SALON); CancellationPolicy::apply($p,$a,CancellationPolicy::BY_SALON); $p->commit();
  check((int)$p->query("SELECT COUNT(*) FROM payment_transactions WHERE event='deposit_forfeited'")->fetchColumn()===1,'policy applies once even with stale input');
 } elseif($case==='no_show_releases_staff') {
  $p->exec("UPDATE appointments SET status='No-Show' WHERE id=1");
  check(!Scheduling::staffHasConflict($p,1,'2030-01-01 14:30',30),'No-Show releases staff');
  $p->exec('UPDATE branches SET slot_limit=1 WHERE id=1');
  check(!Scheduling::branchWindowIsFull($p,1,'2030-01-01 14:30',30),'No-Show releases branch capacity');
 } else {
  $p->beginTransaction(); $a=Scheduling::lockAppointment($p,1); $review=Scheduling::arrivalReview($p,$a); $p->rollBack();
  $staff=array_column($review['staff'],null,'id');
  check($review['graceMinutes']===null,'grace period remains unset');
  check(!$staff['3']['available'],'unqualified staff unavailable');
  if($case==='on_time') check($review['minutesLate']===0 && $staff['1']['available'],'on-time arrival');
  else check($review['minutesLate']===35 && !$staff['1']['available'],'late arrival protects next booking');
  check($staff['2']['available']===($case!=='all_staff_busy'),'alternative staff availability');
 }
 $after=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
 if($case!=='policy_idempotent') check($after['payment_status']===$before['payment_status'] && $after['deposit_amount']===$before['deposit_amount'],'verified payment preserved');
 check($next===$p->query('SELECT * FROM appointments WHERE id=2')->fetch(),'next customer unchanged');
 echo 'PASS '.$case.PHP_EOL; exit();
}
$portal='cashier';$endpoint='updateStatus.php';$expected=true;
$_POST=['id'=>'ARRIVAL-A','status'=>'In Progress','arrivalConfirmed'=>'1','arrivalStaffId'=>$case==='start_reassigned'?'2':'1'];
if(in_array($case,['protect_next','protect_next_buffer','start_without_review','future_no_show','no_show_in_progress','customer_cancel_in_progress','cancel_completed','cashier_other_branch','staff_start_blocked','legacy_checkout_closed'])) $expected=false;
if($case==='start_without_review') unset($_POST['arrivalConfirmed']);
if(in_array($case,['no_show','future_no_show','no_show_in_progress','duplicate_no_show'])) { $portal='admin';$endpoint='updateBookingStatus.php';$_POST=['id'=>'ARRIVAL-A','status'=>'No-Show']; }
if(in_array($case,['cancel_verified','duplicate_cancel','cancel_completed'])) $_POST=['id'=>'ARRIVAL-A','status'=>'Cancelled'];
if(str_starts_with($case,'customer_')) { $portal='customer';$endpoint='cancelAppointment.php';$_POST=['appointment_id'=>'ARRIVAL-A']; }
if($case==='admin_review') { $portal='admin';$endpoint='updateBookingStatus.php'; }
if(str_starts_with($case,'staff_')) { $portal='appointment';$endpoint='update.php'; }
if(in_array($case,['admin_review','cashier_review','staff_review'])) $_POST['arrivalReview']='1';
if($case==='legacy_checkout_closed') { $portal='admin';$endpoint='completeCheckout.php';$_POST=['id'=>'ARRIVAL-A']; }
$_SERVER['SCRIPT_NAME']='/backend/'.$portal.'/'.$endpoint;$_SERVER['REQUEST_METHOD']='POST';
require __DIR__.'/../backend/config/session.php';$_SESSION=['user_id'=>$portal==='appointment'?1:99,'user_role'=>$portal==='admin'?'Admin':($portal==='customer'?'Customer':($portal==='appointment'?'Staff':'Cashier')),'branch_id'=>$case==='cashier_other_branch'?2:1];
register_shutdown_function(function() use($p,$case,$expected,$before,$next) {
 $body=json_decode((string)ob_get_contents(),true);if($p->inTransaction())$p->rollBack();
 try {
 check(isset($body['success']) && $body['success']===$expected,$case.' response '.json_encode($body));
 $after=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
 check($next===$p->query('SELECT * FROM appointments WHERE id=2')->fetch(),'next booking protected');
 if(!$expected) check($after===$before,'rejection changes no booking or payment');
 if(in_array($case,['start_available','start_reassigned','staff_start_available'])) {
  check($after['status']==='In Progress' && $after['appointment_datetime']==='2030-01-01 15:05:00','actual service window stored');
  check($after['payment_status']==='Fully Paid' && (float)$after['deposit_amount']===1000.0,'paid late customer funds preserved');
  check(str_contains($after['notes'],'2030-01-01 14:30:00'),'reserved start preserved in audit notes');
  if($case==='start_reassigned') check((int)$after['employee_id']===2 && (int)$p->query('SELECT employee_id FROM appointment_services WHERE id=1')->fetchColumn()===2,'explicit main service reassigned');
 }
 $logs=(int)$p->query("SELECT COUNT(*) FROM payment_transactions WHERE event='deposit_forfeited'")->fetchColumn();
 if(in_array($case,['no_show','cancel_verified','customer_cancel_verified'])) check($after['payment_status']==='Forfeited' && $logs===1 && $after['deposit_amount']===$before['deposit_amount'],'existing explicit closure policy applied once');
 if(in_array($case,['admin_review','cashier_review','staff_review'])) {
  check($after===$before && $body['review']['minutesLate']===35,'review does not change booking or payment');
  check((int)$p->query("SELECT COUNT(*) FROM audit_log WHERE action='booking/arrival_review'")->fetchColumn()===1,'arrival review audit saved');
  if($case==='staff_review') check(count($body['review']['staff'])===1 && $body['review']['canReschedule']===false,'staff cannot reassign or reschedule');
 }
 if(str_contains($case,'duplicate')) check($logs===0 && $after===$before,'duplicate closure has no financial side effects');
 fwrite(STDERR,'PASS '.$case.PHP_EOL);
 } catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }
});
chdir(__DIR__.'/../backend/'.$portal);require $endpoint;
