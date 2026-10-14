-- Migration 042: PayMongo online reservation payment
--
-- Adds a fourth reservation payment method, 'PayMongo', next to the manual
-- Cash/GCash/Maya options. A PayMongo booking is inserted unpaid
-- (deposit_paid = 0, payment_status 'Payment Required') and a PayMongo
-- Checkout Session is created for its reservation_amount_due. Once PayMongo
-- reports the session paid (webhook or the result page's status check, see
-- backend/config/PayMongo.php), the deposit is recorded and the booking moves
-- to 'Awaiting Verification' like any other self-reported deposit -- staff
-- still confirm the appointment through the existing flow.
--
--   paymongo_checkout_id  -- PayMongo checkout session id (cs_...)
--   paymongo_checkout_url -- hosted checkout page, reused for "Try again"
--   online_payment_token  -- random secret in the success/cancel URLs so
--                            only the booker can read the payment result
--   loyalty_points_used   -- points spent on the booking, restored if an
--                            unpaid PayMongo booking expires

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `paymongo_checkout_id` VARCHAR(64) NULL AFTER `deposit_reference`,
  ADD COLUMN IF NOT EXISTS `paymongo_checkout_url` VARCHAR(255) NULL AFTER `paymongo_checkout_id`,
  ADD COLUMN IF NOT EXISTS `online_payment_token` CHAR(32) NULL AFTER `paymongo_checkout_url`,
  ADD COLUMN IF NOT EXISTS `loyalty_points_used` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `online_payment_token`,
  ADD INDEX IF NOT EXISTS `idx_appointments_paymongo_checkout` (`paymongo_checkout_id`);
