-- Migration 011: public tracking + feedback for guest requests
-- Gives home_service_requests a reference_code (mirrors appointments) so
-- guests can look up a home & event request the same way they look up a
-- salon appointment, and lets `feedback` attach to either an appointment
-- or a home_service_requests row (a guest home-service request has no
-- appointment_id to hang feedback off of). Exactly one of the two FKs is
-- set per feedback row; enforced in application code (see
-- backend/public/submitGuestFeedback.php).

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `reference_code` VARCHAR(30) NULL AFTER `id`,
  ADD UNIQUE INDEX IF NOT EXISTS `idx_home_service_reference_code_unique` (`reference_code` ASC);

ALTER TABLE `feedback`
  MODIFY COLUMN `appointment_id` INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `home_service_request_id` INT UNSIGNED NULL AFTER `appointment_id`,
  ADD UNIQUE INDEX IF NOT EXISTS `idx_home_service_request_id_unique` (`home_service_request_id` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_feedback_home_service_requests');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `feedback` ADD CONSTRAINT `fk_feedback_home_service_requests` FOREIGN KEY (`home_service_request_id`) REFERENCES `home_service_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
