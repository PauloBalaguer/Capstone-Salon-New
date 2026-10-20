<?php
// Connection-local fixtures: application rows are never changed.
if (!isset($argv[1])) {
    foreach (['slots_single', 'slots_split', 'staff_key', 'staff_buffer'] as $case) {
        passthru(escapeshellarg(PHP_BINARY).' -d disable_functions=curl_exec,mail,fsockopen,stream_socket_client '.escapeshellarg(__FILE__).' '.$case, $exit);
        if ($exit) exit($exit);
    }
    exit;
}
$case = $argv[1];
require __DIR__.'/../backend/config/database.php';
$p = Database::getInstance();
foreach (['branches','employees','customers','appointments','appointment_services','services','staff_services','home_service_requests','notifications','payments','payment_transactions','stylist_waitlist'] as $table) {
    $ddl = $p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl = preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s', '', $ddl);
    $p->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl));
}
$p->exec("INSERT INTO branches (id,branch_key,branch_name,opening_time,closing_time,slot_limit) VALUES (1,'daraga','Test','08:00','20:00',3)");
$p->exec("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number,is_active) VALUES (1,1,1,'One','Test','1',1),(2,2,1,'Two','Test','2',1)");
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price) VALUES (1,1,'One',60,100),(2,1,'Two',60,100)");
$p->exec('INSERT INTO staff_services (employee_id,service_id) VALUES (1,1),(2,2)');
if ($case === 'staff_buffer') {
    $p->exec("INSERT INTO appointments (id,reference_code,customer_id,branch_id,employee_id,appointment_datetime,total_price,status) VALUES (1,'TEST',1,1,1,'2030-01-01 12:00',100,'Confirmed')");
    $p->exec('INSERT INTO appointment_services (appointment_id,service_id,employee_id) VALUES (1,1,1)');
}
$_GET = ['branchKey'=>'daraga','date'=>'2030-01-01','time'=>'13:15','services'=>str_starts_with($case,'slots') ? ['1','2'] : ['1']];
if ($case === 'slots_single') $_GET['assignment'] = 'single';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
register_shutdown_function(function () use ($case) {
    $data = json_decode(ob_get_contents(), true);
    $ok = $data['success'] ?? false;
    if (str_starts_with($case, 'slots')) {
        $slot = array_values(array_filter($data['slots'] ?? [], fn($s) => $s['time'] === '10:00 AM'))[0] ?? null;
        $ok = $ok && $slot && $slot['available'] === ($case === 'slots_split');
    } else {
        $ok = $ok && count($data['staff'] ?? []) === ($case === 'staff_key' ? 1 : 0)
            && count($data['busy'] ?? []) === ($case === 'staff_buffer' ? 1 : 0);
    }
    ob_end_clean();
    echo ($ok ? 'PASS ' : 'FAIL ').$case."\n";
    if (!$ok) exit(1);
});
chdir(__DIR__.'/../backend/public');
require str_starts_with($case, 'slots') ? 'getAvailableSlots.php' : 'getAvailableStaff.php';
