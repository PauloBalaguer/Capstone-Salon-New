-- MySQL Script for Salon Management System
SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_name` VARCHAR(50) NOT NULL,
  `description` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_role_name_unique` (`role_name` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `otp_code` VARCHAR(10) NULL,
  `otp_expiry` TIMESTAMP NULL,
  `last_login` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_email_unique` (`email` ASC),
  INDEX `fk_users_roles_idx` (`role_id` ASC),
  CONSTRAINT `fk_users_roles`
    FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- Existing databases created before email verification need these columns.
-- Fresh databases already have them above, so these statements are no-ops.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `otp_code` VARCHAR(10) NULL AFTER `is_active`;
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `otp_expiry` TIMESTAMP NULL AFTER `otp_code`;

CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `address` TEXT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_phone_number_unique` (`phone_number` ASC),
  INDEX `fk_customers_users_idx` (`user_id` ASC),
  UNIQUE INDEX `idx_user_id_unique` (`user_id` ASC),
  CONSTRAINT `fk_customers_users`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `employees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `position` VARCHAR(100) NULL,
  `hire_date` DATE NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_phone_number_unique` (`phone_number` ASC),
  INDEX `fk_employees_users_idx` (`user_id` ASC),
  UNIQUE INDEX `idx_user_id_unique` (`user_id` ASC),
  CONSTRAINT `fk_employees_users`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `duration_minutes` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `appointments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `appointment_datetime` DATETIME NOT NULL,
  `status` ENUM('Scheduled', 'Completed', 'Cancelled', 'No-Show') NOT NULL DEFAULT 'Scheduled',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_appointments_customers_idx` (`customer_id` ASC),
  INDEX `fk_appointments_employees_idx` (`employee_id` ASC),
  INDEX `idx_appointment_datetime` (`appointment_datetime` ASC),
  CONSTRAINT `fk_appointments_customers`
    FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_appointments_employees`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `appointment_services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appointment_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `fk_appointment_services_appointments_idx` (`appointment_id` ASC),
  INDEX `fk_appointment_services_services_idx` (`service_id` ASC),
  UNIQUE INDEX `idx_appointment_service_unique` (`appointment_id` ASC, `service_id` ASC),
  CONSTRAINT `fk_appointment_services_appointments`
    FOREIGN KEY (`appointment_id`)
    REFERENCES `appointments` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_appointment_services_services`
    FOREIGN KEY (`service_id`)
    REFERENCES `services` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appointment_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('Cash', 'Card', 'Online', 'Gift Card') NOT NULL,
  `payment_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `transaction_id` VARCHAR(255) NULL,
  `status` ENUM('Paid', 'Pending', 'Refunded') NOT NULL DEFAULT 'Paid',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_payments_appointments_idx` (`appointment_id` ASC),
  UNIQUE INDEX `idx_appointment_id_unique` (`appointment_id` ASC),
  CONSTRAINT `fk_payments_appointments`
    FOREIGN KEY (`appointment_id`)
    REFERENCES `appointments` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `inventory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `sku` VARCHAR(100) NULL,
  `quantity_on_hand` INT NOT NULL DEFAULT 0,
  `reorder_level` INT NOT NULL DEFAULT 0,
  `supplier` VARCHAR(255) NULL,
  `cost_price` DECIMAL(10,2) NOT NULL,
  `sale_price` DECIMAL(10,2) NULL,
  `type` ENUM('Retail', 'Professional Use') NOT NULL,
  `last_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_sku_unique` (`sku` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `clock_in_time` DATETIME NOT NULL,
  `clock_out_time` DATETIME NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  INDEX `fk_attendance_employees_idx` (`employee_id` ASC),
  INDEX `idx_clock_in_time` (`clock_in_time` ASC),
  CONSTRAINT `fk_attendance_employees`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `feedback` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appointment_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `comments` TEXT NULL,
  `is_public` BOOLEAN NOT NULL DEFAULT FALSE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_feedback_appointments_idx` (`appointment_id` ASC),
  UNIQUE INDEX `idx_appointment_id_unique` (`appointment_id` ASC),
  INDEX `fk_feedback_customers_idx` (`customer_id` ASC),
  CONSTRAINT `fk_feedback_appointments`
    FOREIGN KEY (`appointment_id`)
    REFERENCES `appointments` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_feedback_customers`
    FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `promotions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `promo_code` VARCHAR(50) NOT NULL,
  `description` TEXT NOT NULL,
  `discount_type` ENUM('Percentage', 'Fixed Amount') NOT NULL,
  `discount_value` DECIMAL(10,2) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_promo_code_unique` (`promo_code` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` BOOLEAN NOT NULL DEFAULT FALSE,
  `type` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_notifications_users_idx` (`user_id` ASC),
  INDEX `idx_is_read` (`is_read` ASC),
  CONSTRAINT `fk_notifications_users`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `rate_limit` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(100) NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_identifier_type_created` (`identifier` ASC, `type` ASC, `created_at` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `email` VARCHAR(255) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`email`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- Guest booking email verification (see database/migrations/010_booking_otp.sql
-- for the idempotent, already-applied version of this migration).
CREATE TABLE IF NOT EXISTS `booking_verifications` (
  `email` VARCHAR(255) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`email`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

INSERT INTO `roles` (`role_name`, `description`) VALUES
  ('Customer', 'Salon customer with booking and profile access.'),
  ('Admin', 'Manages appointments, customers, employees, and settings.'),
  ('Cashier', 'Handles payments and receipts.'),
  ('Staff', 'Salon staff with assigned appointments and attendance access.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Customer dashboard backend support (see database/migrations/002_customer_backend.sql
-- for the idempotent, already-applied version of this migration).

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

ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL AFTER `service_name`,
  ADD COLUMN IF NOT EXISTS `duration_label` VARCHAR(50) NULL AFTER `duration_minutes`;
ALTER TABLE `services`
  ADD INDEX IF NOT EXISTS `fk_services_branches_idx` (`branch_id` ASC);

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `user_id`;
ALTER TABLE `employees`
  ADD INDEX IF NOT EXISTS `fk_employees_branches_idx` (`branch_id` ASC);

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `profile_picture` LONGTEXT NULL AFTER `phone_number`;

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `loyalty_points` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `notify_email` BOOLEAN NOT NULL DEFAULT TRUE AFTER `loyalty_points`,
  ADD COLUMN IF NOT EXISTS `notify_sms` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_email`,
  ADD COLUMN IF NOT EXISTS `profile_picture` LONGTEXT NULL AFTER `notify_sms`;

ALTER TABLE `promotions`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `service_id` INT UNSIGNED NULL AFTER `branch_id`,
  ADD COLUMN IF NOT EXISTS `title` VARCHAR(200) NULL AFTER `service_id`,
  ADD COLUMN IF NOT EXISTS `price` DECIMAL(10,2) NULL AFTER `discount_value`,
  ADD COLUMN IF NOT EXISTS `original_price` DECIMAL(10,2) NULL AFTER `price`;
ALTER TABLE `promotions`
  ADD INDEX IF NOT EXISTS `fk_promotions_branches_idx` (`branch_id` ASC),
  ADD INDEX IF NOT EXISTS `fk_promotions_services_idx` (`service_id` ASC);

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

-- Admin panel backend support (see database/migrations/003_admin_backend.sql
-- for the idempotent, already-applied version of this migration).

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(150) NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `notify_booking` BOOLEAN NOT NULL DEFAULT TRUE AFTER `display_name`,
  ADD COLUMN IF NOT EXISTS `notify_inventory` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_booking`,
  ADD COLUMN IF NOT EXISTS `notify_order` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_inventory`,
  ADD COLUMN IF NOT EXISTS `notify_marketing` BOOLEAN NOT NULL DEFAULT FALSE AFTER `notify_order`;

-- Optional PIN for the internal portal's quick-login (pages/portal-login/),
-- hashed the same way as `password`. NULL until the account holder sets one.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `pin_hash` VARCHAR(255) NULL AFTER `password`;

ALTER TABLE `branches`
  ADD COLUMN IF NOT EXISTS `location` VARCHAR(255) NULL AFTER `branch_name`,
  ADD COLUMN IF NOT EXISTS `branch_type` VARCHAR(100) NULL AFTER `location`;

ALTER TABLE `inventory`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `id`;
ALTER TABLE `inventory`
  ADD INDEX IF NOT EXISTS `fk_inventory_branches_idx` (`branch_id` ASC);

ALTER TABLE `appointments`
  MODIFY COLUMN `employee_id` INT UNSIGNED NULL;

ALTER TABLE `notifications`
  MODIFY COLUMN `user_id` INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS `supplier_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `expected_date` DATE NOT NULL,
  `status` ENUM('Ordered', 'Shipped', 'Received') NOT NULL DEFAULT 'Ordered',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_supplier_orders_inventory_idx` (`inventory_id` ASC),
  CONSTRAINT `fk_supplier_orders_inventory`
    FOREIGN KEY (`inventory_id`)
    REFERENCES `inventory` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_inventory_branches');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `inventory` ADD CONSTRAINT `fk_inventory_branches` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed: one Admin dev login so the panel is reachable.
-- Password: Admin@2026 (bcrypt hash pre-computed below).
INSERT INTO `users` (`role_id`, `email`, `password`, `display_name`)
SELECT r.id, 'admin@leomejillanosalon.ph', '$2y$10$X8UwAGD8psjkusfHxJlIMeXJLGOt/ortWp8Ja0..Sv3buhGYXJ6m2', 'Platform Administrator'
FROM `roles` r WHERE r.role_name = 'Admin'
AND NOT EXISTS (SELECT 1 FROM `users` WHERE `email` = 'admin@leomejillanosalon.ph');

-- Cashier hub backend support (see database/migrations/006_cashier_backend.sql
-- for the idempotent, already-applied version of this migration).

INSERT INTO `branches` (`branch_key`, `branch_name`, `location`, `branch_type`)
VALUES
  ('daraga', 'Leo Mejillano Salon & Makeup Studio', 'Daraga, Albay', 'Salon & Makeup Studio'),
  ('yashano', 'Skin Brows by Leo Mejillano', 'Yashano Mall', 'Skin & Brows Studio'),
  ('cabangan', 'Lash & Brows by Leo Mejillano Salon', 'Cabangan', 'Lash & Brows Studio')
ON DUPLICATE KEY UPDATE `branch_name` = VALUES(`branch_name`);

ALTER TABLE `inventory`
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL AFTER `product_name`,
  ADD COLUMN IF NOT EXISTS `max_stock` INT UNSIGNED NOT NULL DEFAULT 100 AFTER `reorder_level`;

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `shift_status` ENUM('On Duty', 'With Client', 'Off Shift') NOT NULL DEFAULT 'Off Shift' AFTER `is_active`;

ALTER TABLE `payments`
  MODIFY COLUMN `payment_method` ENUM('Cash', 'Card', 'Online', 'Gift Card', 'GCash', 'Maya') NOT NULL,
  ADD COLUMN IF NOT EXISTS `tip_amount` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `processed_by` INT UNSIGNED NULL AFTER `status`;

ALTER TABLE `payments`
  ADD INDEX IF NOT EXISTS `fk_payments_users_idx` (`processed_by` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_payments_users');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_users` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `payment_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` INT UNSIGNED NOT NULL,
  `item_type` ENUM('Service', 'Product') NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `line_total` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `fk_payment_items_payments_idx` (`payment_id` ASC),
  CONSTRAINT `fk_payment_items_payments`
    FOREIGN KEY (`payment_id`)
    REFERENCES `payments` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `inventory_adjustments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_id` INT UNSIGNED NOT NULL,
  `adjustment_type` ENUM('add', 'sub') NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `previous_stock` INT NOT NULL,
  `new_stock` INT NOT NULL,
  `adjusted_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_inventory_adjustments_inventory_idx` (`inventory_id` ASC),
  INDEX `fk_inventory_adjustments_users_idx` (`adjusted_by` ASC),
  CONSTRAINT `fk_inventory_adjustments_inventory`
    FOREIGN KEY (`inventory_id`)
    REFERENCES `inventory` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_adjustments_users`
    FOREIGN KEY (`adjusted_by`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

CREATE TABLE IF NOT EXISTS `daily_closures` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `closed_by` INT UNSIGNED NULL,
  `invoice_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `gross_revenue` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `closed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_daily_closures_branches_idx` (`branch_id` ASC),
  INDEX `fk_daily_closures_users_idx` (`closed_by` ASC),
  CONSTRAINT `fk_daily_closures_branches`
    FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_daily_closures_users`
    FOREIGN KEY (`closed_by`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- Cashier branch-lock (see database/migrations/007_cashier_branch_lock.sql
-- for the idempotent, already-applied version of this migration).

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `role_id`;
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `fk_users_branches_idx` (`branch_id` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_users_branches');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `users` ADD CONSTRAINT `fk_users_branches` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Public tracking + feedback for guest requests (see
-- database/migrations/011_public_tracking.sql for the idempotent,
-- already-applied version of this migration).

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

-- Staff-service specialization (see
-- database/migrations/012_staff_service_specialization.sql for the
-- idempotent, already-applied version of this migration). Required by the
-- customer/guest/cashier booking flows, which restrict staff assignment to
-- employees qualified for every selected service.

CREATE TABLE IF NOT EXISTS `staff_services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_staff_services_employees_idx` (`employee_id` ASC),
  INDEX `fk_staff_services_services_idx` (`service_id` ASC),
  UNIQUE INDEX `idx_staff_service_unique` (`employee_id` ASC, `service_id` ASC),
  CONSTRAINT `fk_staff_services_employees`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_services_services`
    FOREIGN KEY (`service_id`)
    REFERENCES `services` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- Reservation deposit/payment policy (see
-- database/migrations/013_reservation_deposit_policy.sql for the
-- idempotent, already-applied version of this migration).

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

-- Email OTP verification for in-app password changes (see
-- database/migrations/016_password_change_verification.sql for the
-- idempotent, already-applied version of this migration).

CREATE TABLE IF NOT EXISTS `password_change_verifications` (
  `user_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_password_change_verifications_users`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- Preferred payment method at booking time (see
-- database/migrations/017_preferred_payment_method.sql for the idempotent,
-- already-applied version of this migration).

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `preferred_payment_method` VARCHAR(30) NULL AFTER `total_price`;

-- Admin-managed wedding packages (see
-- database/migrations/018_wedding_packages.sql for the idempotent,
-- already-applied version of this migration).

CREATE TABLE IF NOT EXISTS `wedding_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `reservation_fee` DECIMAL(10,2) NULL,
  `features` TEXT NOT NULL,
  `style` ENUM('Plain','Highlight','Premium') NOT NULL DEFAULT 'Plain',
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_wedding_package_name_unique` (`package_name` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

INSERT INTO `wedding_packages` (`package_name`, `price`, `reservation_fee`, `features`, `style`, `display_order`, `is_active`) VALUES
('Package A', 5000.00, NULL,
  'Hairstyle and traditional make-up for the bride prep look and ceremony look, with unlimited touch-up until she leaves the hotel for the ceremony.\nGrooming for the groom (only if he is at the same preparation venue).\nHairstyle and traditional make-up for 2 heads.',
  'Plain', 1, TRUE),
('Package B', 8000.00, NULL,
  'Hairstyle and airbrush make-up for the bride prep look and ceremony look, with unlimited touch-up until she leaves the hotel for the ceremony.\nGrooming for the groom (only if he is at the same preparation venue).\nHairstyle and traditional make-up for 2 heads.',
  'Plain', 2, TRUE),
('Package C', 10000.00, NULL,
  'Hairstyle and airbrush make-up for the bride prep look and ceremony look, with unlimited touch-up until she leaves the hotel for the ceremony.\nReception look / change look for the bride.\nGrooming for the groom (only if he is at the same preparation venue).\nHairstyle and traditional make-up for 2 heads.',
  'Highlight', 3, TRUE),
('Package D', 12000.00, 2000.00,
  'Hairstyle and airbrush make-up for the bride prep look and ceremony look, with unlimited touch-up until she leaves the hotel for the ceremony.\nReception look / change look for the bride.\nGrooming for the groom (only if he is at the same preparation venue).\nHairstyle and traditional make-up for 7 heads.',
  'Premium', 4, TRUE)
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);

-- Per-service loyalty weighting / customer scoring (see
-- database/migrations/019_customer_scoring.sql for the idempotent,
-- already-applied version of this migration).

ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `loyalty_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER `price`;

-- Reservation deposit required before viewing the schedule (see
-- database/migrations/020_deposit_before_schedule.sql for the idempotent,
-- already-applied version of this migration).

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `deposit_reference` VARCHAR(100) NULL AFTER `deposit_method`;

-- Reservation deposit required before viewing the schedule -- Home Service
-- requests (see database/migrations/021_home_service_deposit.sql for the
-- idempotent, already-applied version of this migration).

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

-- Per-service reservation payment requirement, shown on the customer
-- Services & Promos page (see database/migrations/022_service_payment_requirement.sql
-- for the idempotent, already-applied version of this migration). Existing
-- rows default to 'Half Payment' (the salon's standard 20%-of-total deposit
-- policy); higher-commitment services (bridal/wedding-type) are flipped to
-- 'Full Payment' below as a one-time seed default -- adjust per-service via
-- the services table as needed.

ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `payment_requirement` ENUM('Half Payment', 'Full Payment') NOT NULL DEFAULT 'Half Payment' AFTER `loyalty_multiplier`;

UPDATE `services`
  SET `payment_requirement` = 'Full Payment'
  WHERE `category` IN ('Bridal', 'Wedding')
     OR `service_name` LIKE '%Bridal%'
     OR `service_name` LIKE '%Wedding%';

-- Separate appointment status from payment status (see
-- database/migrations/023_appointment_payment_status.sql for the
-- idempotent, already-applied version of this migration).
--
-- Previously the only signal for "has the deposit been checked" was the
-- boolean `deposit_recorded_by IS NOT NULL`, and the appointment status
-- enum had no room for a reschedule conversation. These were never
-- customer-settable -- only backend/admin/updateBookingStatus.php and
-- backend/cashier/updateStatus.php write either column, both gated by
-- role checks -- but they were also not independently modeled. Two
-- statuses now exist in parallel:
--   appointments.status         -- the appointment lifecycle
--   appointments.payment_status -- the deposit/payment lifecycle
-- `payment_status` defaults to 'Payment Required' for schema completeness,
-- though every current booking path collects a deposit before the row is
-- even created, so new rows are inserted straight into 'Awaiting
-- Verification' (see submitBooking.php/submitGuestBooking.php et al).

ALTER TABLE `appointments`
  MODIFY COLUMN `status` ENUM('Pending', 'Confirmed', 'In Progress', 'Completed', 'Cancelled', 'Reviewed', 'No-Show', 'Reschedule Requested', 'Reschedule Required') NOT NULL DEFAULT 'Pending';

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `payment_status` ENUM('Payment Required', 'Awaiting Verification', 'Down Payment Verified', 'Fully Paid', 'Rejected', 'Refunded', 'Forfeited') NOT NULL DEFAULT 'Payment Required' AFTER `deposit_recorded_at`;

UPDATE `appointments`
  SET `payment_status` = CASE
    WHEN `deposit_recorded_by` IS NOT NULL AND `status` = 'Completed' THEN 'Fully Paid'
    WHEN `deposit_recorded_by` IS NOT NULL THEN 'Down Payment Verified'
    WHEN `deposit_paid` = 1 THEN 'Awaiting Verification'
    ELSE 'Payment Required'
  END
  WHERE `payment_status` = 'Payment Required';

-- Home service requests carry the same deposit-verification pattern, so
-- they get the same payment_status column (there is no separate line-item
-- "services" catalog for a home service request, so its Confirmed
-- transition always resolves to 'Down Payment Verified', never
-- 'Fully Paid' -- see backend/admin/updateBookingStatus.php).
ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `payment_status` ENUM('Payment Required', 'Awaiting Verification', 'Down Payment Verified', 'Fully Paid', 'Rejected', 'Refunded', 'Forfeited') NOT NULL DEFAULT 'Payment Required' AFTER `deposit_recorded_at`;

UPDATE `home_service_requests`
  SET `payment_status` = CASE
    WHEN `deposit_recorded_by` IS NOT NULL THEN 'Down Payment Verified'
    WHEN `deposit_paid` = 1 THEN 'Awaiting Verification'
    ELSE 'Payment Required'
  END
  WHERE `payment_status` = 'Payment Required';

SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;

-- Public booking references (migration 024).
-- Step 5. MariaDB 10.3+ (the project's XAMPP database).
-- Run with the application paused. Keep existing public references valid.
-- The global sequence is independent of booking primary keys and never cycles.
-- Sequence values survive rollback; gaps are intentional. Never reset it or
-- purge the registry, including when deleting/cancelling bookings.
CREATE SEQUENCE IF NOT EXISTS booking_reference_sequence START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE TABLE IF NOT EXISTS booking_reference_registry (
  reference_code VARCHAR(48) NOT NULL PRIMARY KEY,
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DELIMITER //
CREATE OR REPLACE FUNCTION next_booking_reference(branch_id_value INT UNSIGNED)
RETURNS VARCHAR(48) NOT DETERMINISTIC MODIFIES SQL DATA
BEGIN
  DECLARE branch_code VARCHAR(16) DEFAULT 'HOM';
  DECLARE sequence_value BIGINT;
  DECLARE public_reference VARCHAR(48);
  IF branch_id_value IS NOT NULL THEN
    SELECT CASE branch_key WHEN 'daraga' THEN 'DAR' WHEN 'yashano' THEN 'YAS'
      WHEN 'cabangan' THEN 'CAB' ELSE CONCAT('BR', id) END
      INTO branch_code FROM branches WHERE id = branch_id_value;
  END IF;
  SET sequence_value = NEXT VALUE FOR booking_reference_sequence;
  SET public_reference = CONCAT('LM-', branch_code, '-', YEAR(UTC_TIMESTAMP() + INTERVAL 8 HOUR), '-',
    LPAD(sequence_value, GREATEST(6, CHAR_LENGTH(sequence_value)), '0'));
  RETURN public_reference;
END//
DELIMITER ;

ALTER TABLE appointments MODIFY reference_code VARCHAR(48) NULL;
ALTER TABLE home_service_requests MODIFY reference_code VARCHAR(48) NULL;
UPDATE appointments SET reference_code = next_booking_reference(branch_id) WHERE reference_code IS NULL OR reference_code = '';
UPDATE home_service_requests SET reference_code = next_booking_reference(NULL) WHERE reference_code IS NULL OR reference_code = '';

-- Preserve historical reservations on repeated migration runs.
INSERT INTO booking_reference_registry (reference_code)
SELECT reference_code FROM appointments WHERE reference_code NOT IN (SELECT reference_code FROM booking_reference_registry);
INSERT INTO booking_reference_registry (reference_code)
SELECT reference_code FROM home_service_requests WHERE reference_code NOT IN (SELECT reference_code FROM booking_reference_registry);

ALTER TABLE appointments MODIFY reference_code VARCHAR(48) NOT NULL DEFAULT '',
  ADD UNIQUE INDEX IF NOT EXISTS idx_reference_code_unique (reference_code);
ALTER TABLE home_service_requests MODIFY reference_code VARCHAR(48) NOT NULL DEFAULT '',
  ADD UNIQUE INDEX IF NOT EXISTS idx_home_service_reference_code_unique (reference_code);

DELIMITER //
CREATE OR REPLACE TRIGGER appointments_reference_insert BEFORE INSERT ON appointments FOR EACH ROW
BEGIN
  IF NEW.reference_code IS NULL OR NEW.reference_code = '' THEN
    SET NEW.reference_code = next_booking_reference(NEW.branch_id);
  END IF;
  INSERT INTO booking_reference_registry (reference_code) VALUES (NEW.reference_code);
END//
CREATE OR REPLACE TRIGGER home_service_reference_insert BEFORE INSERT ON home_service_requests FOR EACH ROW
BEGIN
  IF NEW.reference_code IS NULL OR NEW.reference_code = '' THEN
    SET NEW.reference_code = next_booking_reference(NULL);
  END IF;
  INSERT INTO booking_reference_registry (reference_code) VALUES (NEW.reference_code);
END//
CREATE OR REPLACE TRIGGER appointments_reference_immutable BEFORE UPDATE ON appointments FOR EACH ROW
BEGIN
  IF NOT (NEW.reference_code <=> OLD.reference_code) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking references cannot be changed';
  END IF;
END//
CREATE OR REPLACE TRIGGER home_service_reference_immutable BEFORE UPDATE ON home_service_requests FOR EACH ROW
BEGIN
  IF NOT (NEW.reference_code <=> OLD.reference_code) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking references cannot be changed';
  END IF;
END//
CREATE OR REPLACE TRIGGER booking_registry_no_delete BEFORE DELETE ON booking_reference_registry FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued booking references must never be reused';
END//
CREATE OR REPLACE TRIGGER booking_registry_no_update BEFORE UPDATE ON booking_reference_registry FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued booking references cannot be changed';
END//
DELIMITER ;
