<?php
// Connection-local tables only. No production bookings or provider requests.
$cases=['logic','parallel','assign_busy','assign_free','assign_team','assign_unqualified','assign_inactive','confirm_busy','confirm_free','confirm_unpaid','confirm_repeat','quote_busy','quote_free','record_before_approval','record_after_approval','record_repeat','cancel_event','reschedule_busy','reschedule_free','customer_full','guest_full','customer_free','guest_free','gateway_paid','gateway_busy','gateway_repeated','gateway_failed','gateway_mismatch','gateway_cancelled'];
if (!isset($argv[1])) {
    foreach($cases as $case) {passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.$case,$exit);if($exit)exit($exit);}exit;
}
$case=$argv[1];
require __DIR__.'/../backend/config/database.php';
require __DIR__.'/../backend/config/HomeServiceRequest.php';
$p=Database::getInstance();
if($case==='parallel') {
    $other=new PDO('mysql:host=127.0.0.1;dbname=new_capstone_salon','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $p->beginTransaction();$p->query('SELECT id FROM employees WHERE is_active=1 ORDER BY id LIMIT 1 FOR UPDATE')->fetchColumn();
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');$other->beginTransaction();$blocked=false;
    try{HomeServiceRequest::availability($other,['event_type'=>'Wedding','preferred_date'=>'2030-01-01','preferred_time'=>'10:00']);}
    catch(PDOException $e){$blocked=($e->errorInfo[1]===1205);}
    finally{$other->rollBack();$p->rollBack();}
    if(!$blocked)throw new RuntimeException('FAIL parallel event capacity mutex');echo "PASS parallel event capacity mutex (two connections, rollback only)\n";exit;
}
// Never let integration fixtures trigger real SMTP at process shutdown.
$hook=new ReflectionProperty(EmailOutbox::class,'hookRegistered');$hook->setAccessible(true);$hook->setValue(null,true);
foreach(['branches','users','customers','employees','services','staff_services','appointments','appointment_services','home_service_requests','home_service_staff','home_service_payments','notifications','email_outbox','payment_transactions','audit_log','rate_limit'] as $table) {
    $ddl=$p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s','',$ddl);
    $p->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$p->exec("INSERT INTO branches (id,branch_key,branch_name) VALUES (1,'daraga','Test')");
$p->exec("INSERT INTO users (id,role_id,email,password) VALUES (99,1,'test@example.com','test')");
$p->exec("INSERT INTO customers (id,user_id,first_name,last_name,phone_number,notify_email) VALUES (1,99,'Test','Customer','09123456789',0)");
for($i=1;$i<=6;$i++) $p->prepare("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number,is_active) VALUES (?,?,1,?, 'Test',?,?)")->execute([$i,$i,"Staff$i","1000$i",$i===4?0:1]);
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price) VALUES (1,1,'Hair & Make-up Wedding',60,1000),(2,1,'Lash Extensions',60,500),(3,1,'Hair Setting',60,500),(4,1,'Event Makeup',60,500)");
$p->exec('INSERT INTO staff_services (employee_id,service_id) VALUES (1,1),(2,1),(3,2),(4,1),(5,3),(6,4)');
$p->exec("INSERT INTO home_service_requests (id,reference_code,customer_id,employee_id,address,event_type,wedding_package,preferred_date,preferred_time,status,quote_price,deposit_amount) VALUES
    (1,'EVENT-A',1,NULL,'Test Address','Wedding','D','2030-01-01','10:00','Pending Review',12000,2000),
    (2,'EVENT-B',1,1,'Test Address','Wedding','D','2030-01-01','10:00','Confirmed',12000,2000)");
$p->exec('INSERT INTO home_service_staff (home_service_request_id,employee_id) VALUES (2,1)');
function eventCheck($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$request=fn()=>$p->query('SELECT * FROM home_service_requests WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if(str_starts_with($case,'gateway_')) {
    $source=file_get_contents(__DIR__.'/../backend/config/PayMongo.php');
    $start=strpos($source,'    private static function request(');$end=strpos($source,'    /** Creates a hosted checkout',$start);
    $source=substr_replace($source,'    private static function request(string $method,string $path,?array $payload=null):array {return $GLOBALS["providerFixture"];}'."\n",$start,$end-$start);
    $source=str_replace('__DIR__',var_export(realpath(__DIR__.'/../backend/config'),true),$source);eval(substr($source,5));
    $property=new ReflectionProperty(PayMongo::class,'credentials');$property->setAccessible(true);$property->setValue(null,['secret_key'=>'sk_test_mock','webhook_secret'=>'test_secret']);
    $p->exec("UPDATE home_service_requests SET paymongo_checkout_id='cs_event',status='Payment Required',employee_id=".($case==='gateway_busy'?1:2).' WHERE id=1');
    if($case==='gateway_cancelled')$p->exec("UPDATE home_service_requests SET status='Cancelled' WHERE id=1");
    $GLOBALS['providerFixture']=['data'=>['id'=>'cs_event','attributes'=>['payments'=>[['id'=>'pay_event','attributes'=>['status'=>$case==='gateway_failed'?'failed':'paid','currency'=>'PHP','amount'=>$case==='gateway_mismatch'?1:200000]]]]]];
    $initial=$request();$paid=false;try{$paid=PayMongo::syncHomeService($p,$initial);}catch(RuntimeException $e){if($case!=='gateway_mismatch')throw $e;}
    $expected=!in_array($case,['gateway_failed','gateway_mismatch'],true);
    if($case==='gateway_repeated')eventCheck(PayMongo::syncHomeService($p,$initial),'repeated event callback returns paid');
    $after=$request();eventCheck($paid===$expected && (bool)$after['deposit_paid']===$expected,'event callback '.$case);
    eventCheck($p->query('SELECT COUNT(*) FROM home_service_payments')->fetchColumn()===($expected?1:0),'event payment written once');
    if($expected)eventCheck($after['status']===($case==='gateway_busy'?'Payment Being Verified':($case==='gateway_cancelled'?'Cancelled':'Confirmed')),'event payment / schedule status consistency');
    exit;
}
if($case==='logic') {
    eventCheck(!HomeServiceRequest::availability($p,$request(),[1])['available'],'staff busy with another wedding');
    eventCheck(HomeServiceRequest::availability($p,$request(),[2])['available'],'two weddings, different available staff');
    eventCheck(HomeServiceRequest::availability($p,$request(),[5,6])['available'],'team collectively covers hair and makeup');
    eventCheck(!HomeServiceRequest::availability($p,$request(),[5])['available'],'incomplete service coverage');
    eventCheck(!HomeServiceRequest::availability($p,$request(),[3])['available'],'unqualified staff');
    eventCheck(!HomeServiceRequest::availability($p,$request(),[4])['available'],'inactive staff');
    eventCheck(Scheduling::staffHasConflict($p,1,'2030-01-01 11:00',30),'salon booking sees event conflict');
    eventCheck(Scheduling::staffHasConflict($p,1,'2030-01-01 12:15',30),'event cleaning buffer');
    eventCheck(!Scheduling::staffHasConflict($p,1,'2030-01-01 12:30',30),'event buffer boundary');
    eventCheck(Scheduling::homeServiceStaffHasConflict($p,1,'2030-01-01','07:45',1),'buffer before existing event');
    $p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,employee_id,appointment_datetime,total_price,status) VALUES (1,'SALON',1,1,2,'2030-01-01 11:00',500,'Confirmed')");
    $p->exec('INSERT INTO appointment_services (appointment_id,service_id,employee_id) VALUES (1,1,2)');
    eventCheck(!HomeServiceRequest::availability($p,$request(),[2])['available'],'event sees salon conflict');
    $p->exec("UPDATE appointments SET status='Cancelled'");
    $p->exec("UPDATE home_service_requests SET status='Cancelled' WHERE id=2");
    eventCheck(HomeServiceRequest::availability($p,$request(),[1])['available'],'cancelled event releases staff');
    $p->exec("UPDATE home_service_requests SET status='Pending Review',employee_id=NULL WHERE id=2");
    $p->exec('DELETE FROM home_service_staff');
    eventCheck(HomeServiceRequest::availability($p,$request(),[1])['available'],'pending unassigned wedding does not block date');
    $p->exec('UPDATE employees SET is_active=0 WHERE id IN (2,3,4,5,6)');
    $p->exec("UPDATE home_service_requests SET status='Confirmed' WHERE id=2");
    eventCheck(!HomeServiceRequest::availability($p,$request())['available'],'confirmed unassigned event consumes capacity');
    $p->exec('UPDATE employees SET is_active=1 WHERE id=2');
    eventCheck(HomeServiceRequest::availability($p,$request())['available'],'sufficient capacity for separate teams');
    $p->exec('UPDATE employees SET is_active=0 WHERE id=2');
    $later=array_replace($request(),['preferred_time'=>'15:00']);
    eventCheck(HomeServiceRequest::availability($p,$later)['available'],'fully booked morning does not block entire date');
    exit;
}
if(str_starts_with($case,'reschedule')) {
    $p->exec('UPDATE home_service_requests SET employee_id=2,deposit_paid=1,payment_status="Down Payment Verified" WHERE id=1');
    $p->exec('INSERT INTO home_service_staff (home_service_request_id,employee_id) VALUES (1,2)');
    $p->exec('UPDATE home_service_requests SET employee_id=2 WHERE id=2');
    $p->exec('UPDATE home_service_staff SET employee_id=2 WHERE home_service_request_id=2');
    $p->beginTransaction();$before=$request();$ok=true;
    try{HomeServiceRequest::reschedule($p,$before,'2030-01-01',$case==='reschedule_busy'?'11:00':'15:00');$p->commit();}catch(RuntimeException $e){$p->rollBack();$ok=false;}
    eventCheck($ok===($case==='reschedule_free'),'reschedule conflict / available schedule');
    eventCheck($request()['payment_status']===$before['payment_status'] && $request()['deposit_paid']==1,'reschedule preserves verified payment');exit;
}
$endpoint='assignStaff.php';$portal='admin';$expected=true;
$_POST=['id'=>'EVENT-A','staffIds'=>['2']];
if($case==='assign_busy'){$_POST['staffIds']=['1'];$expected=false;}
if($case==='assign_team')$_POST['staffIds']=['5','6'];
if($case==='assign_unqualified'){$_POST['staffIds']=['3'];$expected=false;}
if($case==='assign_inactive'){$_POST['staffIds']=['4'];$expected=false;}
if(str_starts_with($case,'confirm')) {
    $endpoint='updateBookingStatus.php';$_POST=['id'=>'EVENT-A','status'=>'Confirmed'];
    $p->exec("UPDATE home_service_requests SET status='Payment Being Verified',employee_id=".($case==='confirm_busy'?1:2).",deposit_paid=1,payment_status='Awaiting Verification',deposit_method='Cash' WHERE id=1");
    $expected=$case!=='confirm_busy'&&$case!=='confirm_unpaid';
    if($case==='confirm_unpaid')$p->exec('UPDATE home_service_requests SET deposit_paid=0 WHERE id=1');
    if($case==='confirm_repeat') {
        $p->exec("UPDATE home_service_requests SET payment_status='Fully Paid',deposit_recorded_by=99 WHERE id=1");
        $p->exec("INSERT INTO home_service_payments (home_service_request_id,kind,amount,method) VALUES (1,'deposit',2000,'Cash')");
    }
}
if(str_starts_with($case,'quote_')) {
    $endpoint='setHomeServiceQuote.php';$_POST=['id'=>'EVENT-A','action'=>'quote','quotePrice'=>'12000','reservationFee'=>$case==='quote_busy'?'2000':'0'];
    if($case==='quote_busy'){$p->exec('UPDATE home_service_requests SET employee_id=1 WHERE id=1');$expected=false;}
}
if(str_starts_with($case,'record_')) {
    $endpoint='setHomeServiceQuote.php';$_POST=['id'=>'EVENT-A','action'=>'recordPayment','depositAmount'=>'2000','depositMethod'=>'Cash'];
    $expected=$case==='record_after_approval';
    if($case!=='record_before_approval')$p->exec("UPDATE home_service_requests SET status='".($expected?'Payment Required':'Payment Being Verified')."',deposit_paid=".($expected?0:1).' WHERE id=1');
}
if($case==='cancel_event'){$endpoint='updateBookingStatus.php';$_POST=['id'=>'EVENT-A','status'=>'Cancelled'];$p->exec("UPDATE home_service_requests SET deposit_paid=1,payment_status='Fully Paid',employee_id=2 WHERE id=1");}
if(str_starts_with($case,'customer_')||str_starts_with($case,'guest_')) {
    $expected=str_ends_with($case,'free');
    if(!$expected)$p->exec('UPDATE employees SET is_active=0 WHERE id!=1');
    $p->exec("ALTER TABLE home_service_requests ALTER reference_code SET DEFAULT 'TEST-EVENT-NEW'");
    require __DIR__.'/../backend/config/Address.php';
    $prov=Address::formLocations('provinces')[0]['code'];$city=Address::formLocations('cities',$prov)[0]['code'];$bar=Address::formLocations('barangays',$city)[0]['code'];
    $_POST=['structuredAddress'=>json_encode(['province'=>$prov,'city'=>$city,'barangay'=>$bar,'street'=>'Test Street']), 'eventType'=>'Wedding','weddingPackage'=>'D','clients'=>'5','agreedToTerms'=>'1'];
    if(str_starts_with($case,'customer_')) { $portal='customer';$endpoint='submitHomeServiceRequest.php';$_POST+=['preferredDate'=>'2030-01-01','preferredTime'=>'10:00']; }
    else { $portal='public';$endpoint='submitGuestHomeService.php';$_POST+=['date'=>'2030-01-01','time'=>'10:00','fullname'=>'Guest Customer','contact'=>'09999999999','email'=>'guest@example.com']; }
}
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['SCRIPT_NAME']='/backend/'.$portal.'/'.$endpoint;
require __DIR__.'/../backend/config/session.php';$_SESSION=['user_id'=>99,'user_role'=>$portal==='admin'?'Admin':'Customer'];
$before=$request();
register_shutdown_function(function()use($p,$case,$expected,$request,$before){
    $body=json_decode((string)ob_get_contents(),true);if($p->inTransaction())$p->rollBack();
    $ok=($body['success']??null)===$expected;
    if(!$expected)$ok=$ok&&$request()===$before;
    if($case==='assign_team')$ok=$ok&&$p->query('SELECT COUNT(*) FROM home_service_staff WHERE home_service_request_id=1')->fetchColumn()==2;
    if($case==='assign_free')$ok=$ok&&$request()['status']==='Pending Review';
    if($case==='confirm_free')$ok=$ok&&$request()['status']==='Confirmed'&&$p->query('SELECT COUNT(*) FROM home_service_payments WHERE home_service_request_id=1')->fetchColumn()==1;
    if($case==='confirm_repeat')$ok=$ok&&$request()['payment_status']==='Fully Paid'&&$p->query('SELECT COUNT(*) FROM home_service_payments WHERE home_service_request_id=1')->fetchColumn()==1;
    if($case==='record_after_approval')$ok=$ok&&$request()['status']==='Payment Being Verified'&&$request()['payment_status']==='Awaiting Verification';
    if($case==='quote_free')$ok=$ok&&$request()['status']==='Quote Ready';
    if($case==='cancel_event')$ok=$ok&&$request()['status']==='Cancelled'&&$request()['payment_status']==='Fully Paid';
    if(in_array($case,['customer_free','guest_free'])) {
        $new=$p->query("SELECT * FROM home_service_requests WHERE reference_code='TEST-EVENT-NEW'")->fetch();
        $ok=$ok&&($body['reference']??'')==='TEST-EVENT-NEW'&&$new['status']==='Pending Review'&&$new['deposit_paid']==0;
    }
    if(!$ok)fwrite(STDERR,json_encode($body)."\n");
    ob_clean();eventCheck($ok,'endpoint '.$case);
});
chdir(__DIR__.'/../backend/'.$portal);
if($case==='guest_free') {
    // Temporary tables cannot run the production reference trigger. Substitute
    // its fixture value only; exercise the real guest insertion and reference read.
    $source=file_get_contents($endpoint);
    $source=str_replace('$referenceCode = \'\';', '$referenceCode = \'TEST-EVENT-NEW\';', $source);
    $source=str_replace('__DIR__',var_export(getcwd(),true),$source);
    eval(substr($source,5));
} else require $endpoint;
