-- Migration: real backend support for the admin panel.
-- Adds the columns/tables the admin dashboard needs that the customer-backend
-- migration (002) didn't touch: display names + notification prefs for
-- Admin/Owner accounts, branch display fields, inventory branch scoping,
-- supplier order tracking, nullable appointment staff assignment (for the
-- admin "Unassigned -> Assign Staff" flow), and broadcast-style
-- notifications. Also seeds one Owner + one Admin login so the panel is
-- actually reachable.

-- users: display name (Admin/Owner have no employees/customers profile row
-- to hold a name) + admin notification-preference toggles.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(150) NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `notify_booking` BOOLEAN NOT NULL DEFAULT TRUE AFTER `display_name`,
  ADD COLUMN IF NOT EXISTS `notify_inventory` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_booking`,
  ADD COLUMN IF NOT EXISTS `notify_order` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_inventory`,
  ADD COLUMN IF NOT EXISTS `notify_marketing` BOOLEAN NOT NULL DEFAULT FALSE AFTER `notify_order`;

-- branches: display fields used by the admin dashboard cards. Revenue and
-- rating are computed on read (from payments/feedback), not stored here.
ALTER TABLE `branches`
  ADD COLUMN IF NOT EXISTS `location` VARCHAR(255) NULL AFTER `branch_name`,
  ADD COLUMN IF NOT EXISTS `branch_type` VARCHAR(100) NULL AFTER `location`;

-- inventory: scope stock to a branch (table currently has no branch concept
-- at all).
ALTER TABLE `inventory`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `id`;
ALTER TABLE `inventory`
  ADD INDEX IF NOT EXISTS `fk_inventory_branches_idx` (`branch_id` ASC);

-- appointments: allow an unassigned booking (admin dispatch flow creates a
-- booking first, assigns staff afterward). Customer-facing booking endpoints
-- always set this, so they're unaffected.
ALTER TABLE `appointments`
  MODIFY COLUMN `employee_id` INT UNSIGNED NULL;

-- notifications: NULL user_id = broadcast to every Admin/Owner, so one event
-- doesn't need an insert per admin user.
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

-- Foreign keys added last so the referenced/added columns above already exist.
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_inventory_branches');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `inventory` ADD CONSTRAINT `fk_inventory_branches` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed: one Admin dev login so the panel is reachable. Admin and Owner have
-- identical access in backend/admin/*.php, so only one seed account is kept.
-- Password: Admin@2026 (bcrypt hash pre-computed below).
INSERT INTO `users` (`role_id`, `email`, `password`, `display_name`)
SELECT r.id, 'admin@leomejillanosalon.ph', '$2y$10$X8UwAGD8psjkusfHxJlIMeXJLGOt/ortWp8Ja0..Sv3buhGYXJ6m2', 'Platform Administrator'
FROM `roles` r WHERE r.role_name = 'Admin'
AND NOT EXISTS (SELECT 1 FROM `users` WHERE `email` = 'admin@leomejillanosalon.ph');
