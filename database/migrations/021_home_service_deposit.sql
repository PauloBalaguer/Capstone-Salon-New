-- Migration 021: reservation deposit required before viewing the schedule
-- (Home Service requests)
--
-- Same policy as migration 020 for salon appointments, applied to
-- home_service_requests: a guest (index.html's Home Service wizard) or
-- logged-in customer (pages/customer/home-service.html) must submit a
-- reservation deposit before they can pick a preferred date. Since home
-- service has no per-item catalog price at request time (quotes are
-- confirmed after review), the deposit is a flat fee rather than a
-- percentage -- see HOME_SERVICE_DEPOSIT in the PHP submission endpoints.
-- Same self-report-then-staff-verifies model: deposit_recorded_by IS NULL
-- means "self-reported, not yet verified" (see backend/admin/updateBookingStatus.php).

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `deposit_paid` BOOLEAN NOT NULL DEFAULT FALSE AFTER `preferred_date`,
  ADD COLUMN IF NOT EXISTS `deposit_amount` DECIMAL(10,2) NULL AFTER `deposit_paid`,
  ADD COLUMN IF NOT EXISTS `deposit_method` VARCHAR(30) NULL AFTER `deposit_amount`,
  ADD COLUMN IF NOT EXISTS `deposit_reference` VARCHAR(100) NULL AFTER `deposit_method`,
  ADD COLUMN IF NOT EXISTS `deposit_recorded_by` INT UNSIGNED NULL AFTER `deposit_reference`,
  ADD COLUMN IF NOT EXISTS `deposit_recorded_at` TIMESTAMP NULL AFTER `deposit_recorded_by`;
ALTER TABLE `home_service_requests`
  ADD INDEX IF NOT EXISTS `fk_home_service_deposit_recorded_by_idx` (`deposit_recorded_by` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_home_service_deposit_recorded_by');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `home_service_requests` ADD CONSTRAINT `fk_home_service_deposit_recorded_by` FOREIGN KEY (`deposit_recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
