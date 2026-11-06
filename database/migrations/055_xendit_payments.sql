-- Migration 055: Xendit online payments
--
-- Xendit replaces PayMongo for online reservation payments (see
-- backend/shared/config/payments/Xendit.php). Three methods:
--   'Xendit-GCash', 'Xendit-Maya' -- Payment Request API; the customer goes
--       straight to the GCash / Maya page.
--   'Xendit-Card' -- Invoice API limited to cards (Xendit's hosted card form,
--       so card details never touch this server).
--
--   xendit_payment_request_id -- current GCash / Maya attempt; "Try again"
--                                replaces it with a new one
--   xendit_invoice_id         -- current card attempt (invoice)
--   xendit_invoice_url        -- the current attempt's open payment page
--                                (invoice page, or the GCash / Maya page),
--                                reopened by the result page
--
-- The paid attempt's id goes in deposit_reference. preferred_payment_method /
-- deposit_method / home_service_payments.method are VARCHARs, so the new
-- values need no ENUM change. Home service balance links keep using
-- balance_checkout_id / balance_checkout_url (now a Xendit invoice).
-- Safe to rerun.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `xendit_payment_request_id` VARCHAR(64) NULL AFTER `deposit_reference`,
  ADD COLUMN IF NOT EXISTS `xendit_invoice_id` VARCHAR(64) NULL AFTER `xendit_payment_request_id`,
  ADD COLUMN IF NOT EXISTS `xendit_invoice_url` VARCHAR(512) NULL AFTER `xendit_invoice_id`,
  ADD INDEX IF NOT EXISTS `idx_appointments_xendit_request` (`xendit_payment_request_id`),
  ADD INDEX IF NOT EXISTS `idx_appointments_xendit_invoice` (`xendit_invoice_id`);

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `xendit_payment_request_id` VARCHAR(64) NULL AFTER `deposit_recorded_at`,
  ADD COLUMN IF NOT EXISTS `xendit_invoice_id` VARCHAR(64) NULL AFTER `xendit_payment_request_id`,
  ADD COLUMN IF NOT EXISTS `xendit_invoice_url` VARCHAR(512) NULL AFTER `xendit_invoice_id`,
  ADD INDEX IF NOT EXISTS `idx_home_service_xendit_request` (`xendit_payment_request_id`),
  ADD INDEX IF NOT EXISTS `idx_home_service_xendit_invoice` (`xendit_invoice_id`);

ALTER TABLE `payment_transactions`
  MODIFY `provider` VARCHAR(20) NOT NULL DEFAULT 'Xendit';
