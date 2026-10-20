<?php
// Every table below is connection-local; application rows are never modified.
$cases=['admin_busy','cashier_busy','admin_buffer','cashier_buffer','admin_free','cashier_free','admin_payment','cashier_payment','admin_unqualified','cashier_unqualified','admin_reassign_busy','cashier_reassign_busy','admin_split','cashier_split','admin_split_busy','cashier_split_busy','move_overlap','move_buffer','move_free','move_split','move_stale'];
if (!isset($argv[1])) {
    foreach ($cases as $case) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$exit);
        if ($exit) exit($exit);
    }
    exit();
}
$case=$argv[1];
require __DIR__.'/../backend/config/database.php';
$p=Database::getInstance();
foreach (['branches','appointments','appointment_services','services','staff_services','employees','customers','users','notifications','audit_log','home_service_requests','daily_closures','stylist_waitlist','payments'] as $table) {
    $ddl=$p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s','',$ddl);
    $p->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$p->exec("INSERT INTO branches (id,branch_key,branch_name,opening_time,closing_time,slot_limit) VALUES (1,'daraga','Test','08:00','20:00',3)");
$p->exec("INSERT INTO customers (id,first_name,last_name,phone_number,notify_email) VALUES (1,'Test','Customer','09000000000',0)");
$p->exec("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number,is_active) VALUES (1,1,1,'One','Test','1',1),(2,2,1,'Two','Test','2',1),(3,3,1,'Three','Test','3',1)");
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price) VALUES (1,1,'One',60,100),(2,1,'Two',60,100)");
$p->exec('INSERT INTO staff_services (employee_id,service_id) VALUES (1,1),(2,1),(2,2)');
$p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,employee_id,appointment_datetime,total_price,status,payment_status,deposit_paid,deposit_amount,deposit_method,reservation_amount_due) VALUES
 (1,'TEST-ONE',1,1,1,'2030-01-01 13:00',100,'Pending','Awaiting Verification',1,50,'Cash',50),
 (2,'TEST-TWO',1,1,1,'2030-01-01 13:30',100,'Confirmed','Fully Paid',1,100,'Cash',50)");
$p->exec('INSERT INTO appointment_services (id,appointment_id,service_id,employee_id) VALUES (1,1,1,NULL),(2,2,1,NULL)');
$free=str_contains($case,'free') || str_contains($case,'payment') || str_contains($case,'split');
if ($free) $p->exec("UPDATE appointments SET appointment_datetime='2030-01-01 10:00' WHERE id=2");
if (str_ends_with($case,'_buffer') && !str_starts_with($case,'move_')) $p->exec("UPDATE appointments SET appointment_datetime='2030-01-01 12:00' WHERE id=2");
if (str_contains($case,'payment')) $p->exec("UPDATE appointments SET payment_status='Fully Paid',deposit_amount=100,deposit_method='PayMongo',deposit_recorded_by=99 WHERE id=1");
if (str_contains($case,'reassign_busy')) $p->exec('UPDATE appointments SET employee_id=2 WHERE id=2');
if (str_contains($case,'split')) {
 $p->exec('UPDATE appointment_services SET employee_id=1 WHERE id=1');
 $p->exec('INSERT INTO appointment_services (id,appointment_id,service_id,employee_id) VALUES (3,1,2,2)');
}
if (str_ends_with($case,'split_busy')) $p->exec("UPDATE appointments SET employee_id=2,appointment_datetime='2030-01-01 14:30' WHERE id=2");
$before=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
$links=$p->query('SELECT * FROM appointment_services WHERE appointment_id=1 ORDER BY id')->fetchAll();
$expected=$free && !str_ends_with($case,'split_busy');
if (str_starts_with($case,'move_')) {
 require __DIR__.'/../backend/config/Reschedule.php';
 $a=Reschedule::load($p,'TEST-ONE');
 $time= $case==='move_buffer' ? '14:45' : ($case==='move_overlap' ? '14:00' : '16:00');
 if ($case==='move_stale') { $p->exec("UPDATE appointments SET status='Completed' WHERE id=1"); $expected=false; }
 try { $result=Reschedule::apply($p,$a,'2030-01-01',$time,false,'',1); $success=true; }
 catch(RuntimeException $e) { $success=false; }
 $after=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
 $ok=$success===$expected && $after['payment_status']===$before['payment_status'] && $after['deposit_amount']===$before['deposit_amount'] && $after['reference_code']===$before['reference_code'];
 $ok=$ok && $links===$p->query('SELECT * FROM appointment_services WHERE appointment_id=1 ORDER BY id')->fetchAll();
 echo ($ok?'PASS ':'FAIL ').$case.($ok?'':' '.json_encode(['success'=>$success,'expected'=>$expected,'error'=>isset($e)?$e->getMessage():null,'before'=>$before,'after'=>$after])).PHP_EOL;
 exit($ok?0:1);
}
$portal=str_starts_with($case,'admin')?'admin':'cashier';
$assign=str_contains($case,'unqualified') || str_contains($case,'reassign_busy') || (str_contains($case,'split') && !str_ends_with($case,'split_busy'));
$endpoint=$assign?'assignStaff.php':($portal==='admin'?'updateBookingStatus.php':'updateStatus.php');
$_SERVER['SCRIPT_NAME']='/backend/'.$portal.'/'.$endpoint;
$_SERVER['REQUEST_METHOD']='POST';
require __DIR__.'/../backend/config/session.php';
$_SESSION=['user_id'=>99,'user_role'=>$portal==='admin'?'Admin':'Cashier','branch_id'=>1];
$_POST=['id'=>'TEST-ONE','status'=>'Confirmed','depositAmount'=>'50','depositMethod'=>'Cash','staffId'=>str_contains($case,'unqualified')?'3':'2'];
register_shutdown_function(function() use($p,$case,$before,$links,$expected) {
 $body=json_decode((string)ob_get_contents(),true);
 // Rejects may exit with an uncommitted transaction; roll it back before verifying.
 if ($p->inTransaction()) $p->rollBack();
 $after=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
 $ok=isset($body['success']) && $body['success']===$expected;
 if (!$expected) $ok=$ok && $before===$after;
 if (str_contains($case,'payment')) $ok=$ok && $after['payment_status']==='Fully Paid' && (float)$after['deposit_amount']===100.0 && $after['deposit_method']==='PayMongo' && (int)$after['deposit_recorded_by']===99;
 $ok=$ok && $links===$p->query('SELECT * FROM appointment_services WHERE appointment_id=1 ORDER BY id')->fetchAll();
 fwrite(STDERR,($ok?'PASS ':'FAIL ').$case.($ok?'':' '.json_encode($body)).PHP_EOL);
 if (!$ok) exit(1);
});
chdir(__DIR__.'/../backend/'.$portal);
require $endpoint;
