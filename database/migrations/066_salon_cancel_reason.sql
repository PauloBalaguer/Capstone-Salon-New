-- Migration 066: why and by whom the salon cancelled a booking.
--
-- When an admin cancels (e.g. the stylist is absent) they must choose to
-- refund the deposit or reschedule, and give a reason. The reason and the
-- admin are kept on the booking so the refund record, the admin screen and
-- the customer's email all show the same thing.
-- Adds two empty columns; changes no existing data. Safe to run again.
ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `cancel_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `cancelled_at`,
  ADD COLUMN IF NOT EXISTS `cancelled_by` INT UNSIGNED NULL DEFAULT NULL AFTER `cancel_reason`;
