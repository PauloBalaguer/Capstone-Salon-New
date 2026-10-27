<?php

require_once __DIR__ . '/EmailOutbox.php';
require_once __DIR__ . '/SmsOutbox.php';
require_once __DIR__ . '/../booking/Scheduling.php';

/**
 * Customer notifications for every booking write path.
 *
 * In-app: registered customers get a notifications row (user_id set to
 * their own account) for the dashboard bell/panel. Guest bookings have no
 * linked user account (customers.user_id is NULL), so they get no in-app
 * notification -- they use Track a Booking instead.
 *
 * Email: the same message is also queued to the customer's email (their
 * account email, or the OTP-verified email a guest gave when booking --
 * customers.email) unless they turned email notifications off. Queued via
 * EmailOutbox so it only goes out if the surrounding change commits.
 * Reminders use the same queue, with scheduling handled by the reminder cron.
 *
 * SMS: types with a template in SMS_TEMPLATES (backend/shared/config/setup/sms.php)
 * also queue a short text to customers.phone_number unless the customer
 * turned SMS off (notify_sms). Best-effort: an SMS problem is logged and
 * never undoes the booking change or the email.
 */
final class CustomerNotifier
{
    private const EMAIL_SUBJECTS = [
        'BOOKING_SUBMITTED' => 'We received your booking request',
        'PAYMENT_SUBMITTED' => 'We received your reservation payment',
        'PAYMENT_VERIFIED' => 'Your reservation payment is verified',
        'PAYMENT_ATTENTION' => 'Your payment needs attention',
        'PAYMENT_FORFEITED' => 'Your reservation deposit was forfeited',
        'REFUND_DUE' => 'Your reservation deposit will be refunded',
        'PAYMENT_REFUNDED' => 'Your reservation deposit has been refunded',
        'CONFIRMED' => 'Your appointment is confirmed',
        'RESCHEDULE' => 'Appointment schedule update',
        'CANCELLED' => 'Your appointment was cancelled',
        'COMPLETED' => 'Thank you for visiting us',
        'WAITLIST_OPENING' => 'Your preferred stylist has an opening',
        'QUOTE_READY' => 'Your home service quote is ready',
        'REMINDER' => 'Appointment reminder',
        'PAYMENT_REMINDER' => 'Complete your booking payment before it expires',
        'PAYMENT_RECEIVED' => 'Your payment was received',
    ];
    // In-salon appointment emails that repeat the late-arrival rule (when one is configured).
    private const LATE_RULE_TYPES = ['BOOKING_SUBMITTED', 'CONFIRMED', 'RESCHEDULE', 'REMINDER'];

