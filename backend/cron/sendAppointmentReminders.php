<?php

/**
 * Cron: Send Appointment Reminders
 *
 * Emails customers whose Confirmed appointment starts within the next 24
 * hours and hasn't been reminded yet (appointments.reminder_sent). Meant to
 * run periodically from the command line, e.g. every 30-60 minutes via
 * Windows Task Scheduler or cron -- it is NOT web-exposed on purpose, since
 * an unauthenticated HTTP endpoint that mass-emails customers on request
 * would be an abuse vector.
 *
 * SMS isn't wired up yet -- no provider account/API key exists (Semaphore is
 * the common choice for PH numbers) -- so sendAppointmentReminderSms() below
 * only logs what would have been sent. Customers reachable only by SMS (no
 * linked user account/email, e.g. most guest bookings) are intentionally
 * left with reminder_sent = 0 so a future SMS-capable run can still pick
 * them up; customers who opted out of email (notify_email = 0) are marked
 * handled since there's currently no other channel to try.
 *
 * Usage:
 *   php backend/cron/sendAppointmentReminders.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/CustomerNotifier.php';

/**
 * Placeholder for a future SMS reminder path. Needs a paid provider account
 * before this can actually send anything -- for now it only logs so the
 * dispatch loop below is ready to wire up once that exists.
 */
function sendAppointmentReminderSms(string $phone, string $message): bool
{
    error_log("[SMS reminder placeholder] Would text {$phone}: {$message}");
    return false;
}

$pdo = Database::getInstance();

$stmt = $pdo->query("
    SELECT
        a.id, a.reference_code, a.appointment_datetime, a.customer_id,
        c.first_name, c.last_name, c.phone_number, c.notify_email, c.notify_sms,
        u.email,
        br.branch_name,
        GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.id SEPARATOR ', ') AS serviceNames
    FROM appointments a
    JOIN customers c ON c.id = a.customer_id
    LEFT JOIN users u ON u.id = c.user_id
    LEFT JOIN branches br ON br.id = a.branch_id
    LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
    LEFT JOIN services s ON s.id = aps.service_id
    WHERE a.status = 'Confirmed'
      AND a.reminder_sent = 0
      AND a.appointment_datetime BETWEEN NOW() AND (NOW() + INTERVAL 24 HOUR)
    GROUP BY a.id
");
$due = $stmt->fetchAll();

echo count($due) . " appointment(s) due for a reminder.\n";

$markSent = $pdo->prepare('UPDATE appointments SET reminder_sent = 1 WHERE id = ?');

foreach ($due as $appt) {
    $customerName = trim($appt['first_name'] . ' ' . $appt['last_name']);
    $when = date('l, F j \a\t g:i A', strtotime($appt['appointment_datetime']));
    $smsMessage = "Hi {$customerName}, reminder: your {$appt['serviceNames']} appointment at {$appt['branch_name']} is on {$when}. Ref: {$appt['reference_code']}.";

    // In-app bell notification is its own channel, independent of the
    // email/SMS opt-in preferences below, so every due appointment gets one.
    CustomerNotifier::notify(
        $pdo,
        (int) $appt['customer_id'],
        'REMINDER',
        "Reminder: Your appointment {$appt['reference_code']} for \"{$appt['serviceNames']}\" is coming up on {$when}."
    );

    $handled = false;

    if ($appt['email'] && (int) $appt['notify_email'] === 1) {
        sendAppointmentReminderEmail(
            $appt['email'],
            $customerName,
            $appt['serviceNames'],
            $appt['branch_name'] ?? 'the salon',
            $appt['appointment_datetime'],
            $appt['reference_code']
        );
        $handled = true;
        echo "  {$appt['reference_code']}: emailed {$appt['email']}\n";
    } elseif ($appt['email'] && (int) $appt['notify_email'] === 0) {
        // Opted out of email and SMS isn't sendable yet -- nothing left to
        // try, stop re-selecting this one every run.
        $handled = true;
        echo "  {$appt['reference_code']}: customer opted out of email reminders, marking handled\n";
    }

    if (!$handled && (int) $appt['notify_sms'] === 1) {
        sendAppointmentReminderSms($appt['phone_number'], $smsMessage);
        echo "  {$appt['reference_code']}: no email on file -- SMS placeholder logged only, not yet sendable\n";
    } elseif (!$handled) {
        echo "  {$appt['reference_code']}: no email on file and SMS reminders disabled -- skipped\n";
    }

    if ($handled) {
        $markSent->execute([$appt['id']]);
    }
}

echo "Done.\n";
