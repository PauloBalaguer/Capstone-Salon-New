<?php

require_once __DIR__ . '/EmailOutbox.php';

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
 * Reminders are excluded because backend/cron/sendAppointmentReminders.php
 * already emails those itself.
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
        'RESCHEDULE' => 'Your appointment needs a schedule change',
        'CANCELLED' => 'Your appointment was cancelled',
        'COMPLETED' => 'Thank you for visiting us',
        'WAITLIST_OPENING' => 'Your preferred stylist has an opening',
        'QUOTE_READY' => 'Your home service quote is ready — pay to confirm',
    ];

    public static function notify(PDO $pdo, int $customerId, string $type, string $message): void
    {
        $stmt = $pdo->prepare('
            SELECT c.user_id, c.notify_email, COALESCE(u.email, c.email) AS email
            FROM customers c LEFT JOIN users u ON u.id = c.user_id
            WHERE c.id = ? LIMIT 1
        ');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            return;
        }

        if ($customer['user_id']) {
            $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)')
                ->execute([$customer['user_id'], $type, $message]);
        }

        $reference = preg_match('/\bLM-[A-Z]+-\d{4}-\d+\b/', $message, $m) ? $m[0] : null;
        // A home service message goes to the email given on that request
        // (home_service_requests.contact_email) -- guests track, pay and
        // reschedule with it, and their shared customer record may have none.
        if ($reference && str_starts_with($reference, 'LM-HOM-')) {
            $stmt = $pdo->prepare('SELECT contact_email FROM home_service_requests WHERE reference_code = ? LIMIT 1');
            $stmt->execute([$reference]);
            $contactEmail = $stmt->fetchColumn();
            if ($contactEmail) $customer['email'] = $contactEmail;
        }

        if (isset(self::EMAIL_SUBJECTS[$type]) && $customer['email'] && (int) $customer['notify_email'] === 1) {
            $body = $message . ($customer['user_id']
                ? "\n\nYou can view your appointment details anytime in your Leo Mejillano account."
                : ($reference && str_starts_with($reference, 'LM-HOM-')
                    ? "\n\nTrack, pay or reschedule anytime in Track Your Home Service (Home & Events) on our website using reference {$reference} and this email address."
                    : "\n\nYou can check your booking anytime with Track a Booking on our website" . ($reference ? " using reference {$reference}." : '.')));
            EmailOutbox::queue($pdo, $customer['email'], self::EMAIL_SUBJECTS[$type] . ($reference ? " ({$reference})" : ''), $body, $reference);
        }
    }
}