    public static function notify(PDO $pdo, int $customerId, string $type, string $message, ?string $reference = null): bool
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT customer_notification');
        try {
        $stmt = $pdo->prepare('
            SELECT c.user_id, c.notify_email, c.notify_sms, c.phone_number, c.first_name, c.last_name, COALESCE(NULLIF(u.email, \'\'), c.email) AS email
            FROM customers c LEFT JOIN users u ON u.id = c.user_id
            WHERE c.id = ? LIMIT 1 FOR UPDATE
        ');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            if ($ownsTransaction) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT customer_notification');
            return false;
        }

        $reference = $reference ?? (preg_match('/\bLM-(?:[A-Z]+-)+\d{4}-\d+\b/', $message, $m) ? $m[0] : null);
        $details = $reference ? self::bookingDetails($pdo, $customerId, $reference) : null;
        if ($details) $message .= "\n\n" . $details['text'];
        if ($customer['user_id']) {
            $existing = $pdo->prepare('SELECT id FROM notifications WHERE user_id = ? AND type = ? AND message = ? LIMIT 1 FOR UPDATE');
            $existing->execute([$customer['user_id'],$type,$message]);
            if (!$existing->fetchColumn()) $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)')
                ->execute([$customer['user_id'], $type, $message]);
        }

        // A home service message goes to the email given on that request
        // (home_service_requests.contact_email) -- guests track, pay and
        // reschedule with it, and their shared customer record may have none.
        if (!empty($details['contactEmail'])) $customer['email'] = $details['contactEmail'];

        if (isset(self::EMAIL_SUBJECTS[$type]) && $customer['email'] && (int) $customer['notify_email'] === 1) {
            $lateRule = $details && !$details['homeService'] && in_array($type, self::LATE_RULE_TYPES, true) ? Scheduling::lateArrivalRule() : null;
            $body = 'Hello ' . trim($customer['first_name'].' '.$customer['last_name']) . ",\n\n" . $message . ($lateRule ? "\n\nLate arrival: {$lateRule}" : '') . ($customer['user_id']
                ? "\n\nYou can view your appointment details anytime in your Leo Mejillano account."
                : (!empty($details['homeService'])
                    ? "\n\nTrack, pay or reschedule anytime in Track Your Home Service (Home & Events) on our website using reference {$reference} and this email address."
                    : "\n\nYou can check your booking anytime with Track a Booking on our website" . ($reference ? " using reference {$reference}." : '.')));
            $subject = $type === 'PAYMENT_VERIFIED' && ($details['paymentStatus'] ?? '') === 'Fully Paid' ? 'Your full payment was received' : self::EMAIL_SUBJECTS[$type];
            EmailOutbox::queue($pdo, $customer['email'], $subject . ($reference ? " ({$reference})" : ''), $body, $reference);
        }
        if (isset(SMS_TEMPLATES[$type]) && (int) $customer['notify_sms'] === 1) {
            $pdo->exec('SAVEPOINT customer_sms');
            try {
                $vars = [];
                if ($reference) $vars['{ref}'] = $reference;
                if ($details) $vars['{when}'] = formatSmsWhen($details['scheduled']);
                $sms = renderSms($type, $vars);
                if ($sms) SmsOutbox::queue($pdo, $customer['phone_number'], $type, $sms, $reference);
                else error_log("SMS not queued ({$type}): no booking details for the message.");
                $pdo->exec('RELEASE SAVEPOINT customer_sms');
            } catch (Throwable $e) {
                try { $pdo->exec('ROLLBACK TO SAVEPOINT customer_sms'); } catch (Throwable $ignored) {}
                error_log('Customer SMS could not be queued: ' . $e->getMessage());
            }
        }
        if ($ownsTransaction) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT customer_notification');
        return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                if ($ownsTransaction) $pdo->rollBack();
                else { $pdo->exec('ROLLBACK TO SAVEPOINT customer_notification'); $pdo->exec('RELEASE SAVEPOINT customer_notification'); }
            }
            error_log('Customer notification could not be queued: ' . $e->getMessage());
            return false;
        }
    }

    private static function bookingDetails(PDO $pdo, int $customerId, string $reference): ?array
    {
        $stmt = $pdo->prepare("SELECT a.reference_code, a.appointment_datetime AS scheduled, a.status, a.payment_status,
            br.branch_name, (SELECT GROUP_CONCAT(s.service_name ORDER BY aps.id SEPARATOR ', ') FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = a.id) AS services
            FROM appointments a LEFT JOIN branches br ON br.id = a.branch_id WHERE a.reference_code = ? AND a.customer_id = ? LIMIT 1");
        $stmt->execute([$reference,$customerId]); $row = $stmt->fetch(); $home = false;
        if (!$row) {
            $stmt = $pdo->prepare("SELECT h.reference_code, CONCAT(h.preferred_date, ' ', COALESCE(h.preferred_time,'09:00:00')) AS scheduled,
                h.status, h.payment_status, COALESCE(br.branch_name,'Home / Event Service (off-site)') AS branch_name,
                CONCAT(h.event_type, IF(h.wedding_package IS NULL,'',CONCAT(' — Package ',h.wedding_package))) AS services, h.contact_email
                FROM home_service_requests h LEFT JOIN branches br ON br.id = h.branch_id WHERE h.reference_code = ? AND h.customer_id = ? LIMIT 1");
            $stmt->execute([$reference,$customerId]); $row = $stmt->fetch(); $home = true;
        }
        if (!$row) return null;
        $when = new DateTimeImmutable($row['scheduled'],new DateTimeZone('Asia/Manila'));
        return ['homeService'=>$home,'scheduled'=>$row['scheduled'],'contactEmail'=>$row['contact_email']??null,'paymentStatus'=>$row['payment_status'],
            'text'=>"Booking reference: {$row['reference_code']}\nService / Event: ".($row['services'] ?: 'Service details available in your booking').
            "\nScheduled time: ".$when->format('Y-m-d H:i')." (Philippine time)\nAppointment: ".$when->format('l, F j, Y, g:i A').
            "\nBranch: {$row['branch_name']}\nBooking status: {$row['status']}\nPayment status: {$row['payment_status']}"];
    }
}
