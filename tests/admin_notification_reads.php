<?php
// All fixtures are temporary tables, scoped to this connection.
require __DIR__.'/../backend/config/database.php';
require __DIR__.'/../backend/config/session.php';
$pdo = Database::getInstance();
$pdo->exec('CREATE TEMPORARY TABLE notifications (id INT PRIMARY KEY, user_id INT NULL)');
$pdo->exec('CREATE TEMPORARY TABLE admin_notification_reads (user_id INT, notification_id INT, PRIMARY KEY(user_id, notification_id))');
$pdo->exec('INSERT INTO notifications VALUES (1,NULL),(2,99),(3,1),(4,NULL)');
$pdo->exec('INSERT INTO admin_notification_reads VALUES (2,1)');
$_SESSION = ['user_id'=>1,'user_role'=>'Admin'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['through'=>'3'];
ob_start();
register_shutdown_function(function () use ($pdo) {
    $result = json_decode(ob_get_contents(), true);
    ob_end_clean();
    $rows = $pdo->query('SELECT notification_id FROM admin_notification_reads WHERE user_id=1 ORDER BY notification_id')->fetchAll(PDO::FETCH_COLUMN);
    $other = $pdo->query('SELECT COUNT(*) FROM admin_notification_reads WHERE user_id=2')->fetchColumn();
    if (empty($result['success']) || array_map('intval', $rows) !== [1,3] || (int)$other !== 1) {
        echo "FAIL admin read scope\n"; exit(1);
    }
    echo "PASS per-admin reads, private notification scope, and new-notification cutoff\n";
});
chdir(__DIR__.'/../backend/admin');
require 'markNotificationsRead.php';
