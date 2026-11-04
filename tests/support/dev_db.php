<?php
/**
 * The dev database the tests copy, and the MySQL port: DB_NAME / DB_PORT
 * from the private .env (or the server environment), like the app itself.
 *
 * Read in a child process, so this process never loads the .env and its API
 * keys -- tests must not send real emails or texts. DB_PORT is exported, so
 * the app's Database class here and in every endpoint child process
 * (support/endpoint.php) uses the same port with the .env hidden.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

$settings = proc_open([PHP_BINARY, '-r', 'require ' . var_export(dirname(__DIR__, 2) . '/backend/shared/config/setup/env.php', true)
    . "; echo env('DB_NAME', 'new_capstone_salon'), PHP_EOL, env('DB_PORT', '3306');"], [1 => ['pipe', 'w']], $pipes);
[$devDatabase, $devPort] = array_map('trim', explode(PHP_EOL, stream_get_contents($pipes[1])) + [1 => '3306']);
proc_close($settings);
putenv("DB_PORT={$devPort}");

define('DEV_DATABASE', $devDatabase);
define('TEST_MYSQL', "C:\\xampp\\mysql\\bin\\mysql.exe -uroot -P{$devPort} -h127.0.0.1");
define('TEST_DUMP', "C:\\xampp\\mysql\\bin\\mysqldump.exe -uroot -P{$devPort} -h127.0.0.1 --routines --triggers");
unset($settings, $pipes, $devDatabase, $devPort);
