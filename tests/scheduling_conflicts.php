<?php
require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/config/Scheduling.php';
$p = Database::getInstance();
foreach ([
'CREATE TEMPORARY TABLE branches (id INT PRIMARY KEY, branch_key VARCHAR(20), opening_time TIME, closing_time TIME, sunday_opening_time TIME NULL, sunday_closing_time TIME NULL, slot_limit INT)',
'CREATE TEMPORARY TABLE employees (id INT PRIMARY KEY)',
'CREATE TEMPORARY TABLE appointments (id INT PRIMARY KEY, branch_id INT, employee_id INT, appointment_datetime DATETIME, status VARCHAR(30))',
'CREATE TEMPORARY TABLE services (id INT PRIMARY KEY, duration_minutes INT)',
'CREATE TEMPORARY TABLE appointment_services (id INT PRIMARY KEY, appointment_id INT, service_id INT, employee_id INT NULL)',
"INSERT INTO branches VALUES (1,'daraga','08:00','20:00',NULL,NULL,2)",
'INSERT INTO employees VALUES (1),(2)',
'INSERT INTO services VALUES (1,60)',
"INSERT INTO appointments VALUES (1,1,1,'2030-01-01 13:00','Confirmed')",
'INSERT INTO appointment_services VALUES (1,1,1,1)'
] as $sql) $p->exec($sql);
function check($ok,$name) { if (!$ok) throw new Exception('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; }
$p->beginTransaction();
check(Scheduling::staffHasConflict($p,1,'2030-01-01 13:00',60),'same staff same schedule');
check(Scheduling::staffHasConflict($p,1,'2030-01-01 13:30',60),'same staff overlap');
check(!Scheduling::staffHasConflict($p,2,'2030-01-01 13:00',60),'different staff same schedule');
check(!Scheduling::branchWindowIsFull($p,1,'2030-01-01 13:00',60),'branch permits second booking');
check(Scheduling::staffHasConflict($p,1,'2030-01-01 14:29',60),'cleaning buffer');
check(!Scheduling::staffHasConflict($p,1,'2030-01-01 14:30',60),'buffer exact boundary');
check(Scheduling::staffHasConflict($p,1,'2030-01-01 12:00',45),'buffer before existing appointment');
$p->exec("INSERT INTO appointments VALUES (2,1,2,'2030-01-01 13:00','Pending')");
$p->exec('INSERT INTO appointment_services VALUES (2,2,1,2)');
check(Scheduling::branchWindowIsFull($p,1,'2030-01-01 13:00',60),'fully booked branch / stale slot');
$p->exec("UPDATE appointments SET status='Cancelled' WHERE id=2");
check(!Scheduling::staffHasConflict($p,2,'2030-01-01 13:00',60),'cancelled booking releases staff');
$p->exec("UPDATE appointments SET appointment_datetime='2030-01-01 14:00',status='Confirmed' WHERE id=2");
check(!Scheduling::branchWindowIsFull($p,1,'2030-01-01 13:00',120),'disjoint occupancy does not falsely fill branch');
putenv('SALON_CLEANING_BUFFER_MINUTES=0');
check(!Scheduling::staffHasConflict($p,1,'2030-01-01 14:00',60),'configurable buffer');
putenv('SALON_CLEANING_BUFFER_MINUTES');
$p->rollBack();
// Real persistent-row contention, no writes to application data.
$a = new PDO('mysql:host=127.0.0.1;dbname=new_capstone_salon','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$b = new PDO('mysql:host=127.0.0.1;dbname=new_capstone_salon','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$id=$a->query('SELECT id FROM branches LIMIT 1')->fetchColumn();
$a->beginTransaction(); $a->query('SELECT id FROM branches WHERE id='.(int)$id.' FOR UPDATE');
$b->exec('SET SESSION innodb_lock_wait_timeout=1'); $b->beginTransaction();
$blocked=false;
try { Scheduling::branchWindowIsFull($b,(int)$id,'2030-01-01 13:00',60); }
catch(PDOException $e) { $blocked=($e->errorInfo[1]===1205); }
finally { $b->rollBack(); $a->rollBack(); }
check($blocked,'parallel connection blocked by branch mutex');
