-- Migration 058: GCash through PayMongo Sources
--
-- New GCash payments use a Source instead of a Payment Intent (see
-- PayMongo::startPayment), because a Source has a redirect.failed URL: Fail /
-- Cancel on the GCash page sends the customer back to the result page, like
-- Maya. paymongo_source_id is the current GCash attempt; "Try again" replaces
-- it with a new one. The Payment created from it goes in paymongo_payment_id
-- as soon as it exists, so it is never created twice. Older GCash bookings
-- keep their paymongo_payment_intent_id.
-- Safe to rerun. Run after 057.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `paymongo_source_id` VARCHAR(64) NULL AFTER `paymongo_payment_intent_id`,
  ADD INDEX IF NOT EXISTS `idx_appointments_paymongo_source` (`paymongo_source_id`);

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `paymongo_source_id` VARCHAR(64) NULL AFTER `paymongo_payment_intent_id`,
  ADD INDEX IF NOT EXISTS `idx_home_service_paymongo_source` (`paymongo_source_id`);
