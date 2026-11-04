<?php

/**
 * Cron: Send Queued Emails
 *
 * Customer emails (booking received, status changes, waitlist openings) are
 * normally sent right after the request that queued them (see
 * backend/shared/config/notifications/EmailOutbox.php). This retries anything still Pending --
 * e.g. when the Brevo API was briefly unreachable -- up to
 * EmailOutbox::MAX_ATTEMPTS times. Run every few minutes from Windows Task
 * Scheduler alongside sendAppointmentReminders.php. Not web-exposed.
 *
 * Usage:
 *   php backend/shared/cron/sendQueuedEmails.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../config/setup/database.php';
require_once __DIR__ . '/../config/notifications/EmailOutbox.php';

$sent = EmailOutbox::flush(Database::getInstance(), 100);
echo "Sent {$sent} email(s).\n";
