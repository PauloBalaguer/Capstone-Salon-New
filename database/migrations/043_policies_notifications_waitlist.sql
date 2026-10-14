-- Migration 043: cancellation policy, payment plans, email outbox,
-- online payment log, and stylist waitlist
--
-- 1. appointments.payment_status gains 'Refund Due': a verified/online
--    deposit on a booking cancelled early enough (see
--    backend/config/CancellationPolicy.php) is owed back to the customer
--    until staff mark it 'Refunded'. Late cancellations and no-shows move a
--    held deposit to 'Forfeited' automatically.
-- 2. appointments.payment_plan records whether the customer chose to pay a
--    deposit (minimum ₱100) or the full amount upfront.
-- 3. customers.email stores a guest's (OTP-verified) email so booking and
--    status emails can reach guests too; registered customers use users.email.
-- 4. email_outbox queues customer emails inside the same transaction as the
--    booking change they describe (rolled back together), then sends them
--    after the request (backend/config/EmailOutbox.php). Doubles as the
--    email send log.
-- 5. payment_transactions is the traceable log of every online payment
--    event: checkout created, paid, expired, webhook received/rejected,
--    deposit forfeited / refund due, API errors.
-- 6. stylist_waitlist lets a customer ask to be notified when a fully booked
--    stylist frees up on a given date (backend/config/Waitlist.php).

ALTER TABLE `appointments`
  MODIFY COLUMN `payment_status` ENUM('Payment Required', 'Awaiting Verification', 'Down Payment Verified', 'Fully Paid', 'Rejected', 'Refund Due', 'Refunded', 'Forfeited') NOT NULL DEFAULT 'Payment Required';

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `payment_plan` ENUM('deposit', 'full') NOT NULL DEFAULT 'deposit' AFTER `reservation_amount_due`,
  ADD COLUMN IF NOT EXISTS `cancelled_at` TIMESTAMP NULL AFTER `payment_plan`;

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL AFTER `phone_number`;

CREATE TABLE IF NOT EXISTS `email_outbox` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `reference_code` VARCHAR(40) NULL,
  `status` ENUM('Pending', 'Sent', 'Failed') NOT NULL DEFAULT 'Pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_email_outbox_status` (`status`, `attempts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appointment_id` INT UNSIGNED NULL,
  `reference_code` VARCHAR(40) NULL,
  `provider` VARCHAR(20) NOT NULL DEFAULT 'PayMongo',
  `event` VARCHAR(40) NOT NULL,
  `external_id` VARCHAR(100) NULL,
  `amount` DECIMAL(10,2) NULL,
  `status` VARCHAR(30) NULL,
  `details` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_payment_transactions_reference` (`reference_code`),
  INDEX `idx_payment_transactions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stylist_waitlist` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `branch_id` INT UNSIGNED NOT NULL,
  `desired_date` DATE NOT NULL,
  `desired_time` VARCHAR(20) NULL,
  `customer_id` INT UNSIGNED NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(30) NULL,
  `status` ENUM('Waiting', 'Notified', 'Cancelled') NOT NULL DEFAULT 'Waiting',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notified_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_waitlist_lookup` (`employee_id`, `desired_date`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
