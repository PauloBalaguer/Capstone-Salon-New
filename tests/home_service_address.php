<?php
require __DIR__.'/../backend/config/Address.php';
$province = Address::formLocations('provinces')[0]['code'];
$cities = Address::formLocations('cities',$province);
$city = $cities[0]['code'];
$barangay = Address::formLocations('barangays',$city)[0]['code'];
$input = ['province'=>$province,'city'=>$city,'barangay'=>$barangay,'house'=>'12','street'=>'Test Street','subdivision'=>'Test Building','landmark'=>'Blue gate'];
[$valid,$errors] = Address::validate($input,true);
function hsCheck($ok,$label,$quiet=false) { if (!$ok) {fwrite(STDERR,"FAIL $label\n");exit(1);} if(!$quiet)echo "PASS $label\n"; }
if (!isset($argv[1])) {
    hsCheck($valid && !$errors,'saved structured address accepted');
    hsCheck(Address::validate([],false)===[null,[]],'optional missing profile address');
    hsCheck(isset(Address::validate([],true)[1]['province']),'missing service address rejected');
    hsCheck(isset(Address::validate(array_replace($input,['city'=>'999999999']),true)[1]['city']),'invalid city rejected');
    $otherCity=$cities[1]['code'];
    $otherBarangay=Address::formLocations('barangays',$otherCity)[0]['code'];
    hsCheck(isset(Address::validate(array_replace($input,['barangay'=>$otherBarangay]),true)[1]['barangay']),'cross-city barangay rejected');
    hsCheck(isset(Address::validate(array_replace($input,['house'=>'','street'=>'','subdivision'=>'']),true)[1]['street']),'missing detailed address rejected');
    hsCheck(isset(Address::validate(array_replace($input,['street'=>['bad']]),true)[1]['street']),'array address field rejected');
    $count=0;
    foreach ($cities as $c) {
        $rows=Address::formLocations('barangays',$c['code']);
        hsCheck(count($rows)>0,'location data for '.$c['name']);
        foreach ($rows as $b) {
            hsCheck(!Address::validate(array_replace($input,['city'=>$c['code'],'barangay'=>$b['code']]),true)[1],'relationship '.$b['code'],true);
            $count++;
        }
    }
    echo "DATASET: ".count($cities)." cities/municipalities, $count barangays checked\n";
    foreach (['saved','different','no_saved','wedding','invalid','save_profile'] as $case) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.$case,$result); if($result)exit($result);
    }
    exit;
}
$case=$argv[1];
require __DIR__.'/../backend/config/database.php';
require __DIR__.'/../backend/config/EmailOutbox.php';
$hook=new ReflectionProperty(EmailOutbox::class,'hookRegistered');$hook->setAccessible(true);$hook->setValue(null,true);
$p=Database::getInstance();
foreach(['users','customers','branches','employees','services','staff_services','appointments','appointment_services','home_service_requests','home_service_staff','notifications','email_outbox','audit_log'] as $table) {
    $ddl=$p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s','',$ddl);
    $p->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
// Event availability now requires a qualified team. Keep this fixture independent
// of whichever employees and bookings happen to exist in the application DB.
$p->exec("INSERT INTO branches (id,branch_key,branch_name) VALUES (1,'daraga','Test Branch')");
$p->exec("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number,is_active) VALUES (1,1,1,'Test','Stylist','09123456789',1)");
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price,is_active) VALUES (1,1,'Hair Styling',60,1000,1),(2,1,'Event Makeup',60,1000,1)");
$p->exec('INSERT INTO staff_services (employee_id,service_id) VALUES (1,1),(1,2)');
// Temporary tables do not run production reference triggers; use a test-only default.
$p->exec("ALTER TABLE home_service_requests ALTER reference_code SET DEFAULT 'TEST-HS'");
$p->exec("INSERT INTO users (id,role_id,email,password) VALUES (99,1,'test@example.com','test')");
$p->prepare('INSERT INTO customers (id,user_id,first_name,last_name,phone_number,address,structured_address) VALUES (1,99,?,?,?,?,?)')
    ->execute(['Test','Customer','09123456789',$case==='no_saved'?null:$valid['formatted'],$case==='no_saved'?null:json_encode($valid)]);
$before=$p->query('SELECT address,structured_address FROM customers WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if ($case==='different' || $case==='save_profile') $input['street']='Another Street';
if ($case==='invalid') $input['city']='999999999';
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['SCRIPT_NAME']='/backend/customer/'.($case==='save_profile'?'saveProfile.php':'submitHomeServiceRequest.php');
require __DIR__.'/../backend/config/session.php';
$_SESSION=['user_id'=>99,'user_role'=>'Customer'];
$_POST=['structuredAddress'=>json_encode($input),'eventType'=>$case==='wedding'?'Wedding':'Standard Home Service',
    'weddingPackage'=>'D','clients'=>'2','services'=>['Hair Styling'],'preferredDate'=>'2030-01-01','preferredTime'=>'09:00','agreedToTerms'=>'1'];
if ($case==='save_profile') $_POST+=['name'=>'Test Customer','email'=>'test@example.com','phone'=>'09123456789'];
register_shutdown_function(function() use($p,$case,$before) {
    $body=json_decode((string)ob_get_contents(),true);
    $after=$p->query('SELECT address,structured_address FROM customers WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if ($case==='save_profile') {
        $ok=($body['success']??false) && str_contains($after['address'],'Another Street') && ($body['addressProfile']['address']??null)===$after['address'];
    } else {
        $ok=$before===$after;
        $request=$p->query('SELECT * FROM home_service_requests LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($case==='invalid') $ok=$ok && http_response_code()===422 && isset($body['errors']['city']) && !$request;
        else $ok=$ok && ($body['success']??false) && $request['contact_email']==='test@example.com'
            && $request['status']==='Pending Review' && $request['payment_status']==='Payment Required'
            && $request['deposit_paid']==0 && $request['reference_code']==='TEST-HS'
            && str_contains($request['address'],$case==='different'?'Another Street':'Test Street')
            && ($case!=='wedding' || $request['wedding_package']==='D');
    }
    ob_clean(); hsCheck($ok,'isolated endpoint '.$case.'; profile/payment correctness');
});
chdir(__DIR__.'/../backend/customer');
require $case==='save_profile'?'saveProfile.php':'submitHomeServiceRequest.php';
