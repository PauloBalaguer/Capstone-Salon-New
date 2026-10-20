<?php
require __DIR__.'/../backend/config/database.php';
require __DIR__.'/../backend/config/AdminBookingList.php';
$p=Database::getInstance();
foreach(['appointments','home_service_requests','customers','branches','employees','appointment_services','services','home_service_staff','home_service_payments','payments'] as $table) {
 $ddl=$p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s','',$ddl);$p->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$p->exec("INSERT INTO branches (id,branch_key,branch_name) VALUES (1,'daraga','Daraga'),(2,'yashano','Yashano')");
$p->exec("INSERT INTO customers (id,first_name,last_name,phone_number,email) VALUES (1,'Alice','Example','1','alice@example.invalid'),(2,'Bob %_','Example','2',NULL)");
$p->exec("INSERT INTO services (id,service_name,duration_minutes,price) VALUES (1,'Test Service',60,1000)");
$ins=$p->prepare('INSERT INTO appointments (id,reference_code,customer_id,branch_id,appointment_datetime,created_at,status,payment_status,total_price) VALUES (?,?,?,?,?,?,?,?,1000)');
for($id=1;$id<=25;$id++) {
 $even=$id%2===0;$ins->execute([$id,'BOOK-'.str_pad((string)$id,4,'0',STR_PAD_LEFT),$even?1:2,$even?1:2,sprintf('2030-06-%02d 10:00:00',$id),sprintf('2030-01-01 00:%02d:00',$id),$even?'Confirmed':'Pending',$even?'Fully Paid':'Awaiting Verification']);
 $p->exec("INSERT INTO appointment_services (appointment_id,service_id) VALUES ($id,1)");
}
// Home-service required fields vary by migration; use the actual current schema.
$ins=$p->prepare("INSERT INTO home_service_requests (id,reference_code,customer_id,event_type,address,preferred_date,preferred_time,status,payment_status,submitted_at,wedding_package,requests) VALUES (?,?,1,'Wedding','Test Venue','2030-06-01','11:00','Pending Review',?,?,'A','Long wedding requirements and guest details')");
foreach([1,2,3] as $id) $ins->execute([$id,'HOME-'.$id,'Payment Required','2030-01-0'.($id+1).' 12:00:00']);
$p->exec("UPDATE appointments SET payment_status='Refund Due', appointment_datetime='2099-01-01 10:00:00' WHERE id=1");
function check($value,$name) { if(!$value) throw new Exception('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; }
if(($argv[1]??'')==='endpoint') {
 $_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/backend/admin/getDashboardData.php';
 require __DIR__.'/../backend/config/session.php';$_SESSION=['user_id'=>99,'user_role'=>'Admin'];$_GET=['bookingPage'=>'1','bookingsOnly'=>'1'];
 ob_start();register_shutdown_function(function() {
  $body=json_decode((string)ob_get_contents(),true);ob_clean();
  if(!$body || !$body['success'] || count($body['bookings'])!==10 || $body['bookingPagination']['total']!==28 || $body['bookings'][0]['id']!=='HOME-3' || $body['bookings'][0]['requests']!=='Long wedding requirements and guest details' || $body['bookings'][0]['weddingPackage']!=='A') { fwrite(STDERR,'FAIL paginated API / detail mapping '.json_encode($body).PHP_EOL);exit(1); }
  fwrite(STDERR,'PASS paginated API sorting, metadata and wedding details'.PHP_EOL);
 });
 chdir(__DIR__.'/../backend/admin');require 'getDashboardData.php';exit();
}
$first=AdminBookingList::select($p,[]);check(count($first['salonIds'])+count($first['homeIds'])===10 && $first['pagination']['total']===28 && $first['pagination']['totalPages']===3,'ten rows by default / total pages');
check($first['homeIds']===[3,2,1] || array_map('intval',$first['homeIds'])===[3,2,1],'newest actual submissions across booking types');
check((int)$first['salonIds'][0]===25 && !in_array(1,array_map('intval',$first['salonIds']),true),'future appointment date does not outrank newest submission');
$seen=[];for($page=1;$page<=3;$page++){ $result=AdminBookingList::select($p,['bookingPage'=>$page]);foreach(['salonIds','homeIds'] as $kind)foreach($result[$kind] as $id)$seen[]=$kind.':'.$id; }
check(count($seen)===28 && count(array_unique($seen))===28,'next/previous pages cover every row exactly once');
$result=AdminBookingList::select($p,['search'=>'Alice','branch'=>'daraga','status'=>'Confirmed','paymentStatus'=>'Fully Paid','dateFrom'=>'2030-06-01','dateTo'=>'2030-06-10']);
check($result['pagination']['total']===5,'search and all combined filters');
check(AdminBookingList::select($p,['branch'=>'off-site'])['pagination']['total']===3,'home-service branch filter');
$refund=AdminBookingList::select($p,['status'=>'refunds']); check($refund['pagination']['total']===1,'refund filter preserved');
check(AdminBookingList::select($p,['search'=>'BOOK-0001'])['pagination']['total']===1,'reference search');
check(AdminBookingList::select($p,['search'=>'%_'])['pagination']['total']===13,'literal percent / underscore search');
check(AdminBookingList::select($p,['search'=>"' OR 1=1 --"])['pagination']['total']===0,'parameterized search');
check(AdminBookingList::select($p,['bookingPage'=>999])['pagination']['page']===3,'out-of-range page clamped');
$empty=AdminBookingList::select($p,['search'=>'no match']);check($empty['pagination']['total']===0 && $empty['pagination']['page']===1,'empty results');
foreach([['pageSize'=>101],['bookingPage'=>0],['dateFrom'=>'2030-02-30'],['dateFrom'=>'2030-06-10','dateTo'=>'2030-06-01'],['search'=>[]]] as $invalid) {
 $rejected=false;try { AdminBookingList::select($p,$invalid); }catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'invalid filter rejected');
}
