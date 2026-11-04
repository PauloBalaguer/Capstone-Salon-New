<?php
/**
 * Exports the local database into one .sql file to import on a live server.
 * The dev database is only read, never changed.
 *
 * The schema + migrations alone don't rebuild a working database (no service
 * catalog, and migration 009 fails on a fresh schema), so the live copy starts
 * from this export. Compared with a plain phpMyAdmin export, this file:
 *   - drops DEFINER=root@localhost, which shared hosts refuse to import;
 *   - leaves out the *_backup_* tables;
 *   - keeps the structure but not the rows of the tables below, so queued test
 *     emails/SMS aren't sent to real people and old codes and links are gone.
 *
 * Run from the command line (the default output is outside the web root):
 *   C:\xampp\php\php.exe database\scripts\export_for_hosting.php [output file]
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../backend/shared/config/setup/database.php';

const STRUCTURE_ONLY_TABLES = [
    'account_setup_tokens', 'booking_verifications', 'email_outbox', 'password_change_verifications',
    'password_resets', 'rate_limit', 'sms_outbox',
];

$output = $argv[1] ?? envSecretsDir() . DIRECTORY_SEPARATOR . 'salon_live_import.sql';
if (!is_dir(dirname($output))) {
    fwrite(STDERR, 'Output folder does not exist: ' . dirname($output) . "\n");
    exit(1);
}

$pdo = Database::getInstance();
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$backups = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\\_backup\\_%'")->fetchAll(PDO::FETCH_COLUMN);

// XAMPP keeps mysqldump beside PHP (C:\xampp\php and C:\xampp\mysql\bin).
$xamppDump = dirname(PHP_BINARY, 2) . '/mysql/bin/mysqldump.exe';
$mysqldump = is_file($xamppDump) ? $xamppDump : 'mysqldump';
$connection = [
    $mysqldump, '--host=' . env('DB_HOST', '127.0.0.1'), '--port=' . env('DB_PORT', '3306'),
    '--user=' . env('DB_USERNAME', 'root'), '--default-character-set=utf8mb4', '--single-transaction',
];
$ignored = array_map(fn($table) => "--ignore-table={$dbName}.{$table}", array_merge($backups, STRUCTURE_ONLY_TABLES));

$runs = [
    array_merge($connection, ['--routines', '--triggers'], $ignored, [$dbName]),
    array_merge($connection, ['--no-data', '--skip-triggers', $dbName], STRUCTURE_ONLY_TABLES),
];

$out = fopen($output, 'wb');
if ($out === false) {
    fwrite(STDERR, "Could not write {$output}\n");
    exit(1);
}

foreach ($runs as $command) {
    // proc_open takes the arguments as a list, so nothing goes through a shell.
    // The password travels in the environment so it never appears in a process list.
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        array_merge(getenv(), ['MYSQL_PWD' => env('DB_PASSWORD')]));
    if (!is_resource($process)) {
        fclose($out);
        unlink($output);
        fwrite(STDERR, "Could not start mysqldump.\n");
        exit(1);
    }

    while (($line = fgets($pipes[1])) !== false) {
        // Row data is never touched: a value could legitimately contain this text.
        if (!str_starts_with($line, 'INSERT INTO')) {
            $line = preg_replace('~/\*!\d+\s+DEFINER=`[^`]*`@`[^`]*`\s*\*/\s*~', '', $line);
            $line = preg_replace('~DEFINER=`[^`]*`@`[^`]*`\s*~', '', $line);
        }
        fwrite($out, $line);
    }
    $errors = stream_get_contents($pipes[2]);

    if (proc_close($process) !== 0) {
        fclose($out);
        unlink($output);
        fwrite(STDERR, "mysqldump failed: {$errors}\n");
        exit(1);
    }
}
fclose($out);

echo "Exported {$dbName} to {$output}\n";
echo 'Left out ' . count($backups) . " backup table(s). Kept without rows: " . implode(', ', STRUCTURE_ONLY_TABLES) . ".\n";
echo "Import it on a MariaDB server (not MySQL) and don't commit or upload it to the web folder.\n";
