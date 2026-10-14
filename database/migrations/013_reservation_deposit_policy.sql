-- Migration 013: reservation deposit/payment policy
-- Panel feedback: an unpaid (Pending) reservation should stay soft/adjustable,
-- but once staff record a deposit and confirm it, that reservation is "locked"
-- and takes priority over any other unpaid claim on the same stylist/time.
-- These columns live on `appointments`, not `payments` -- `payments` is a
-- strict 1:1-per-appointment, all-or-nothing checkout record (see
-- backend/cashier/payment.php / backend/admin/completeCheckout.php) and a
-- partial deposit doesn't fit that shape.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `deposit_paid` BOOLEAN NOT NULL DEFAULT FALSE AFTER `total_price`,
  ADD COLUMN IF NOT EXISTS `deposit_amount` DECIMAL(10,2) NULL AFTER `deposit_paid`,
  ADD COLUMN IF NOT EXISTS `deposit_method` VARCHAR(30) NULL AFTER `deposit_amount`,
  ADD COLUMN IF NOT EXISTS `deposit_recorded_by` INT UNSIGNED NULL AFTER `deposit_method`,
  ADD COLUMN IF NOT EXISTS `deposit_recorded_at` TIMESTAMP NULL AFTER `deposit_recorded_by`;
ALTER TABLE `appointments`
  ADD INDEX IF NOT EXISTS `fk_appointments_deposit_recorded_by_idx` (`deposit_recorded_by` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_appointments_deposit_recorded_by');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_deposit_recorded_by` FOREIGN KEY (`deposit_recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
