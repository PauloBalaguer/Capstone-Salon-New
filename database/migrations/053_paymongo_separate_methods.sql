-- Migration 053: separate PayMongo methods -- GCash, Maya, Credit/Debit Card
--
-- Replaces the combined 'PayMongo' checkout (GCash/Maya/card on one page) for
-- new bookings with three methods (see backend/shared/config/payments/PayMongo.php):
--   'PayMongo-GCash', 'PayMongo-Maya' -- Payment Intent + Payment Method; the
--       customer goes straight to the GCash / Maya authorization page.
--   'PayMongo-Card' -- Checkout Session limited to cards (PayMongo's hosted
--       card form, so card details never touch this server).
-- 'PayMongo' stays valid for historical rows.
--
--   paymongo_payment_intent_id -- current GCash/Maya intent (pi_...); "Try
--                                 again" replaces it with a new one
--   paymongo_payment_id        -- the paid payment (pay_...), for any method
--
-- preferred_payment_method / deposit_method / home_service_payments.method
-- are VARCHARs, so the new values need no ENUM change.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `paymongo_payment_intent_id` VARCHAR(64) NULL AFTER `paymongo_checkout_url`,
  ADD COLUMN IF NOT EXISTS `paymongo_payment_id` VARCHAR(64) NULL AFTER `paymongo_payment_intent_id`,
  ADD INDEX IF NOT EXISTS `idx_appointments_paymongo_intent` (`paymongo_payment_intent_id`);

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `paymongo_payment_intent_id` VARCHAR(64) NULL AFTER `paymongo_checkout_url`,
  ADD COLUMN IF NOT EXISTS `paymongo_payment_id` VARCHAR(64) NULL AFTER `paymongo_payment_intent_id`,
  ADD INDEX IF NOT EXISTS `idx_home_service_paymongo_intent` (`paymongo_payment_intent_id`);

-- Online payments recorded before this migration keep their pay_ id in deposit_reference.
UPDATE `appointments` SET `paymongo_payment_id` = `deposit_reference`
WHERE `paymongo_payment_id` IS NULL AND `deposit_reference` LIKE 'pay\_%';
UPDATE `home_service_requests` SET `paymongo_payment_id` = `deposit_reference`
WHERE `paymongo_payment_id` IS NULL AND `deposit_reference` LIKE 'pay\_%';
