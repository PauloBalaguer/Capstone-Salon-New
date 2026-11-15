<?php
require __DIR__ . '/support/harness.php';
$pdo=clone_database('capstone_retry_hold_test');
$p=test_people($pdo);
$stmt=$pdo->prepare('SELECT id FROM employees WHERE branch_id=? AND is_active=1 LIMIT 1');$stmt->execute([1]);$staff=$stmt->fetchColumn();
$token=bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO appointments (customer_id,employee_id,branch_id,appointment_datetime,status,payment_status,
    preferred_payment_method,reservation_amount_due,online_payment_token,created_at)
    VALUES (?,?,1,NOW()+INTERVAL 5 DAY,'Pending','Payment Required','PayMongo-GCash',250,?,NOW()-INTERVAL 29 MINUTE)")
    ->execute([$p['customerA'],$staff,$token]);
$id=$pdo->lastInsertId();
$stmt=$pdo->prepare('SELECT reference_code FROM appointments WHERE id=?');$stmt->execute([$id]);$ref=$stmt->fetchColumn();
// No gateway attempt exists yet, so sync needs no network request.
$get=fn($key)=>run_endpoint(['script'=>'backend/public/paymongoStatus.php','method'=>'GET','paymongo'=>'http://127.0.0.1:8766', 'get'=>['ref'=>$ref,'token'=>$key]]);
$r=$get($token);
check('within the existing 30-minute hold: unpaid booking offers retry methods', ($r['success']??false) && $r['status']==='Pending' && $r['holdMinutes']===30 && count($r['methods'])===3);
$pdo->prepare('UPDATE appointments SET created_at=NOW()-INTERVAL 31 MINUTE WHERE id=?')->execute([$id]);
$r=$get(str_repeat('0',32));
check('wrong token cannot inspect or release the booking', !($r['success']??true) && row($pdo,'appointments',(int)$id)['status']==='Pending');
$r=$get($token);
check('result-page refresh releases an unpaid expired hold and removes retry', ($r['success']??false) && $r['status']==='Cancelled' && $r['holdSecondsRemaining']===0 && $r['methods']===[]);
$get($token);
$stmt=$pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE appointment_id=? AND event='checkout_expired'");$stmt->execute([$id]);
check('expiry is logged once even on repeated checks', (int)$stmt->fetchColumn()===1);
finish();
