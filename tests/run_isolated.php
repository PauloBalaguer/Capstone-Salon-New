<?php
/** CLI only: schema-only sandbox, existing account, no CREATE USER / GRANT. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../backend/config/database.php';
$source = Database::getInstance();
$sourceDb = (string)$source->query('SELECT DATABASE()')->fetchColumn();
$settings = [];
foreach (['host','port','username','password'] as $name) {
    $property = new ReflectionProperty(Database::class, $name);
    $property->setAccessible(true);
    $settings[$name] = $property->getValue();
}
$token = bin2hex(random_bytes(6));
$db = 'leo_defense_test_'.$token;
$sandbox = sys_get_temp_dir().'/leo-defense-'.$token;
$created = false;
$failed = false;
$previousIni = getenv('PHP_INI_SCAN_DIR');

function copyTestTree(string $from, string $to): void {
    if (!is_dir($to)) mkdir($to, 0700, true);
    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot() || $entry->isLink()) continue;
        $name = $entry->getFilename();
        if ($entry->isDir()) {
            if ($name !== 'logs') copyTestTree($entry->getPathname(), $to.'/'.$name);
        } elseif (in_array(strtolower($entry->getExtension()), ['php','json'], true)
            && !preg_match('/credentials\.php$/i', $name)) {
            copy($entry->getPathname(), $to.'/'.$name);
        }
    }
}

function removeTestTree(string $path): void {
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) continue;
        if ($entry->isDir() && !$entry->isLink()) removeTestTree($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
}

try {
    copyTestTree(__DIR__.'/../backend', $sandbox.'/backend');
    copyTestTree(__DIR__, $sandbox.'/tests');
    mkdir($sandbox.'/ini');
    mkdir($sandbox.'/sessions');
    file_put_contents($sandbox.'/ini/safety.ini', implode("\n", [
        'disable_functions=curl_exec,curl_multi_exec,mail,fsockopen,pfsockopen,stream_socket_client,socket_connect',
        'allow_url_fopen=0', 'display_errors=1', 'log_errors=0',
        'session.save_path="'.str_replace('\\','/',$sandbox).'/sessions"',
    ]));
    $source->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $created = true;
    $dsn = "mysql:host={$settings['host']};port={$settings['port']};dbname=$db;charset=utf8mb4";
    $target = new PDO($dsn, $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $target->exec('SET FOREIGN_KEY_CHECKS=0');
    $tableCount = 0;
    foreach ($source->query('SHOW FULL TABLES WHERE Table_type="BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $row) {
        $quoted = '`'.str_replace('`','``',$row[0]).'`';
        $ddl = $source->query("SHOW CREATE TABLE $quoted")->fetch(PDO::FETCH_NUM)[1];
        $target->exec(str_replace($sourceDb, $db, $ddl));
        $tableCount++;
    }
    // Fresh sequence, actual reference function/triggers; no customer rows copied.
    $target->exec('CREATE SEQUENCE booking_reference_sequence START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE');
    $definition = $source->query('SHOW CREATE FUNCTION next_booking_reference')->fetch(PDO::FETCH_ASSOC)['Create Function'];
    $definition = preg_replace('/DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*/i', '', $definition);
    $target->exec(str_replace($sourceDb, $db, $definition));
    foreach (['appointments_reference_insert','appointments_reference_immutable','home_service_reference_insert',
        'home_service_reference_immutable','booking_registry_no_delete','booking_registry_no_update'] as $trigger) {
        $definition = $source->query('SHOW CREATE TRIGGER '.$trigger)->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
        $definition = preg_replace('/DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*/i', '', $definition);
        $target->exec(str_replace($sourceDb, $db, $definition));
    }
    $target->exec("INSERT INTO branches (id,branch_key,branch_name,opening_time,closing_time,slot_limit) VALUES (1,'daraga','Isolated Test Branch','08:00','20:00',3)");
    $target->exec("INSERT INTO employees (id,user_id,branch_id,first_name,last_name,phone_number,is_active) VALUES (1,1,1,'Isolated','Staff','09123456789',1)");
    $target->exec('SET FOREIGN_KEY_CHECKS=1');

    // Rewrite sandbox copies only. The application's configuration stays intact.
    $configPath = $sandbox.'/backend/config/database.php';
    $config = str_replace(var_export($sourceDb, true), var_export($db, true), file_get_contents($configPath));
    file_put_contents($configPath, $config);
    foreach (glob($sandbox.'/tests/*.php') as $test) {
        if (basename($test) === basename(__FILE__)) continue;
        $text = file_get_contents($test);
        $text = str_replace("new PDO('mysql:host=127.0.0.1;dbname=new_capstone_salon','root','',",
            'new PDO('.var_export($dsn,true).','.var_export($settings['username'],true).','.var_export($settings['password'],true).',', $text);
        if (str_contains($text, 'dbname=new_capstone_salon')) throw new RuntimeException('Unredirected test connection: '.basename($test));
        file_put_contents($test, $text);
    }
    putenv('PHP_INI_SCAN_DIR='.$sandbox.'/ini');
    echo "PASS connection / isolated schema: $tableCount tables; actual reference objects; existing account; providers disabled\n";
    $suites = ['scheduling_conflicts.php','booking_workflow_conflicts.php','booking_form_validation.php','payment_workflow.php',
        'arrival_closure_workflow.php','event_availability.php','home_service_address.php','admin_booking_list.php',
        'customer_notifications.php','loyalty_reports.php','defense_customer_journey.php','booking_otp_delivery.php'];
    foreach ($suites as $suite) {
        $output = []; $exit = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($sandbox.'/tests/'.$suite).' 2>&1', $output, $exit);
        $log = implode("\n", $output);
        $ok = $exit === 0 && str_contains($log, 'PASS ') && !preg_match('/FAIL |Fatal error|Warning:|Parse error|Database connection failed/', $log);
        echo ($ok ? 'PASS ' : 'FAIL ').$suite."\n";
        if (!$ok) { $failed = true; echo $log."\n"; }
    }
    $output = []; $exit = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($sandbox.'/tests/admin_booking_list.php').' endpoint 2>&1', $output, $exit);
    $ok = $exit === 0 && str_contains(implode("\n",$output), 'PASS paginated API') && !str_contains(implode("\n",$output), 'FAIL ');
    echo ($ok ? 'PASS ' : 'FAIL ')."admin booking endpoint\n";
    if (!$ok) { $failed = true; echo implode("\n",$output)."\n"; }
} catch (Throwable $e) {
    $failed = true;
    // Do not expose credentials, filesystem copies, or query text in error traces.
    if ($e instanceof PDOException) echo 'FAIL database setup: SQLSTATE '.$e->getCode().'; driver '.($e->errorInfo[1]??'unknown')."\n";
    else echo 'FAIL test setup: '.$e->getMessage()."\n";
} finally {
    putenv($previousIni === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR='.$previousIni);
    $target = null;
    if ($created && preg_match('/^leo_defense_test_[a-f0-9]{12}$/D',$db)) {
        try {
            $source->exec("DROP DATABASE $db");
            echo "PASS disposable schema removed\n";
        } catch (Throwable $e) {
            $failed = true;
            echo "FAIL cleanup: disposable schema $db remains; no application schema was targeted\n";
        }
    }
    // The random directory must still be directly inside the system temp folder.
    if (is_dir($sandbox) && realpath(dirname($sandbox)) === realpath(sys_get_temp_dir())
        && basename($sandbox) === 'leo-defense-'.$token) removeTestTree($sandbox);
}
exit($failed ? 1 : 0);
