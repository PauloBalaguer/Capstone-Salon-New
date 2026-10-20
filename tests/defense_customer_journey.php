<?php
/**
 * Defense journey: run ONLY against a disposable leo_defense_test_* database.
 * Executes real endpoints in separate CLI processes; never calls payment/email providers.
 * Database reference function/triggers must be installed in that disposable schema.
 */
require __DIR__.'/../backend/config/database.php';
$p=Database::getInstance();
if (!preg_match('/^leo_defense_test_[a-f0-9]{12}$/D',(string)$p->query('SELECT DATABASE()')->fetchColumn())) {
    fwrite(STDERR,"Refusing to run outside an isolated defense test database.\n");exit(1);
}
if (($argv[1]??'')==='endpoint') {
    $job=json_decode(base64_decode($argv[2]),true);
    if (!in_array($job['endpoint'],['customer/submitBooking.php','public/submitGuestBooking.php','cashier/updateStatus.php','cashier/payment.php','cashier/receipt.php'],true))exit(1);
    $_SERVER['REQUEST_METHOD']=str_ends_with($job['endpoint'],'receipt.php')?'GET':'POST';
    $_SERVER['SCRIPT_NAME']='/backend/'.$job['endpoint'];$_SERVER['REMOTE_ADDR']='127.0.0.1';
    require __DIR__.'/../backend/config/session.php';
    $_SESSION=['user_id'=>$job['user'],'user_role'=>$job['role'],'branch_id'=>1];
    require __DIR__.'/../backend/config/EmailOutbox.php';
    $hook=new ReflectionProperty(EmailOutbox::class,'hookRegistered');$hook->setAccessible(true);$hook->setValue(null,true);
    $p->exec("SET timestamp=UNIX_TIMESTAMP('2030-01-01 13:00:00')");
    $_POST=$job['data'];$_GET=$job['data'];
    if(isset($job['gate'])) { $deadline=microtime(true)+10;while(!is_file($job['gate'])) {if(microtime(true)>$deadline)exit(1);usleep(10000);} }
    chdir(__DIR__.'/../backend/'.dirname($job['endpoint']));require basename($job['endpoint']);exit;
}
function journeyCheck($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function startJourneyRequest(array $job):array {
    $pipes=[];
    $proc=proc_open([PHP_BINARY,__FILE__,'endpoint',base64_encode(json_encode($job))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Could not start endpoint process');
    fclose($pipes[0]);return [$proc,$pipes];
}
function finishJourneyRequest(array $request):array {
    [$proc,$pipes]=$request;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$body=json_decode($out,true);
    if($exit!==0 || !is_array($body) || $err!=='')throw new RuntimeException('Endpoint failed: '.$out.' '.$err);
    return $body;
}
function journeyRequest(array $job):array { return finishJourneyRequest(startJourneyRequest($job)); }
function parallelJourney(array $a,array $b):array {
    $gate=tempnam(sys_get_temp_dir(),'leo-gate-');unlink($gate);$a['gate']=$gate;$b['gate']=$gate;
    $first=startJourneyRequest($a);$second=startJourneyRequest($b);file_put_contents($gate,'go');
    try{return [finishJourneyRequest($first),finishJourneyRequest($second)];}finally{unlink($gate);}
}
function bookingJob(int $user,int $staff,string $date='2030-01-01',string $time='13:00'):array {
    return ['endpoint'=>'customer/submitBooking.php','user'=>$user,'role'=>'Customer','data'=>[
        'branch'=>'daraga','serviceIds'=>['1'],'date'=>$date,'time'=>$time,'staffId'=>(string)$staff,
        'customerName'=>'Defense Customer','customerPhone'=>$user===11?'09123456781':'09123456782',
        'paymentMethod'=>'Cash','agreedToTerms'=>'1']];
}
$p->exec('SET FOREIGN_KEY_CHECKS=0');
$p->exec("INSERT INTO users (id,role_id,email,password) VALUES (11,1,'one@example.invalid','test'),(12,1,'two@example.invalid','test'),(99,1,'cashier@example.invalid','test')");
$p->exec("INSERT INTO customers (id,user_id,first_name,last_name,phone_number,email,notify_email,notify_sms,loyalty_points) VALUES (1,11,'Defense','One','09123456781','one@example.invalid',1,0,0),(2,12,'Defense','Two','09123456782','two@example.invalid',1,0,0)");
$p->exec('UPDATE branches SET slot_limit=2 WHERE id=1');
$p->exec("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number,is_active) VALUES (2,2,1,'Other','Stylist','09123456783',1),(3,3,1,'Unqualified','Staff','09123456784',1)");
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price,payment_requirement,loyalty_multiplier,is_active) VALUES (1,1,'Hair Styling',60,1000,'Half Payment',1,1)");
$p->exec('INSERT INTO staff_services (employee_id,service_id) VALUES (1,1),(2,1)');
$p->exec('SET FOREIGN_KEY_CHECKS=1');
$results=parallelJourney(bookingJob(11,1),bookingJob(12,1));
journeyCheck(count(array_filter($results,fn($r)=>($r['success']??false)))===1,'two simultaneous same-staff submissions produce exactly one booking');
$rejected=array_values(array_filter($results,fn($r)=>!($r['success']??false)))[0];
journeyCheck($rejected['message']==='Selected time slot is unavailable. Please choose another schedule.','losing submission receives clear unavailable-slot message');
journeyCheck((int)$p->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===1,'no duplicate appointment persisted');
$appointment=$p->query('SELECT * FROM appointments LIMIT 1')->fetch();
$reference=$appointment['reference_code'];
journeyCheck((bool)preg_match('/^LM-DAR-[0-9]{4}-[0-9]{6,}$/D',$reference),'actual database trigger generates booking reference');
$other=parallelJourney(bookingJob(11,1,'2030-01-02'),bookingJob(12,2,'2030-01-02'));
journeyCheck(($other[0]['success']??false)&&($other[1]['success']??false),'two simultaneous different-staff bookings both succeed within capacity');
$buffer=journeyRequest(bookingJob(11,1,'2030-01-01','14:29'));
journeyCheck(!($buffer['success']??false),'real booking endpoint rejects cleaning-buffer overlap');
$boundary=journeyRequest(bookingJob(11,1,'2030-01-01','14:30'));
journeyCheck($boundary['success']??false,'real booking endpoint accepts exact 30-minute buffer boundary');
$unqualified=journeyRequest(bookingJob(12,3,'2030-01-01','16:00'));
journeyCheck(!($unqualified['success']??false),'real booking endpoint rejects unqualified staff');
$guest=['endpoint'=>'public/submitGuestBooking.php','user'=>0,'role'=>'','data'=>[
 'fullname'=>'Guest Defense','contact'=>'09123456785','branch'=>'1','services'=>['1'],
 'appointment_date'=>'2030-01-01','time_slot'=>'13:00','staff_id'=>'2','payment_method'=>'Cash','agreedToTerms'=>'1']];
$guestResponse=journeyRequest($guest);
journeyCheck($guestResponse['success']??false,'guest booking succeeds alongside another available staff member');
$guestRetry=journeyRequest($guest);
journeyCheck(!($guestRetry['success']??false),'repeated guest submission cannot duplicate occupied slot');
$statusJob=['endpoint'=>'cashier/updateStatus.php','user'=>99,'role'=>'Cashier','data'=>['id'=>$reference,'paymentAction'=>'verify','depositAmount'=>'500','depositMethod'=>'Cash']];
journeyCheck(journeyRequest($statusJob)['success']??false,'cashier verifies reservation payment');
$beforeRepeat=(int)$p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();
journeyCheck(journeyRequest($statusJob)['success']??false,'repeat verification is idempotent');
journeyCheck((int)$p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$beforeRepeat,'repeat verification does not duplicate queued notifications');
$statusJob['data']=['id'=>$reference,'status'=>'Confirmed'];journeyCheck(journeyRequest($statusJob)['success']??false,'verified booking confirmed');
$statusJob['data']=['id'=>$reference,'status'=>'In Progress','arrivalConfirmed'=>'1'];journeyCheck(journeyRequest($statusJob)['success']??false,'confirmed customer service starts after arrival validation');
$statusJob['data']=['id'=>$reference,'status'=>'Completed'];journeyCheck(journeyRequest($statusJob)['success']??false,'service completes');
$load=$p->prepare('SELECT * FROM appointments WHERE reference_code=?');$load->execute([$reference]);$after=$load->fetch();
journeyCheck($after['payment_status']==='Down Payment Verified','completed service retains outstanding balance');
$checkout=['endpoint'=>'cashier/payment.php','user'=>99,'role'=>'Cashier','data'=>['reference'=>$reference,'paymentMethod'=>'Cash','cashReceived'=>'500','products'=>'[]']];
journeyCheck(journeyRequest($checkout)['success']??false,'cashier collects remaining balance and checks out');
$receipt=journeyRequest(['endpoint'=>'cashier/receipt.php','user'=>99,'role'=>'Cashier','data'=>['reference'=>$reference]]);
journeyCheck(($receipt['success']??false)&&$receipt['receipt']['subtotal']==1000&&$receipt['receipt']['total']==500,'receipt shows booked service total and checkout balance');
journeyCheck(! (journeyRequest($checkout)['success']??false),'duplicate checkout rejected');
$load->execute([$reference]);$after=$load->fetch();
journeyCheck($after['status']==='Completed'&&$after['payment_status']==='Fully Paid'&&$after['reference_code']===$reference,'final booking/payment statuses and reference preserved');
$points=$p->prepare('SELECT loyalty_points FROM customers WHERE id=?');$points->execute([$appointment['customer_id']]);
journeyCheck((int)$points->fetchColumn()===50,'completed paid visit earns loyalty points exactly once');
require __DIR__.'/../backend/config/RevenueReport.php';
$rows=array_values(array_filter(RevenueReport::rows($p,[]),fn($r)=>$r['reference']===$reference));
journeyCheck(array_sum(array_column($rows,'amount'))==1000,'report counts verified deposit plus checkout balance once');
journeyCheck((int)$p->query("SELECT COUNT(*) FROM email_outbox WHERE status='Sent'")->fetchColumn()===0,'workflow queues emails without sending production messages');
