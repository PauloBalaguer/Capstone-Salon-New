-- Migration: real backend support for the customer dashboard.
-- Adds branches, per-branch service/staff/promotion data, home service
-- requests, and the extra columns the customer UI already expects
-- (loyalty points, notification prefs, profile picture, booking reference,
-- widened appointment status vocabulary).

CREATE TABLE IF NOT EXISTS `branches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_key` VARCHAR(30) NOT NULL,
  `branch_name` VARCHAR(150) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_branch_key_unique` (`branch_key` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `home_service_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `address` VARCHAR(500) NOT NULL,
  `event_type` VARCHAR(150) NOT NULL,
  `preferred_date` DATE NOT NULL,
  `requests` TEXT NULL,
  `status` ENUM('Pending Review','Confirmed','Completed','Cancelled') NOT NULL DEFAULT 'Pending Review',
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_home_service_requests_customers_idx` (`customer_id` ASC),
  CONSTRAINT `fk_home_service_requests_customers`
    FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- services: attach to a branch, add a display category + friendly duration label
ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL AFTER `service_name`,
  ADD COLUMN IF NOT EXISTS `duration_label` VARCHAR(50) NULL AFTER `duration_minutes`;

ALTER TABLE `services`
  ADD INDEX IF NOT EXISTS `fk_services_branches_idx` (`branch_id` ASC);

-- employees: attach staff to a branch
ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `user_id`;

ALTER TABLE `employees`
  ADD INDEX IF NOT EXISTS `fk_employees_branches_idx` (`branch_id` ASC);

-- customers: loyalty points, notification prefs, profile picture
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `loyalty_points` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `notify_email` BOOLEAN NOT NULL DEFAULT TRUE AFTER `loyalty_points`,
  ADD COLUMN IF NOT EXISTS `notify_sms` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_email`,
  ADD COLUMN IF NOT EXISTS `profile_picture` LONGTEXT NULL AFTER `notify_sms`;

-- promotions: link to a specific branch + service, add display price fields
ALTER TABLE `promotions`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `service_id` INT UNSIGNED NULL AFTER `branch_id`,
  ADD COLUMN IF NOT EXISTS `title` VARCHAR(200) NULL AFTER `service_id`,
  ADD COLUMN IF NOT EXISTS `price` DECIMAL(10,2) NULL AFTER `discount_value`,
  ADD COLUMN IF NOT EXISTS `original_price` DECIMAL(10,2) NULL AFTER `price`;

ALTER TABLE `promotions`
  ADD INDEX IF NOT EXISTS `fk_promotions_branches_idx` (`branch_id` ASC),
  ADD INDEX IF NOT EXISTS `fk_promotions_services_idx` (`service_id` ASC);

-- appointments: branch link, booking reference, final price, reminder flag,
-- and a status vocabulary that matches the customer dashboard UI
ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `reference_code` VARCHAR(30) NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `customer_id`,
  ADD COLUMN IF NOT EXISTS `total_price` DECIMAL(10,2) NULL AFTER `appointment_datetime`,
  ADD COLUMN IF NOT EXISTS `reminder_sent` BOOLEAN NOT NULL DEFAULT FALSE AFTER `total_price`;

ALTER TABLE `appointments`
  MODIFY COLUMN `status` ENUM('Pending','Confirmed','In Progress','Completed','Cancelled','Reviewed','No-Show') NOT NULL DEFAULT 'Pending';

ALTER TABLE `appointments`
  ADD UNIQUE INDEX IF NOT EXISTS `idx_reference_code_unique` (`reference_code` ASC),
  ADD INDEX IF NOT EXISTS `fk_appointments_branches_idx` (`branch_id` ASC);

-- Foreign keys added last so the referenced/added columns above already exist.
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_services_branches');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `services` ADD CONSTRAINT `fk_services_branches` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_employees_branches');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `employees` ADD CONSTRAINT `fk_employees_branches` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_promotions_branches');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `promotions` ADD CONSTRAINT `fk_promotions_branches` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_promotions_services');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `promotions` ADD CONSTRAINT `fk_promotions_services` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_appointments_branches');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_branches` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
