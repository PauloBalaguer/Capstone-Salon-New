-- Migration 035: Supplier Portal (separate role)
--
-- Suppliers were previously just a free-text label on `inventory.supplier`
-- and a `supplier_orders.supplier_name` snapshot -- no login, no account.
-- This adds a real `Supplier` role/account (same pattern as Cashier: a
-- `users` row with no `employees` row) plus a `suppliers` profile table,
-- and links `supplier_orders` to a registered supplier account so a
-- supplier can see only their own orders.
--
-- Existing orders/inventory rows keep working unchanged: `supplier_id` is
-- nullable, and the free-text `supplier`/`supplier_name` fields are left in
-- place as a fallback for orders never assigned to a registered account.

INSERT INTO `roles` (`role_name`, `description`)
SELECT 'Supplier', 'External supplier/vendor with access to their own purchase orders only.'
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_name` = 'Supplier');

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `company_name` VARCHAR(255) NOT NULL,
  `contact_person` VARCHAR(255) NULL,
  `phone` VARCHAR(30) NULL,
  `email` VARCHAR(255) NULL,
  `address` VARCHAR(500) NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_suppliers_user_id_unique` (`user_id` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_suppliers_users');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `suppliers` ADD CONSTRAINT `fk_suppliers_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `supplier_orders`
  ADD COLUMN IF NOT EXISTS `supplier_id` INT UNSIGNED NULL AFTER `inventory_id`,
  ADD COLUMN IF NOT EXISTS `dispatched_at` TIMESTAMP NULL AFTER `expected_date`,
  ADD COLUMN IF NOT EXISTS `delivery_notes` VARCHAR(500) NULL AFTER `dispatched_at`;

ALTER TABLE `supplier_orders`
  ADD INDEX IF NOT EXISTS `fk_supplier_orders_suppliers_idx` (`supplier_id` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_supplier_orders_suppliers');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `supplier_orders` ADD CONSTRAINT `fk_supplier_orders_suppliers` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Audit trail for supplier-side actions (separate from inventory_adjustments,
-- which is about stock quantities -- this is about the supplier's own
-- account/order actions: login, confirm, expected-delivery update, dispatch).
CREATE TABLE IF NOT EXISTS `supplier_action_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NULL,
  `order_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_supplier_action_log_supplier` (`supplier_id` ASC),
  INDEX `idx_supplier_action_log_order` (`order_id` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;
