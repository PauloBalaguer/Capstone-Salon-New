-- Migration 040: Remove PIN login
--
-- The role+PIN sign-in mode (backend/auth/portalLogin.php,
-- backend/auth/accountPin.php, the "Portal access PIN" account settings
-- widget) has been removed -- Staff/Cashier/Admin now sign in with email +
-- password only, same as Customers (see backend/auth/login.php's `portal`
-- flag). `pin_hash` (added in migration 005_portal_pin_login.sql) was only
-- ever read/written by those two now-deleted files.

ALTER TABLE `users` DROP COLUMN IF EXISTS `pin_hash`;
