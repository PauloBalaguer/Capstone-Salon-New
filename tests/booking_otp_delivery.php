<?php
// Run through run_isolated.php; never send real verification messages.
require __DIR__.'/../backend/config/database.php';
$p = Database::getInstance();
if (!preg_match('/^leo_defense_test_[a-f0-9]{12}$/D', (string)$p->query('SELECT DATABASE()')->fetchColumn())) {
    fwrite(STDERR, "Refusing OTP tests outside a disposable defense database.\n"); exit(1);
}
if (!isset($argv[1])) {
    foreach (['missing_credentials','incomplete_credentials','smtp_failed','failed_resend','sent','invalid_email','array_email','invalid_method','cooldown_30','cooldown_59','cooldown_expired','legacy_hour'] as $case) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.$case, $exit);
        if ($exit) exit($exit);
    }
    exit;
}
$case = $argv[1];
foreach (['booking_verifications','rate_limit'] as $table) {
    $ddl = $p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $p->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl));
}
$p->exec('SET timestamp = '.time());
if (str_starts_with($case, 'cooldown_') || $case === 'legacy_hour') {
    $age = match ($case) { 'cooldown_30'=>30, 'cooldown_59'=>59, 'cooldown_expired'=>60, default=>120 };
    $insert = $p->prepare("INSERT INTO rate_limit (identifier,type,created_at) VALUES ('127.0.0.1','booking_otp',DATE_SUB(NOW(), INTERVAL ? SECOND))");
    for ($i=0; $i<($case === 'legacy_hour' ? 5 : 1); $i++) $insert->execute([$age]);
}
if ($case === 'failed_resend') {
    $p->exec("INSERT INTO booking_verifications (email,code,expires_at) VALUES ('guest@example.invalid','123456',DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
}
$before = $p->query('SELECT * FROM booking_verifications')->fetchAll();
if (in_array($case, ['missing_credentials','incomplete_credentials'], true)) {
    // The isolated runner omits credentials. Never risk an actual SMTP request.
    if (is_file(__DIR__.'/../backend/config/mail_credentials.php')) throw new RuntimeException('Credentials must be absent from test sandbox');
    if ($case === 'incomplete_credentials') {
        file_put_contents(__DIR__.'/../backend/config/mail_credentials.php', "<?php return ['smtp_host'=>'smtp.gmail.com','smtp_username'=>'sender@example.invalid','smtp_password'=>'','from_email'=>'sender@example.invalid'];");
    }
    require __DIR__.'/../backend/config/mail.php';
} else {
    function sendBookingOtpEmail(string $email, string $code): bool {
        $GLOBALS['attemptedCode'] = $code;
        return in_array($GLOBALS['case'], ['sent','cooldown_expired','legacy_hour'], true);
    }
}
$_SERVER['REQUEST_METHOD'] = $case === 'invalid_method' ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_POST = ['email'=>$case === 'invalid_email' ? 'bad@' : ($case === 'array_email' ? ['bad'] : 'guest@example.invalid')];
ob_start();
register_shutdown_function(function () use ($p, $case, $before) {
    if ($case === 'incomplete_credentials') unlink(__DIR__.'/../backend/config/mail_credentials.php');
    $body = json_decode(ob_get_clean(), true);
    $after = $p->query('SELECT * FROM booking_verifications')->fetchAll();
    $sent = in_array($case, ['sent','cooldown_expired','legacy_hour'], true);
    $expectedStatus = match ($case) { 'sent','cooldown_expired','legacy_hour'=>200, 'invalid_email','array_email'=>400, 'invalid_method'=>405, 'cooldown_30','cooldown_59'=>429, default=>503 };
    $ok = http_response_code() === $expectedStatus && ($body['success'] ?? null) === $sent && !$p->inTransaction();
    if ($sent) $ok = $ok && count($after) === 1 && $after[0]['code'] === $GLOBALS['attemptedCode'] && preg_match('/^[0-9]{6}$/D',$after[0]['code']) && $body['retryAfter'] === 60;
    else $ok = $ok && $after === $before;
    if (in_array($case, ['cooldown_30','cooldown_59'], true)) {
        $ok = $ok && $body['retryAfter'] === ($case === 'cooldown_30' ? 30 : 1)
            && str_contains($body['message'], 'seconds') && !str_contains($body['message'], 'hour') && !isset($GLOBALS['attemptedCode']);
    }
    if (!$ok) { fwrite(STDERR, "FAIL OTP delivery $case: ".json_encode($body)."\n"); exit(1); }
    echo "PASS OTP delivery $case; truthful status and verification records\n";
});
chdir(__DIR__.'/../backend/public');
$source = file_get_contents('sendBookingOtp.php');
$source = str_replace("require_once '../config/mail.php';", '', $source);
eval(substr($source,5));
