<?php
require __DIR__ . '/support/harness.php';
$pdo=clone_database('capstone_security_roles_test');
$p=test_people($pdo);
$root=dirname(__DIR__);
foreach (['admin','cashier','customer','staff','account'] as $folder) {
    $bad=[];$count=0;
    foreach (glob($root.'/backend/'.$folder.'/*.php') as $file) {
        $session=$folder==='customer' ? $p['cashier'] : $p['sessionA'];
        $r=run_endpoint(['script'=>'backend/'.$folder.'/'.basename($file),'session'=>$session]);
        $count++;
        if (($r['success']??true)!==false || str_starts_with($r['message']??'', 'Not JSON:')) $bad[]=basename($file);
    }
    check("{$folder}: all {$count} endpoints reject a foreign role".($bad?' ('.implode(', ',$bad).')':''), !$bad);
}
$auth=fn($file,$post)=>run_endpoint(['script'=>'backend/auth/'.$file.'.php','post'=>$post]);
$staff=$pdo->prepare("SELECT u.id,u.email,u.password FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_name=? ORDER BY u.id LIMIT 1");
foreach (['Staff','Cashier'] as $role) {
    $staff->execute([$role]); $user=$staff->fetch();
    $pdo->prepare('DELETE FROM password_resets WHERE email=?')->execute([$user['email']]);
    $auth('forgotPassword',['email'=>$user['email']]);
    $q=$pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE email=?');$q->execute([$user['email']]);
    check('managed account cannot obtain a public reset code', (int)$q->fetchColumn()===0);
    $pdo->prepare('INSERT INTO password_resets (email,code,expires_at) VALUES (?, ?, NOW()+INTERVAL 10 MINUTE)')->execute([$user['email'],'123456']);
    $r=$auth('resetPassword',['email'=>$user['email'],'code'=>'123456','new_password'=>'NewPassword!123','confirm_password'=>'NewPassword!123']);
    check('managed account cannot use a previously issued public reset code', !($r['success']??true));
}
$parallel=function(array $spec):array {
    $spec['startAt']=microtime(true)+0.5;
    $a=start_endpoint($spec);$b=start_endpoint($spec);
    return [endpoint_result($a),endpoint_result($b)];
};
$wins=fn($results)=>count(array_filter($results,fn($r)=>$r['success']??false));
$old='PreviousPass!123';$new='NewPassword!123';
foreach (['customer'=>$p['sessionA'],'account'=>$p['admin']] as $folder=>$session) {
    $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($old,PASSWORD_DEFAULT),$session['user_id']]);
    $r=run_endpoint(['script'=>"backend/{$folder}/changePassword.php",'session'=>$session,'post'=>['currentPassword'=>$old,'newPassword'=>$new,'confirmPassword'=>$new]]);
    check("{$folder}: failed email delivery is not reported as sent", !($r['success']??true) && str_contains($r['message']??'', 'could not send'));
    $pdo->prepare('INSERT INTO password_change_verifications (user_id,code,expires_at) VALUES (?,?,NOW()+INTERVAL 10 MINUTE) ON DUPLICATE KEY UPDATE code=VALUES(code),expires_at=VALUES(expires_at)')->execute([$session['user_id'],'123456']);
    $base=['script'=>"backend/{$folder}/confirmPasswordChange.php",'session'=>$session,'post'=>['code'=>'654321','newPassword'=>$new,'confirmPassword'=>$new]];
    $r=run_endpoint($base);check("{$folder}: wrong OTP refused", !($r['success']??true));
    $base['post']['code']='123456';
    check("{$folder}: simultaneous OTP confirmations succeed only once", $wins($parallel($base))===1);
    $q=$pdo->prepare('SELECT password FROM users WHERE id=?');$q->execute([$session['user_id']]);$hash=$q->fetchColumn();
    check("{$folder}: changed password is hashed and verifies", $hash!==$new && password_verify($new,$hash));
}
$q=$pdo->prepare('SELECT email FROM users WHERE id=?');$q->execute([$p['sessionA']['user_id']]);$email=$q->fetchColumn();
$pdo->prepare('INSERT INTO password_resets (email,code,expires_at) VALUES (?, ?, NOW()+INTERVAL 10 MINUTE) ON DUPLICATE KEY UPDATE code=VALUES(code),expires_at=VALUES(expires_at)')->execute([$email,'111111']);
$spec=['script'=>'backend/auth/resetPassword.php','post'=>['email'=>$email,'code'=>'111111','new_password'=>$old,'confirm_password'=>$old]];
check('simultaneous public resets consume the code only once', $wins($parallel($spec))===1);
$token=bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO account_setup_tokens (user_id,token_hash,expires_at) VALUES (?,?,NOW()+INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),expires_at=VALUES(expires_at)')->execute([$p['cashier']['user_id'],hash('sha256',$token)]);
$spec=['script'=>'backend/auth/completeAccountSetup.php','post'=>['token'=>$token,'new_password'=>$new,'confirm_password'=>$new]];
check('simultaneous initial setup consumes the link only once', $wins($parallel($spec))===1);
finish();
