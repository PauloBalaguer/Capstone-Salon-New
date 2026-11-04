-- Migration 036: Remove Supplier Portal
--
-- The adviser removed the supplier/vendor module entirely (see migrations
-- 003, 032, 035 for what this undoes): no more Supplier login role, no
-- supplier accounts, no purchase-order tracking. Restocking is now purely
-- manual via inventory_adjustments (backend/admin/adjustStock.php,
-- backend/cashier/adjustStock.php) -- the free-text `inventory.supplier`
-- label is untouched and still works as a plain reference note.

-- Drop the child table first (FKs to inventory + users).
DROP TABLE IF EXISTS `supplier_orders`;

-- Any accounts that only existed to log into the Supplier Portal have
-- nothing left to do -- remove them before the role row they point to.
DELETE u FROM `users` u
  JOIN `roles` r ON r.id = u.role_id
  WHERE r.role_name = 'Supplier';

DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `supplier_action_log`;

DELETE FROM `roles` WHERE `role_name` = 'Supplier';
