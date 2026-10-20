<?php
// Isolated SQL fixtures and mocked provider responses only; no real payments.
$cases=['quote','gateway_paid','gateway_failed','gateway_cancelled','gateway_mismatch','gateway_repeated','checkout_deposit','checkout_full','checkout_duplicate','checkout_unverified','checkout_online_pending','checkout_receipt','verify_paymongo_forged','verify_paymongo_received','verify_repeat','confirm_no_deposit','service_completed_unpaid','confirm_after_checkout'];
if (!isset($argv[1])) {
 foreach($cases as $case) { passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$exit); if($exit) exit($exit); } exit();
}
$case=$argv[1];
require __DIR__.'/../backend/config/database.php';
require __DIR__.'/../backend/config/ReservationPayment.php';
function check($value,$label) { if(!$value) throw new Exception('FAIL '.$label); }
if($case==='quote') {
 $s=[['id'=>1,'service_name'=>'Test','price'=>1000,'payment_requirement'=>'Half Payment']];
 check(ReservationPayment::quote($s)['amountDue']===500,'configured deposit');
 check(ReservationPayment::quote($s,0,false,'full')['amountDue']===1000,'full payment');
 $s[0]['payment_requirement']='No Online Reservation'; check(ReservationPayment::quote($s)['amountDue']===0,'no online reservation');
 check((float)array_sum(ReservationPayment::receiptServicePrices([['price'=>200],['price'=>300]],450))===450.0,'discounted receipt allocation');
 echo 'PASS quote / full / no-online / receipt allocation'.PHP_EOL; exit();
}
$p=Database::getInstance();
foreach(['branches','appointments','appointment_services','services','staff_services','employees','customers','users','notifications','audit_log','home_service_requests','home_service_payments','daily_closures','stylist_waitlist','payments','payment_items','payment_transactions','inventory','inventory_adjustments','feedback'] as $table) {
 $ddl=$p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
 $ddl=preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s','',$ddl);
 $p->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$p->exec("INSERT INTO branches (id,branch_key,branch_name,opening_time,closing_time) VALUES (1,'daraga','Test','08:00','20:00')");
$p->exec("INSERT INTO customers (id,first_name,last_name,phone_number,notify_email) VALUES (1,'Test','Customer','09000000000',0)");
$p->exec("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number,is_active) VALUES (1,1,1,'One','Test','1',1)");
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price) VALUES (1,1,'Service',60,1000)");
$p->exec('INSERT INTO staff_services (employee_id,service_id) VALUES (1,1)');
$p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,employee_id,appointment_datetime,total_price,status,payment_status,deposit_paid,deposit_amount,deposit_method,deposit_recorded_by,reservation_amount_due) VALUES (1,'TEST-PAY',1,1,1,'2030-01-01 13:00',900,'In Progress','Down Payment Verified',1,450,'Cash',99,450)");
$p->exec('INSERT INTO appointment_services (id,appointment_id,service_id,employee_id) VALUES (1,1,1,1)');
if(str_starts_with($case,'gateway_')) {
 // Replace only the HTTP transport in the evaluated test copy of PayMongo.
 $source=file_get_contents(__DIR__.'/../backend/config/PayMongo.php');
 $start=strpos($source,'    private static function request('); $end=strpos($source,'    /** Creates a hosted checkout',$start);
 $source=substr_replace($source,'    private static function request(string $method, string $path, ?array $payload = null): array { return $GLOBALS["providerFixture"]; }'."\n\n",$start,$end-$start);
 $source=str_replace('__DIR__',var_export(realpath(__DIR__.'/../backend/config'),true),$source);
 eval(substr($source,5));
 $property=new ReflectionProperty(PayMongo::class,'credentials'); $property->setAccessible(true); $property->setValue(null,['secret_key'=>'sk_test_mock','webhook_secret'=>'test_secret']);
 $p->exec("UPDATE appointments SET status='Pending',payment_status='Payment Required',deposit_paid=0,deposit_amount=NULL,deposit_method=NULL,deposit_recorded_by=NULL,paymongo_checkout_id='cs_test' WHERE id=1");
 if($case==='gateway_cancelled') $p->exec("UPDATE appointments SET status='Cancelled' WHERE id=1");
 $status=$case==='gateway_failed'?'failed':'paid';
 $GLOBALS['providerFixture']=['data'=>['id'=>'cs_test','attributes'=>['payments'=>[['id'=>'pay_test','attributes'=>['status'=>$status,'amount'=>$case==='gateway_mismatch'?1:45000,'currency'=>'PHP']]]]]];
 $a=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
 try { $paid=PayMongo::syncAppointment($p,$a); } catch(RuntimeException $e) { $paid=false; }
 if($case==='gateway_repeated') check(PayMongo::syncAppointment($p,$a),'repeated sync returns paid');
 $after=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
 $expected=!in_array($case,['gateway_failed','gateway_mismatch']);
 check($paid===$expected && (bool)$after['deposit_paid']===$expected,$case);
 check((int)$p->query("SELECT COUNT(*) FROM payment_transactions WHERE event='payment_paid'")->fetchColumn()===($expected?1:0),'one paid log');
 if($case==='gateway_cancelled') check($after['status']==='Cancelled' && $after['payment_status']==='Refund Due','cancelled payment does not restore booking');
 $raw=json_encode(['data'=>['attributes'=>['livemode'=>false]]]); $signature=hash_hmac('sha256','123.'.$raw,'test_secret');
 check(PayMongo::verifyWebhookSignature($raw,'t=123,te='.$signature),'test signature');
 check(!PayMongo::verifyWebhookSignature($raw,'t=123,li='.$signature),'wrong-mode signature rejected');
 echo 'PASS '.$case.' (mock provider)'.PHP_EOL; exit();
}
$endpoint='payment.php'; $expected=true;
$_POST=['reference'=>'TEST-PAY','paymentMethod'=>'Cash','cashReceived'=>'450','products'=>'[]'];
if($case==='checkout_full') { $p->exec("UPDATE appointments SET deposit_amount=900,payment_status='Fully Paid' WHERE id=1"); $_POST['cashReceived']='0'; }
if($case==='checkout_duplicate') { $p->exec("INSERT INTO payments (appointment_id,amount,payment_method,status) VALUES (1,450,'Cash','Paid')"); $expected=false; }
if($case==='checkout_unverified') { $p->exec("UPDATE appointments SET deposit_recorded_by=NULL,payment_status='Awaiting Verification' WHERE id=1"); $expected=false; }
if($case==='checkout_online_pending') { $p->exec("UPDATE appointments SET deposit_paid=0,deposit_recorded_by=NULL,paymongo_checkout_id='cs_pending' WHERE id=1"); $expected=false; }
if(str_starts_with($case,'verify_') || $case==='confirm_no_deposit' || $case==='service_completed_unpaid' || $case==='confirm_after_checkout') {
 $endpoint='updateStatus.php';
 $_POST=['id'=>'TEST-PAY','paymentAction'=>'verify','depositAmount'=>'450','depositMethod'=>'PayMongo'];
 if($case==='verify_paymongo_forged') { $p->exec("UPDATE appointments SET deposit_recorded_by=NULL,deposit_method=NULL,deposit_reference=NULL,payment_status='Payment Required',deposit_paid=0 WHERE id=1"); $expected=false; }
 if($case==='verify_paymongo_received') $p->exec("UPDATE appointments SET deposit_recorded_by=NULL,deposit_method='PayMongo',deposit_reference='pay_test',paymongo_checkout_id='cs_test',payment_status='Awaiting Verification' WHERE id=1");
 if($case==='verify_repeat') $_POST['depositAmount']='100';
 if($case==='confirm_no_deposit') { $p->exec("UPDATE appointments SET status='Pending',payment_status='Payment Required',deposit_paid=0,deposit_amount=NULL,deposit_recorded_by=NULL,reservation_amount_due=0 WHERE id=1"); $_POST=['id'=>'TEST-PAY','status'=>'Confirmed']; }
 if($case==='service_completed_unpaid') $_POST=['id'=>'TEST-PAY','status'=>'Completed'];
 if($case==='confirm_after_checkout') {
  $p->exec("INSERT INTO payments (appointment_id,amount,payment_method,status) VALUES (1,450,'Cash','Paid')");
  $p->exec("UPDATE appointments SET status='Completed',payment_status='Fully Paid' WHERE id=1");
  $_POST=['id'=>'TEST-PAY','status'=>'Confirmed','depositAmount'=>'450','depositMethod'=>'Cash']; $expected=false;
 }
}
$before=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
$_SERVER['SCRIPT_NAME']='/backend/cashier/'.$endpoint; $_SERVER['REQUEST_METHOD']='POST';
require __DIR__.'/../backend/config/session.php'; $_SESSION=['user_id'=>99,'user_role'=>'Cashier','branch_id'=>1];
register_shutdown_function(function() use($p,$case,$before,$expected,$endpoint) {
 $body=json_decode((string)ob_get_contents(),true);
 if($p->inTransaction()) $p->rollBack();
 try {
 check(isset($body['success']) && $body['success']===$expected,$case.' response '.json_encode($body));
 $after=$p->query('SELECT * FROM appointments WHERE id=1')->fetch();
 if(!$expected) check($after===$before,'rejected request preserves booking');
 if($endpoint==='payment.php' && $expected) {
  check($after['status']==='Completed' && $after['payment_status']==='Fully Paid','checkout statuses');
  check((int)$p->query('SELECT loyalty_points FROM customers WHERE id=1')->fetchColumn()===45,'loyalty awarded once');
  check((float)$p->query('SELECT SUM(line_total) FROM payment_items')->fetchColumn()===900.0,'receipt lines use booked total');
  $_GET=['reference'=>'TEST-PAY']; ob_start(); require __DIR__.'/../backend/cashier/receipt.php'; $receipt=json_decode(ob_get_clean(),true);
  check($receipt['success'] && $receipt['receipt']['subtotal']===900 && $receipt['receipt']['total']===($case==='checkout_full'?0:450),'receipt totals');
  // Execute the actual dashboard report SQL against these isolated fixtures.
  preg_match_all('~\$pdo->query\("(.*?)"\);~s',file_get_contents(__DIR__.'/../backend/admin/getDashboardData.php'),$queries);
  $branches=$p->query($queries[1][0])->fetchAll();
  $bookings=$p->query(str_replace('$appointmentWhere','',$queries[1][1]))->fetchAll();
  require_once __DIR__.'/../backend/config/RevenueReport.php';
  $collected=RevenueReport::totals($p);
  check((float)$collected['daraga']===900.0 && (float)$bookings[0]['checkoutRevenue']===900.0 && (int)$bookings[0]['checkoutPaid']===1,'completed checkout and deposit reflected once in collection report');
 }
 if($case==='verify_repeat') check($after['deposit_amount']===$before['deposit_amount'] && $after['deposit_method']===$before['deposit_method'],'repeat verification preserves payment');
 if($case==='service_completed_unpaid') check($after['status']==='Completed' && $after['payment_status']==='Down Payment Verified','service complete does not settle payment');
 if($case==='confirm_no_deposit') check($after['status']==='Confirmed' && $after['payment_status']==='Payment Required','no-online rule does not invent a payment');
 fwrite(STDERR,'PASS '.$case.PHP_EOL);
 } catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
});
chdir(__DIR__.'/../backend/cashier'); require $endpoint;
