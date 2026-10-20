<?php
// All data changes below affect temporary tables only; no provider calls or real emails.
$cases=['report','report_endpoint','payment_log_endpoint','customer_recent','tier','cashier_reward','admin_reward','cashier_duplicate','admin_duplicate','unpaid','cancelled','redeem_valid','redeem_insufficient'];
if(!isset($argv[1])){foreach($cases as $case){passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.$case,$exit);if($exit)exit($exit);}exit;}
$case=$argv[1];
require __DIR__.'/../backend/config/database.php';
require __DIR__.'/../backend/config/LoyaltyTier.php';
require __DIR__.'/../backend/config/ReservationPayment.php';
require __DIR__.'/../backend/config/RevenueReport.php';
require __DIR__.'/../backend/config/EmailOutbox.php';
$hook=new ReflectionProperty(EmailOutbox::class,'hookRegistered');$hook->setAccessible(true);$hook->setValue(null,true);
$p=Database::getInstance();
foreach(['branches','users','customers','employees','services','staff_services','appointments','appointment_services','home_service_requests','home_service_staff','home_service_payments','payments','payment_items','payment_transactions','inventory','inventory_adjustments','daily_closures','notifications','email_outbox','audit_log','stylist_waitlist'] as $table){
 $ddl=$p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s','',$ddl);$p->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
function lrCheck($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$p->exec("INSERT INTO branches (id,branch_key,branch_name,opening_time,closing_time) VALUES (1,'daraga','Test Daraga','08:00','20:00')");
$p->exec("INSERT INTO users (id,role_id,email,password) VALUES (99,1,'test@example.com','test')");
$p->exec("INSERT INTO customers (id,user_id,first_name,last_name,phone_number,loyalty_points,notify_email) VALUES (1,99,'Test','Customer','09123456789',100,0)");
$p->exec("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number) VALUES (1,1,1,'Staff','Test','09000000000')");
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price,loyalty_multiplier) VALUES (1,1,'Service',60,1000,2)");
$p->exec('INSERT INTO staff_services (employee_id,service_id) VALUES (1,1)');
$p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,employee_id,appointment_datetime,total_price,status,payment_status,deposit_paid,deposit_amount,deposit_method,deposit_recorded_by,deposit_recorded_at) VALUES
 (1,'TEST-LOYALTY',1,1,1,'2030-01-01 13:00',900,'In Progress','Down Payment Verified',1,450,'Cash',99,'2026-01-01 10:00')");
