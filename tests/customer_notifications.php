<?php
// Mock SMTP transport and connection-local SQL fixtures: never send real mail/SMS.
require __DIR__.'/../backend/config/database.php';
$GLOBALS['delivered']=[];$GLOBALS['deliveryOK']=true;
function sendNotificationEmail(string $to,string $subject,string $body):bool {
    $GLOBALS['delivered'][]=[$to,$subject,$body];return $GLOBALS['deliveryOK'];
}
foreach(['EmailOutbox','CustomerNotifier'] as $class) {
    $source=file_get_contents(__DIR__.'/../backend/config/'.$class.'.php');
    $source=preg_replace('/^require_once .*;\R/m','',$source);
    eval(substr($source,5));
}
$hook=new ReflectionProperty(EmailOutbox::class,'hookRegistered');$hook->setAccessible(true);$hook->setValue(null,true);
$p=Database::getInstance();
foreach(['users','customers','appointments','appointment_services','services','branches','home_service_requests','notifications','email_outbox'] as $table) {
    $ddl=$p->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/,?\s*CONSTRAINT `[^`]+` FOREIGN KEY .*?(?=,\n|\n\))/s','',$ddl);
    $p->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
function notificationCheck($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$p->exec("INSERT INTO users (id,role_id,email,password) VALUES (1,1,'account@example.com','test')");
$p->exec("INSERT INTO customers (id,user_id,first_name,last_name,phone_number,email,notify_email,notify_sms) VALUES
 (1,1,'Test','Customer','09123456789',NULL,1,1),(2,NULL,'Guest','Customer','09999999999','guest@example.com',1,0)");
$p->exec("INSERT INTO branches (id,branch_key,branch_name) VALUES (1,'daraga','Daraga Test Branch')");
$p->exec("INSERT INTO services (id,branch_id,service_name,duration_minutes,price) VALUES (1,1,'Hair Styling',60,1000)");
$when=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->modify('+2 hours')->format('Y-m-d H:i:s');
$p->prepare("INSERT INTO appointments (id,reference_code,customer_id,branch_id,appointment_datetime,total_price,status,payment_status) VALUES
 (1,'LM-DAR-2026-00001',1,1,?,1000,'Confirmed','Fully Paid'),(2,'LM-DAR-2026-00002',2,1,?,1000,'Confirmed','Payment Required')")->execute([$when,$when]);
$p->exec('INSERT INTO appointment_services (appointment_id,service_id) VALUES (1,1),(2,1)');
$ref='LM-DAR-2026-00001';
foreach(['BOOKING_SUBMITTED','CONFIRMED','PAYMENT_RECEIVED','PAYMENT_VERIFIED','PAYMENT_ATTENTION','PAYMENT_REFUNDED','RESCHEDULE','CANCELLED'] as $type) {
    notificationCheck(CustomerNotifier::notify($p,1,$type,"Update for $ref: $type."),'queued '.$type);
}
$body=$p->query('SELECT body FROM email_outbox ORDER BY id LIMIT 1')->fetchColumn();
foreach([$ref,'Hair Styling','Daraga Test Branch',substr($when,0,16),'Booking status: Confirmed','Payment status: Fully Paid'] as $text)notificationCheck(str_contains($body,$text),'message details '.$text);
notificationCheck((bool)$p->query("SELECT id FROM email_outbox WHERE subject LIKE 'Your full payment was received%'")->fetchColumn(),'full payment subject');
$before=$p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();
CustomerNotifier::notify($p,1,'CONFIRMED',"Update for $ref: CONFIRMED.");
notificationCheck($p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$before,'duplicate event/email suppressed');
notificationCheck($p->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===$before,'duplicate bell entry suppressed');
$p->beginTransaction();CustomerNotifier::notify($p,1,'CONFIRMED','Rollback only notification',$ref);
notificationCheck(EmailOutbox::flush($p)===0,'uncommitted emails never sent');$p->rollBack();
notificationCheck($p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$before,'queue rolls back with business transaction');
$p->exec('UPDATE customers SET notify_email=0 WHERE id=1');
CustomerNotifier::notify($p,1,'CONFIRMED','Email disabled; keep bell notification',$ref);
notificationCheck($p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$before,'email opt-out respected independently of SMS');
$GLOBALS['delivered']=[];EmailOutbox::flush($p);
notificationCheck(count($GLOBALS['delivered'])===0,'queued email also respects a later opt-out');
$p->exec('UPDATE customers SET notify_email=1 WHERE id=1');
$p->prepare("INSERT INTO home_service_requests (id,reference_code,customer_id,contact_email,address,event_type,wedding_package,preferred_date,preferred_time,status) VALUES (1,'LM-HOM-2026-00001',2,'event@example.com','Test Address','Wedding','D',?, '10:00','Pending Review')")->execute([substr($when,0,10)]);
CustomerNotifier::notify($p,2,'BOOKING_SUBMITTED','Your wedding request was received.','LM-HOM-2026-00001');
notificationCheck($p->query("SELECT to_email FROM email_outbox WHERE reference_code='LM-HOM-2026-00001'")->fetchColumn()==='event@example.com','guest request contact email used');
$GLOBALS['deliveryOK']=true;
CustomerNotifier::notify($p,1,'PAYMENT_RECEIVED','Another payment update',$ref);
notificationCheck(EmailOutbox::flush($p)===2,'queued events merged by recipient and delivered through mock');
notificationCheck(EmailOutbox::flush($p)===0,'repeated email worker does not resend sent rows');
$p->exec('DELETE FROM email_outbox');$GLOBALS['deliveryOK']=false;$GLOBALS['delivered']=[];
EmailOutbox::queue($p,'account@example.com','Retry test','Mock delivery failure');
for($attempt=1;$attempt<=3;$attempt++) {
    EmailOutbox::flush($p);$row=$p->query('SELECT * FROM email_outbox LIMIT 1')->fetch();
    notificationCheck((int)$row['attempts']===$attempt&&$row['status']===($attempt===3?'Failed':'Pending'),'retry attempt '.$attempt);
}
EmailOutbox::flush($p);notificationCheck(count($GLOBALS['delivered'])===3,'retry limit exactly three sends');
$other=new PDO('mysql:host=127.0.0.1;dbname=new_capstone_salon','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$other->query("SELECT GET_LOCK(CONCAT('salon-mail-', DATABASE()),0)")->fetchColumn();
try{notificationCheck(EmailOutbox::flush($p)===0,'second queue worker excluded by shared mutex');}
finally{$other->query("SELECT RELEASE_LOCK(CONCAT('salon-mail-', DATABASE()))");}
$p->exec('DELETE FROM email_outbox');$p->exec('DELETE FROM notifications');$GLOBALS['delivered']=[];$GLOBALS['deliveryOK']=true;
$source=file_get_contents(__DIR__.'/../backend/cron/sendAppointmentReminders.php');
$source=preg_replace('/^require_once .*;\R/m','',$source);eval(substr($source,5));
notificationCheck(queueAppointmentReminders($p)===2,'registered and guest reminders queued');
notificationCheck(queueAppointmentReminders($p)===0,'repeated reminder execution is idempotent');
notificationCheck($p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===2,'guest email fallback works');
$p->exec("UPDATE appointments SET status='Cancelled' WHERE id=2");
$later=(new DateTimeImmutable($when))->modify('+1 hour')->format('Y-m-d H:i:s');
$p->prepare('UPDATE appointments SET appointment_datetime=?,reminder_sent=0 WHERE id=1')->execute([$later]);
notificationCheck(queueAppointmentReminders($p)===1,'rescheduled booking gets a new schedule reminder');
notificationCheck(EmailOutbox::flush($p)===1,'obsolete cancelled/rescheduled reminders suppressed');
notificationCheck(count($GLOBALS['delivered'])===1&&str_contains($GLOBALS['delivered'][0][2],substr($later,0,16)),'only current reminder delivered');
$p->exec('UPDATE customers SET notify_email=0,notify_sms=1 WHERE id=1');
$p->exec('UPDATE appointments SET reminder_sent=0 WHERE id=1');
$emailCount=$p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();queueAppointmentReminders($p);
notificationCheck($p->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$emailCount&&queueAppointmentReminders($p)===0,'opt-out handled once; no SMS dispatch');
notificationCheck(sendAppointmentReminderSms('09123456789','Test')===false,'SMS placeholder never claims delivery');
$p->exec('UPDATE customers SET notify_email=1 WHERE id=1');
$p->exec("ALTER TABLE email_outbox ADD CONSTRAINT reject_test_email CHECK (body NOT LIKE '%reject-queue-fixture%')");
$p->beginTransaction();$p->exec('UPDATE appointments SET total_price=1100 WHERE id=1');
notificationCheck(!CustomerNotifier::notify($p,1,'CONFIRMED','reject-queue-fixture',$ref),'queue failure reported without throwing into booking workflow');
notificationCheck($p->inTransaction() && (float)$p->query('SELECT total_price FROM appointments WHERE id=1')->fetchColumn()===1100.0,'queue failure preserves caller transaction');
$p->commit();
