<?php

require_once __DIR__ . '/CustomerNotifier.php';
require_once __DIR__ . '/EmailOutbox.php';

/**
 * "Notify me when my stylist is free" waitlist.
 *
 * A customer (registered or guest) whose preferred stylist is fully booked
 * on a date joins the waitlist for that stylist + date
 * (backend/public/joinWaitlist.php). Whenever one of that stylist's
 * appointments on that date is cancelled, marked No-Show, or released by an
 * expired online payment, notifyOpening() emails (and, for registered
 * customers, in-app notifies) everyone waiting. Openings are first come,
 * first served -- being notified doesn't reserve the slot.
 */
final class Waitlist
{
    public static function notifyOpening(PDO $pdo, int $employeeId, string $appointmentDateTime): void
    {
        $date = substr($appointmentDateTime, 0, 10);
        $stmt = $pdo->prepare("
            SELECT w.id, w.customer_id, w.name, w.email, w.desired_time,
                   CONCAT(e.first_name, ' ', e.last_name) AS stylist, b.branch_name
            FROM stylist_waitlist w
            JOIN employees e ON e.id = w.employee_id
            JOIN branches b ON b.id = w.branch_id
            WHERE w.employee_id = ? AND w.desired_date = ? AND w.status = 'Waiting' AND w.desired_date >= CURDATE()
        ");
        $stmt->execute([$employeeId, $date]);
        $entries = $stmt->fetchAll();
        if (!$entries) return;

        $time = date('g:i A', strtotime($appointmentDateTime));
        $dateLabel = date('l, F j, Y', strtotime($date));
        $mark = $pdo->prepare("UPDATE stylist_waitlist SET status = 'Notified', notified_at = NOW() WHERE id = ?");

        foreach ($entries as $entry) {
            $message = "Good news, {$entry['name']}! {$entry['stylist']} at {$entry['branch_name']} now has an opening on {$dateLabel} around {$time}. "
                . 'Book soon — openings are first come, first served.';
            if ($entry['customer_id']) {
                CustomerNotifier::notify($pdo, (int) $entry['customer_id'], 'WAITLIST_OPENING', $message);
            } else {
                EmailOutbox::queue($pdo, $entry['email'], 'Your preferred stylist has an opening', $message
                    . "\n\nBook on our website to claim it.");
            }
            $mark->execute([$entry['id']]);
        }
    }
}