$p->exec('INSERT INTO appointment_services (appointment_id,service_id,employee_id) VALUES (1,1,1)');
if($case==='customer_recent'){
 $p->exec("UPDATE appointments SET created_at='2025-01-01' WHERE id=1");
 $p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,appointment_datetime,total_price,status,created_at) VALUES (2,'NEWER-REQUEST',1,1,'2029-01-01',1000,'Confirmed','2026-01-01')");
 $source=file_get_contents(__DIR__.'/../backend/customer/getDashboardData.php');
 preg_match('/\$stmt = \$pdo->prepare\("(.*?)"\);/s',$source,$match);
 $stmt=$p->prepare($match[1]);$stmt->execute([1]);$rows=$stmt->fetchAll();
 lrCheck($rows[0]['id']==='NEWER-REQUEST'&&$rows[1]['id']==='TEST-LOYALTY','customer recent bookings sort by submission, not appointment date');exit;
}
if(str_starts_with($case,'report') || $case==='payment_log_endpoint') {
 $p->exec("UPDATE appointments SET total_price=1000,deposit_amount=400,status='Completed',payment_status='Fully Paid',appointment_datetime='2030-01-01' WHERE id=1");
 $p->exec("INSERT INTO payments (appointment_id,amount,payment_method,status,payment_date) VALUES (1,600,'Cash','Paid','2026-01-03 12:00')");
 $p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,employee_id,appointment_datetime,total_price,status,payment_status,deposit_paid,deposit_amount,deposit_recorded_by,deposit_recorded_at) VALUES
 (2,'TEST-DEPOSIT',1,1,1,'2031-01-01',1000,'Pending','Down Payment Verified',1,300,99,'2026-01-02'),
 (3,'TEST-UNVERIFIED',1,1,1,'2031-01-01',1000,'Pending','Awaiting Verification',1,200,NULL,NULL),
 (4,'TEST-REFUND',1,1,1,'2031-01-01',1000,'Cancelled','Refunded',1,150,99,'2026-01-02'),
 (5,'TEST-LEGACY',1,1,1,'2031-01-01',1000,'Confirmed','Down Payment Verified',1,75,99,NULL)");
 $p->exec("INSERT INTO payment_transactions (reference_code,appointment_id,event,external_id,amount,status,created_at) VALUES
 ('TEST-REFUND',4,'refunded','rf_test',150,'Refunded','2026-01-04'),('TEST-REFUND',4,'refunded','rf_test',150,'Refunded','2026-01-04')");
 $p->exec("INSERT INTO home_service_requests (id,reference_code,customer_id,branch_id,address,event_type,preferred_date,status) VALUES
 (1,'HOME-OFFSITE',1,NULL,'Venue','Wedding','2030-01-01','Cancelled'),(2,'HOME-BRANCH',1,1,'Venue','Wedding','2030-01-01','Confirmed')");
 $p->exec("INSERT INTO home_service_payments (home_service_request_id,kind,amount,method,reference,recorded_by,created_at) VALUES
 (1,'deposit',500,'Cash',NULL,99,'2026-01-03'),(1,'balance',500,'Cash',NULL,99,'2026-01-03'),
 (2,'balance',600,'PayMongo','pay_repeat',NULL,'2026-01-03'),(2,'balance',600,'PayMongo','pay_repeat',NULL,'2026-01-03')");
 if($case==='payment_log_endpoint') {
  $p->exec("INSERT INTO payment_transactions (reference_code,event,amount,status,created_at) VALUES ('HOME-BRANCH','payment_paid',600,'paid','2026-01-03')");
  for($i=0;$i<301;$i++)$p->exec("INSERT INTO payment_transactions (event,created_at) VALUES ('api_error','2026-01-05')");
  $_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/backend/admin/getPaymentLog.php';require __DIR__.'/../backend/config/session.php';$_SESSION=['user_id'=>99,'user_role'=>'Admin'];
  $_GET=['from'=>'2026-01-03','to'=>'2026-01-03','branch'=>'home-service'];
  ob_start();register_shutdown_function(function(){ $body=json_decode(ob_get_clean(),true);lrCheck(($body['success']??false)&&count($body['events'])===1&&$body['events'][0]['reference']==='HOME-BRANCH','payment log applies date/branch before row limit');});chdir(__DIR__.'/../backend/admin');require 'getPaymentLog.php';exit;
 }
 if($case==='report_endpoint'){
  $_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/backend/admin/getRevenueReport.php';require __DIR__.'/../backend/config/session.php';$_SESSION=['user_id'=>99,'user_role'=>'Admin'];
  $_GET=['from'=>'2026-01-03','to'=>'2026-01-03','branch'=>'daraga'];
  ob_start();register_shutdown_function(function(){ $body=json_decode(ob_get_clean(),true);lrCheck(($body['success']??false)&&count($body['collections'])===1&&(float)array_sum(array_column($body['collections'],'amount'))===600.0,'authenticated filtered revenue endpoint');});require __DIR__.'/../backend/admin/getRevenueReport.php';exit;
 }
 $all=RevenueReport::rows($p,[]);lrCheck(array_sum(array_column($all,'amount'))===2975.0,'actual collections include deposits/balances, exclude unpaid, deduct refund once');
 $totals=RevenueReport::totals($p);lrCheck((float)$totals['daraga']===1375.0&&(float)$totals['home-service']===1600.0,'dashboard branch and off-site totals');
 $filtered=RevenueReport::rows($p,['from'=>'2026-01-03','to'=>'2026-01-03','branch'=>'daraga']);lrCheck(count($filtered)===1&&array_sum(array_column($filtered,'amount'))===600.0,'collection date and branch filters');
 lrCheck(array_sum(array_column(RevenueReport::rows($p,['from'=>'2026-01-04','to'=>'2026-01-04']),'amount'))===-150.0,'refund belongs to refund date');
 lrCheck(array_sum(array_column(RevenueReport::rows($p,['from'=>'2026-01-01','to'=>'2026-01-01']),'amount'))===400.0,'deposit belongs to collection date before service date');
 foreach([['from'=>'2026-02-30'],['from'=>'2026-02-01','to'=>'2026-01-01']] as $filters){$rejected=false;try{RevenueReport::rows($p,$filters);}catch(InvalidArgumentException $e){$rejected=true;}lrCheck($rejected,'invalid report date rejected');}exit;
}
if($case==='tier') {
 foreach([0=>'Member',2=>'Member',3=>'Silver',6=>'Gold',10=>'VIP'] as $visits=>$tier)lrCheck(LoyaltyTier::fromVisits($visits)['tier']===$tier,'tier threshold '.$visits);
 $p->exec("UPDATE appointments SET status='Completed',appointment_datetime=NOW()-INTERVAL 1 DAY WHERE id=1");
 $p->exec("INSERT INTO appointments (reference_code,customer_id,branch_id,appointment_datetime,total_price,status) VALUES ('FUTURE-VISIT',1,1,NOW()+INTERVAL 1 MONTH,100,'Completed'),('OLD-VISIT',1,1,NOW()-INTERVAL 2 YEAR,100,'Reviewed')");
 lrCheck(LoyaltyTier::forCustomer($p,1)['visits']===1,'future/expired visits do not inflate tier');
 lrCheck(LoyaltyTier::earnedPoints(900,[['price'=>1000,'loyaltyMultiplier'=>2]])===90,'existing reward rate and service weighting');exit;
}
$endpoint='payment.php';$portal='cashier';$expected=true;
$_POST=['reference'=>'TEST-LOYALTY','paymentMethod'=>'Cash','cashReceived'=>'600','tip'=>'100','products'=>'[]'];
if(str_starts_with($case,'admin')){$portal='admin';$endpoint='completeCheckout.php';$_POST=['id'=>'TEST-LOYALTY','paymentMethod'=>'Cash'];}
if(str_ends_with($case,'duplicate')){$p->exec("INSERT INTO payments (appointment_id,amount,payment_method,status) VALUES (1,450,'Cash','Paid')");$p->exec("UPDATE appointments SET status='Completed',payment_status='Fully Paid' WHERE id=1");$expected=false;}
if($case==='cancelled'){$p->exec("UPDATE appointments SET status='Cancelled' WHERE id=1");$expected=false;}
if($case==='unpaid'){$endpoint='updateStatus.php';$_POST=['id'=>'TEST-LOYALTY','status'=>'Completed'];}
if(str_starts_with($case,'redeem')){
 $portal='customer';$endpoint='submitBooking.php';
 $quote=ReservationPayment::quote([['id'=>1,'service_name'=>'Service','price'=>1000,'payment_requirement'=>'Half Payment']],100,true);
 $_POST=['branch'=>'daraga','serviceIds'=>['1'],'date'=>'2030-02-01','time'=>'10:00','staffId'=>'1','customerName'=>'Test Customer','customerPhone'=>'09123456789','useLoyaltyPoints'=>'1','quoteToken'=>$quote['quoteToken'],'paymentMethod'=>'Cash','agreedToTerms'=>'1'];
 $p->exec("ALTER TABLE appointments ALTER reference_code SET DEFAULT 'TEST-REDEEM'");
 if($case==='redeem_insufficient'){$p->exec('UPDATE customers SET loyalty_points=10 WHERE id=1');$expected=false;}
}
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['SCRIPT_NAME']='/backend/'.$portal.'/'.$endpoint;require __DIR__.'/../backend/config/session.php';$_SESSION=['user_id'=>99,'user_role'=>$portal==='admin'?'Admin':($portal==='customer'?'Customer':'Cashier'),'branch_id'=>1];
register_shutdown_function(function()use($p,$case,$expected){$body=json_decode((string)ob_get_contents(),true);if($p->inTransaction())$p->rollBack();$points=(int)$p->query('SELECT loyalty_points FROM customers WHERE id=1')->fetchColumn();
 $ok=($body['success']??null)===$expected;
 if(in_array($case,['cashier_reward','admin_reward']))$ok=$ok&&$points===190&&(float)$p->query('SELECT amount FROM payments WHERE appointment_id=1')->fetchColumn()===($case==='cashier_reward'?550.0:450.0);
 elseif($case==='redeem_valid')$ok=$ok&&$points===0&&(int)$p->query("SELECT loyalty_points_used FROM appointments WHERE id != 1")->fetchColumn()===100;
 elseif($case==='redeem_insufficient')$ok=$ok&&$points===10&&($body['quoteChanged']??false)&&$p->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===1;
 else $ok=$ok&&$points===100;
 if(!$ok)fwrite(STDERR,json_encode($body).' points='.$points."\n");ob_clean();lrCheck($ok,'endpoint '.$case);
});
chdir(__DIR__.'/../backend/'.$portal);require $endpoint;
