-- Migration: PIN-based quick login for the internal portal
-- (pages/portal-login/), used by Admin/Owner/Staff/Cashier accounts as a
-- faster alternative to typing email+password on a shared terminal. Each
-- account's PIN is optional (NULL until the account holder sets one from
-- their profile page) and hashed the same way as the main password.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `pin_hash` VARCHAR(255) NULL AFTER `password`;
