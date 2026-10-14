-- Migration: lock each Cashier account to one branch.
--
-- Previously the cashier hub (pages/cashier/*.html) let any logged-in
-- Cashier switch between all three branches at runtime via a header
-- dropdown, and the backend trusted whatever branchId a request claimed --
-- there was nothing stopping one cashier terminal from writing into another
-- branch's queue/inventory. This ties each Cashier account to a single
-- branch server-side (users.branch_id), stored in the session at login
-- (see backend/auth/portalLogin.php and backend/auth/login.php) and
-- enforced by every backend/cashier/*.php endpoint instead of trusting the
-- client. Admin/Owner accounts leave branch_id NULL and keep full
-- cross-branch visibility, same as before.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `role_id`;
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `fk_users_branches_idx` (`branch_id` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_users_branches');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `users` ADD CONSTRAINT `fk_users_branches` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
