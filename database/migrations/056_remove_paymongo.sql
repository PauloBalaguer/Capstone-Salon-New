-- Migration 056: remove PayMongo
--
-- PayMongo is replaced by Xendit (migration 055) and its code is gone. Its
-- columns are dropped, and the old method values become plain display text so
-- historical bookings still read correctly in the payment log, revenue report
-- and portals. Xendit::LEGACY_METHODS lists these values: they still count as
-- money paid online (staff can verify them, cancellation keeps them), but
-- there is no API for them any more -- refunds (including one still 'Refund
-- Processing') are finished from the PayMongo dashboard by hand, then marked
-- refunded. Old payment_transactions rows keep provider 'PayMongo'.
-- Safe to rerun. Run after 055.

UPDATE `appointments` SET `preferred_payment_method` = CASE `preferred_payment_method`
    WHEN 'PayMongo-GCash' THEN 'GCash (PayMongo)' WHEN 'PayMongo-Maya' THEN 'Maya (PayMongo)' WHEN 'PayMongo-Card' THEN 'Card (PayMongo)' END
  WHERE `preferred_payment_method` IN ('PayMongo-GCash', 'PayMongo-Maya', 'PayMongo-Card');
UPDATE `appointments` SET `deposit_method` = CASE `deposit_method`
    WHEN 'PayMongo-GCash' THEN 'GCash (PayMongo)' WHEN 'PayMongo-Maya' THEN 'Maya (PayMongo)' WHEN 'PayMongo-Card' THEN 'Card (PayMongo)' END
  WHERE `deposit_method` IN ('PayMongo-GCash', 'PayMongo-Maya', 'PayMongo-Card');
UPDATE `home_service_requests` SET `deposit_method` = CASE `deposit_method`
    WHEN 'PayMongo-GCash' THEN 'GCash (PayMongo)' WHEN 'PayMongo-Maya' THEN 'Maya (PayMongo)' WHEN 'PayMongo-Card' THEN 'Card (PayMongo)' END
  WHERE `deposit_method` IN ('PayMongo-GCash', 'PayMongo-Maya', 'PayMongo-Card');
UPDATE `home_service_payments` SET `method` = CASE `method`
    WHEN 'PayMongo-GCash' THEN 'GCash (PayMongo)' WHEN 'PayMongo-Maya' THEN 'Maya (PayMongo)' WHEN 'PayMongo-Card' THEN 'Card (PayMongo)' END
  WHERE `method` IN ('PayMongo-GCash', 'PayMongo-Maya', 'PayMongo-Card');

ALTER TABLE `appointments`
  DROP COLUMN IF EXISTS `paymongo_checkout_id`,
  DROP COLUMN IF EXISTS `paymongo_checkout_url`,
  DROP COLUMN IF EXISTS `paymongo_payment_intent_id`,
  DROP COLUMN IF EXISTS `paymongo_payment_id`,
  DROP COLUMN IF EXISTS `paymongo_refund_id`;

ALTER TABLE `home_service_requests`
  DROP COLUMN IF EXISTS `paymongo_checkout_id`,
  DROP COLUMN IF EXISTS `paymongo_checkout_url`,
  DROP COLUMN IF EXISTS `paymongo_payment_intent_id`,
  DROP COLUMN IF EXISTS `paymongo_payment_id`;
