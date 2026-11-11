-- Migration 061: guests enter first, middle (optional) and last name separately.
--
-- Adds a nullable middle_name to customers and to guest_account_claims (a
-- sign-up waiting to link guest bookings, copied into customers once verified); NULL means none was given (or N/A).
-- Changes no existing data. Safe to run again.
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `middle_name` VARCHAR(100) NULL DEFAULT NULL AFTER `first_name`;

ALTER TABLE `guest_account_claims`
  ADD COLUMN IF NOT EXISTS `middle_name` VARCHAR(100) NULL DEFAULT NULL AFTER `first_name`;
