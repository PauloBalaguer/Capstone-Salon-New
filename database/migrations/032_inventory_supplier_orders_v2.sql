-- Migration 032: Multi-Branch Inventory & Suppliers (Step 4)
--
-- 1. `inventory.is_active` replaces hard-deleting a product. Both
--    inventory_adjustments and supplier_orders have ON DELETE CASCADE to
--    inventory(id) -- deleting a product with any order/audit history wipes
--    that history along with it. Deactivating instead keeps every past
--    supplier order and audit entry intact.
-- 2. `inventory_adjustments.adjustment_type` gains non-stock-delta audit
--    events (create/edit/deactivate/reactivate) so item lifecycle changes
--    are captured in the same audit trail as stock adjustments, not just
--    quantity changes.
-- 3. `supplier_orders` gains a public reference code, a supplier name
--    snapshot (captured from inventory.supplier at order time, so it stays
--    accurate even if that item's supplier text is edited later), and
--    received_by/received_at so "Received By" can be shown per order. The
--    status enum expands from 3 steps to the full 5-step workflow:
--    Order Placed -> Order Confirmed -> Out for Delivery -> Received -> Completed.

ALTER TABLE `inventory`
  ADD COLUMN IF NOT EXISTS `is_active` BOOLEAN NOT NULL DEFAULT TRUE AFTER `type`;

ALTER TABLE `inventory_adjustments`
  MODIFY COLUMN `adjustment_type` ENUM('add', 'sub', 'create', 'edit', 'deactivate', 'reactivate') NOT NULL;

ALTER TABLE `supplier_orders`
  ADD COLUMN IF NOT EXISTS `reference_code` VARCHAR(30) NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `supplier_name` VARCHAR(255) NULL AFTER `inventory_id`,
  ADD COLUMN IF NOT EXISTS `received_by` INT UNSIGNED NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `received_at` TIMESTAMP NULL AFTER `received_by`;

UPDATE `supplier_orders` SET `reference_code` = CONCAT('SO-', LPAD(id, 6, '0')) WHERE `reference_code` IS NULL OR `reference_code` = '';

ALTER TABLE `supplier_orders`
  ADD UNIQUE INDEX IF NOT EXISTS `idx_supplier_order_reference_unique` (`reference_code` ASC),
  ADD INDEX IF NOT EXISTS `fk_supplier_orders_received_by_idx` (`received_by` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_supplier_orders_received_by');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `supplier_orders` ADD CONSTRAINT `fk_supplier_orders_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `supplier_orders` so
  JOIN `inventory` i ON i.id = so.inventory_id
  SET so.supplier_name = i.supplier
  WHERE so.supplier_name IS NULL;

-- Widen to a superset first so existing 'Ordered'/'Shipped' rows survive the
-- remap below, then narrow to the final 5-step vocabulary.
ALTER TABLE `supplier_orders`
  MODIFY COLUMN `status` ENUM('Ordered', 'Shipped', 'Received', 'Order Placed', 'Order Confirmed', 'Out for Delivery', 'Completed') NOT NULL DEFAULT 'Ordered';

UPDATE `supplier_orders` SET `status` = 'Order Placed' WHERE `status` = 'Ordered';
UPDATE `supplier_orders` SET `status` = 'Out for Delivery' WHERE `status` = 'Shipped';

ALTER TABLE `supplier_orders`
  MODIFY COLUMN `status` ENUM('Order Placed', 'Order Confirmed', 'Out for Delivery', 'Received', 'Completed') NOT NULL DEFAULT 'Order Placed';
