-- Migration 031: Admin Home Service management (Step 2)
--
-- Gives the admin Booking Desk everything it needs to run a home service
-- request end-to-end: a structured final quote, an optional reservation
-- fee, and a finer-grained status vocabulary matching the real workflow
-- (Under Review -> Quote Ready -> Payment Required -> Payment Being
-- Verified -> Confirmed -> Staff Assigned -> Service in Progress ->
-- Completed). Old status values are kept in the enum so existing rows and
-- the customer-facing label mapping (scripts/customer/dashboard-home-service.js,
-- which already falls back to the raw value for anything it doesn't know)
-- keep working untouched.
--
-- `wedding_package` and `quote_price` are new structured columns; before
-- this, the package choice was folded into the free-text `requests` field
-- (see backend/customer/submitHomeServiceRequest.php) with no columned
-- final price at all. `preferred_time` is added defensively (IF NOT
-- EXISTS) since existing submit endpoints already write to it.

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `preferred_time` TIME NULL AFTER `preferred_date`,
  ADD COLUMN IF NOT EXISTS `wedding_package` VARCHAR(10) NULL AFTER `event_type`,
  ADD COLUMN IF NOT EXISTS `quote_price` DECIMAL(10,2) NULL AFTER `requests`;

ALTER TABLE `home_service_requests`
  MODIFY COLUMN `status` ENUM(
    'Pending Review','Quote Ready','Payment Required','Payment Being Verified',
    'Confirmed','Staff Assigned','Service in Progress','Completed','Cancelled'
  ) NOT NULL DEFAULT 'Pending Review';
