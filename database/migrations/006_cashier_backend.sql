-- Migration: real backend support for the cashier hub (pages/cashier/).
-- Adds the retail-catalog fields the cashier's Inventory tab needs
-- (category, max_stock), a manual on-floor status for the Staff tab
-- (shift_status), itemized receipts + tips for the checkout terminal
-- (payment_items, payments.tip_amount, payments.processed_by), an
-- adjustment audit trail for the "View Adjustment Log" modal, and an
-- End-of-Day closing log. Also seeds the three branches so the cashier
-- hub works even if database/seeders/seed_customer_demo.php hasn't been
-- run yet.

INSERT INTO `branches` (`branch_key`, `branch_name`, `location`, `branch_type`)
VALUES
  ('daraga', 'Leo Mejillano Salon & Makeup Studio', 'Daraga, Albay', 'Salon & Makeup Studio'),
  ('yashano', 'Skin Brows by Leo Mejillano', 'Yashano Mall', 'Skin & Brows Studio'),
  ('cabangan', 'Lash & Brows by Leo Mejillano Salon', 'Cabangan', 'Lash & Brows Studio')
ON DUPLICATE KEY UPDATE `branch_name` = VALUES(`branch_name`);

-- inventory: retail catalog fields the cashier's Add/Edit Product form collects.
ALTER TABLE `inventory`
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL AFTER `product_name`,
  ADD COLUMN IF NOT EXISTS `max_stock` INT UNSIGNED NOT NULL DEFAULT 100 AFTER `reorder_level`;

-- employees: manually-set floor status ("Set Shifting Status" in the Staff tab).
-- Distinct from the status admin/getDashboardData.php *computes* from
-- attendance/appointments -- this one is the cashier's own direct control.
ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `shift_status` ENUM('On Duty', 'With Client', 'Off Shift') NOT NULL DEFAULT 'Off Shift' AFTER `is_active`;

-- payments: tip allocation + which cashier account processed the transaction,
-- and widen the method vocabulary to match the checkout terminal's options.
ALTER TABLE `payments`
  MODIFY COLUMN `payment_method` ENUM('Cash', 'Card', 'Online', 'Gift Card', 'GCash', 'Maya') NOT NULL,
  ADD COLUMN IF NOT EXISTS `tip_amount` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `processed_by` INT UNSIGNED NULL AFTER `status`;

ALTER TABLE `payments`
  ADD INDEX IF NOT EXISTS `fk_payments_users_idx` (`processed_by` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_payments_users');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_users` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- payment_items: itemized receipt lines (services availed + retail products
-- sold in that transaction), used by receipt.html's printable breakdown.
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

-- inventory_adjustments: audit trail for every manual stock adjustment made
-- from the cashier's Inventory tab, backing the "View Adjustment Log" modal.
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

-- daily_closures: one row per "Execute Close & Archive Logs" click. This is a
-- log entry, not a destructive archive -- no transactional data is deleted or
-- moved, it just timestamps the EOD snapshot for record-keeping.
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
