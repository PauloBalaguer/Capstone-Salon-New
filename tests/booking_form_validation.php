<?php
if (isset($argv[1])) {
    [$script,$portal,$field] = $argv;
    $_SERVER['SCRIPT_NAME']='/backend/'.$portal.'/'.($portal==='customer'?'submitBooking.php':'submitGuestBooking.php');
    $_SERVER['REQUEST_METHOD']='POST';
    require __DIR__.'/../backend/config/session.php';
    $_SESSION=['user_id'=>99,'user_role'=>'Customer'];
    $_POST=$portal==='customer'
        ? ['branch'=>'test','serviceIds'=>['1'],'date'=>'2030-01-01','time'=>'13:00','staffId'=>'1','customerName'=>'Mary Jane','customerPhone'=>'09123456789']
        : ['branch'=>'1','services'=>['1'],'appointment_date'=>'2030-01-01','time_slot'=>'13:00','staff_id'=>'1','fullname'=>'Mary Jane','contact'=>'09123456789','email'=>''];
    $_POST[$field]=match($field) {
        'customerName','fullname'=>'123!', 'customerPhone','contact'=>'09abc456789',
        'serviceIds','services'=>['1','1'], 'staffId','staff_id'=>'1.5',
        'date','appointment_date'=>'2030-02-30','email'=>'bad@',default=>'25:00'
    };
    register_shutdown_function(function() use($portal,$field) {
        $body=json_decode((string)ob_get_contents(),true);
        if (http_response_code()!==422 || !isset($body['errors'][$field])) {fwrite(STDERR,"FAIL endpoint $portal $field\n");exit(1);}
        ob_clean(); echo "PASS endpoint $portal rejects $field inline error\n";
    });
    chdir(__DIR__.'/../backend/'.$portal);
    require $portal==='customer'?'submitBooking.php':'submitGuestBooking.php';
    exit;
}
require __DIR__ . '/../backend/config/BookingValidation.php';
$rules = ['name'=>['name',true,'your full name'], 'phone'=>['mobile',true,'a mobile number'],
    'email'=>['email',false,'an email address'], 'services'=>['ids',true,'services'],
    'staff'=>['id',false,'staff'], 'date'=>['date',true,'a date'], 'time'=>['time',true,'a time']];
$valid = ['name'=>"María O’Neil-Santos", 'phone'=>'09123456789', 'email'=>'', 'services'=>['1','2'], 'staff'=>'1', 'date'=>'2030-01-01', 'time'=>'13:00'];
function checkForm($ok, $label) { if (!$ok) {fwrite(STDERR,"FAIL $label\n");exit(1);} echo "PASS $label\n"; }
checkForm(!BookingValidation::errors($valid,$rules),'valid guest details; optional email');
checkForm(!BookingValidation::errors(array_replace($valid,['staff'=>'','email'=>'customer@example.com']),$rules),'automatic staff and valid email');
foreach (['name'=>'123 !','phone'=>'09abc456789','email'=>'bad@','services'=>['1','1'],'staff'=>'1.5','date'=>'2030-02-30','time'=>'25:00'] as $field=>$value) {
 checkForm(isset(BookingValidation::errors(array_replace($valid,[$field=>$value]),$rules)[$field]),"reject invalid $field");
}
foreach (['name','phone','services','date','time'] as $field) checkForm(isset(BookingValidation::errors(array_replace($valid,[$field=>'']),$rules)[$field]),"required $field");
foreach (['0912345678','+639123456789','091234567890','9.123e10'] as $phone) checkForm(isset(BookingValidation::errors(array_replace($valid,['phone'=>$phone]),$rules)['phone']),'reject malformed mobile '.$phone);
foreach (['Mary Jane','李美玲',"D'Angelo",'Prince'] as $name) checkForm(!BookingValidation::errors(array_replace($valid,['name'=>$name]),$rules),'accept name '.$name);
foreach (['customer'=>['customerName','customerPhone','serviceIds','staffId','date','time'],
    'public'=>['fullname','contact','services','staff_id','appointment_date','time_slot','email']] as $portal=>$fields) {
    foreach ($fields as $field) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.$portal.' '.$field,$result);
        if ($result) exit($result);
    }
}
