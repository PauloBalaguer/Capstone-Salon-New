-- SMS notifications through the textbee.dev gateway.
-- 1. customers.notify_sms is the customer's SMS opt-in (already added by 002;
--    repeated here so this migration stands on its own).
-- 2. sms_outbox queues texts inside the same transaction as the booking
--    change, like email_outbox. backend/shared/cron/sendQueuedSms.php sends
--    them; rows with status 'Sent' and today's sent_at make the daily cap.

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `notify_sms` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notify_email`;

CREATE TABLE IF NOT EXISTS `sms_outbox` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_phone` VARCHAR(20) NOT NULL,
  `type` VARCHAR(40) NOT NULL,
  `message` VARCHAR(500) NOT NULL,
  `reference_code` VARCHAR(40) NULL,
  `status` ENUM('Pending', 'Sent', 'Failed') NOT NULL DEFAULT 'Pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_sms_outbox_status` (`status`, `attempts`),
  INDEX `idx_sms_outbox_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
