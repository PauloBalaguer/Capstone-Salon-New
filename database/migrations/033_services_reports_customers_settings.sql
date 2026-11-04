-- Migration 033: Services/Promos, Reports, Customer Directory, Feedback,
-- Platform Settings (Step 5)

-- 1. Reservation payment rule gains a third option: services that are
--    reviewed/confirmed manually instead of collecting an online deposit at
--    all. 'Half Payment' is kept as-is (existing rows, existing meaning --
--    ReservationPayment::quote() already treats it as a synonym of
--    '50% Down Payment').
ALTER TABLE `services`
  MODIFY COLUMN `payment_requirement` ENUM('Half Payment', 'Full Payment', 'No Online Reservation') NOT NULL DEFAULT 'Half Payment';

-- 2. Feedback moderation status, separate from the customer's own rating/
--    comment columns (which this never touches) so Admin can mark a review
--    Reviewed/Resolved without altering what the guest actually said.
ALTER TABLE `feedback`
  ADD COLUMN IF NOT EXISTS `admin_status` ENUM('New', 'Reviewed', 'Resolved') NOT NULL DEFAULT 'New' AFTER `is_public`;

-- 3. Branch-specific operating hours (with an optional distinct Sunday
--    schedule) and booking capacity, configurable from Platform Settings
--    instead of living only as PHP constants in Scheduling.php.
ALTER TABLE `branches`
  ADD COLUMN IF NOT EXISTS `opening_time` TIME NOT NULL DEFAULT '09:00:00' AFTER `branch_type`,
  ADD COLUMN IF NOT EXISTS `closing_time` TIME NOT NULL DEFAULT '20:00:00' AFTER `opening_time`,
  ADD COLUMN IF NOT EXISTS `sunday_opening_time` TIME NULL AFTER `closing_time`,
  ADD COLUMN IF NOT EXISTS `sunday_closing_time` TIME NULL AFTER `sunday_opening_time`,
  ADD COLUMN IF NOT EXISTS `slot_limit` INT UNSIGNED NOT NULL DEFAULT 2 AFTER `sunday_closing_time`;

UPDATE `branches` SET `opening_time` = '08:00:00', `closing_time` = '20:00:00', `slot_limit` = 2 WHERE `branch_key` = 'daraga';
UPDATE `branches` SET `opening_time` = '09:30:00', `closing_time` = '20:00:00', `slot_limit` = 2 WHERE `branch_key` = 'yashano';
UPDATE `branches` SET `opening_time` = '08:00:00', `closing_time` = '20:00:00', `slot_limit` = 2 WHERE `branch_key` = 'cabangan';

-- 4. Three more notification preference categories (Payment Verification,
--    Home Service, Staff Conflict), alongside the existing Booking/
--    Inventory(Low Stock)/Supplier(Order)/Marketing ones.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `notify_payment` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_order`,
  ADD COLUMN IF NOT EXISTS `notify_home_service` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_payment`,
  ADD COLUMN IF NOT EXISTS `notify_staff_conflict` BOOLEAN NOT NULL DEFAULT TRUE AFTER `notify_home_service`;
