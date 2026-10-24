<?php

/**
 * Cron: Send Queued SMS
 *
 * Sends up to 10 pending texts from sms_outbox (queued by CustomerNotifier),
 * about a second apart, and retries failed ones up to SmsOutbox::MAX_ATTEMPTS
 * times. Does nothing between 9 PM and 7 AM or once the daily cap is hit
 * (see backend/shared/config/notifications/SmsOutbox.php). Run every 2 minutes
 * from Windows Task Scheduler. Not web-exposed.
 *
 * Usage:
 *   php backend/shared/cron/sendQueuedSms.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../config/setup/database.php';
require_once __DIR__ . '/../config/notifications/SmsOutbox.php';

$sent = SmsOutbox::flush(Database::getInstance());
echo "Sent {$sent} SMS.\n";
