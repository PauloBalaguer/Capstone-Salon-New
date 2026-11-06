-- Migration 057: back to PayMongo, Xendit removed
--
-- Undoes 055 (Xendit) and 056 (PayMongo removed) without editing them:
--   * puts back the paymongo_* columns 056 dropped, as 042 / 044 / 046 / 053
--     first defined them (the ids of past PayMongo payments stay in
--     deposit_reference, so nothing is lost)
--   * renames the display text 056 wrote ('GCash (PayMongo)' ...) back to the
--     method values PayMongo.php uses ('PayMongo-GCash' ...)
--   * turns the Xendit method values into plain display text
--     ('Xendit-GCash' -> 'GCash (Xendit)'): no code is left for them, but a
--     deposit paid that way still shows in the payment log and revenue report
--   * drops the xendit_* columns and restores payment_transactions.provider's
--     default. Old rows keep provider 'Xendit'.
-- Safe to rerun.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `paymongo_checkout_id` VARCHAR(64) NULL AFTER `deposit_reference`,
  ADD COLUMN IF NOT EXISTS `paymongo_checkout_url` VARCHAR(255) NULL AFTER `paymongo_checkout_id`,
  ADD COLUMN IF NOT EXISTS `paymongo_refund_id` VARCHAR(64) NULL AFTER `paymongo_checkout_url`,
  ADD COLUMN IF NOT EXISTS `paymongo_payment_intent_id` VARCHAR(64) NULL AFTER `paymongo_checkout_url`,
  ADD COLUMN IF NOT EXISTS `paymongo_payment_id` VARCHAR(64) NULL AFTER `paymongo_payment_intent_id`,
  ADD INDEX IF NOT EXISTS `idx_appointments_paymongo_checkout` (`paymongo_checkout_id`),
  ADD INDEX IF NOT EXISTS `idx_appointments_paymongo_intent` (`paymongo_payment_intent_id`);

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `paymongo_checkout_id` VARCHAR(100) NULL DEFAULT NULL AFTER `deposit_recorded_at`,
  ADD COLUMN IF NOT EXISTS `paymongo_checkout_url` VARCHAR(500) NULL DEFAULT NULL AFTER `paymongo_checkout_id`,
  ADD COLUMN IF NOT EXISTS `paymongo_payment_intent_id` VARCHAR(64) NULL AFTER `paymongo_checkout_url`,
  ADD COLUMN IF NOT EXISTS `paymongo_payment_id` VARCHAR(64) NULL AFTER `paymongo_payment_intent_id`,
  ADD INDEX IF NOT EXISTS `idx_home_service_paymongo_checkout` (`paymongo_checkout_id`),
  ADD INDEX IF NOT EXISTS `idx_home_service_paymongo_intent` (`paymongo_payment_intent_id`);

UPDATE `appointments` SET `preferred_payment_method` = CASE `preferred_payment_method`
    WHEN 'GCash (PayMongo)' THEN 'PayMongo-GCash' WHEN 'Maya (PayMongo)' THEN 'PayMongo-Maya' WHEN 'Card (PayMongo)' THEN 'PayMongo-Card'
    WHEN 'Xendit-GCash' THEN 'GCash (Xendit)' WHEN 'Xendit-Maya' THEN 'Maya (Xendit)' WHEN 'Xendit-Card' THEN 'Card (Xendit)' END
  WHERE `preferred_payment_method` IN ('GCash (PayMongo)', 'Maya (PayMongo)', 'Card (PayMongo)', 'Xendit-GCash', 'Xendit-Maya', 'Xendit-Card');
UPDATE `appointments` SET `deposit_method` = CASE `deposit_method`
    WHEN 'GCash (PayMongo)' THEN 'PayMongo-GCash' WHEN 'Maya (PayMongo)' THEN 'PayMongo-Maya' WHEN 'Card (PayMongo)' THEN 'PayMongo-Card'
    WHEN 'Xendit-GCash' THEN 'GCash (Xendit)' WHEN 'Xendit-Maya' THEN 'Maya (Xendit)' WHEN 'Xendit-Card' THEN 'Card (Xendit)' END
  WHERE `deposit_method` IN ('GCash (PayMongo)', 'Maya (PayMongo)', 'Card (PayMongo)', 'Xendit-GCash', 'Xendit-Maya', 'Xendit-Card');
UPDATE `home_service_requests` SET `deposit_method` = CASE `deposit_method`
    WHEN 'GCash (PayMongo)' THEN 'PayMongo-GCash' WHEN 'Maya (PayMongo)' THEN 'PayMongo-Maya' WHEN 'Card (PayMongo)' THEN 'PayMongo-Card'
    WHEN 'Xendit-GCash' THEN 'GCash (Xendit)' WHEN 'Xendit-Maya' THEN 'Maya (Xendit)' WHEN 'Xendit-Card' THEN 'Card (Xendit)' END
  WHERE `deposit_method` IN ('GCash (PayMongo)', 'Maya (PayMongo)', 'Card (PayMongo)', 'Xendit-GCash', 'Xendit-Maya', 'Xendit-Card');
UPDATE `home_service_payments` SET `method` = CASE `method`
    WHEN 'GCash (PayMongo)' THEN 'PayMongo-GCash' WHEN 'Maya (PayMongo)' THEN 'PayMongo-Maya' WHEN 'Card (PayMongo)' THEN 'PayMongo-Card'
    WHEN 'Xendit-GCash' THEN 'GCash (Xendit)' WHEN 'Xendit-Maya' THEN 'Maya (Xendit)' WHEN 'Xendit-Card' THEN 'Card (Xendit)' END
  WHERE `method` IN ('GCash (PayMongo)', 'Maya (PayMongo)', 'Card (PayMongo)', 'Xendit-GCash', 'Xendit-Maya', 'Xendit-Card');

ALTER TABLE `appointments`
  DROP COLUMN IF EXISTS `xendit_payment_request_id`,
  DROP COLUMN IF EXISTS `xendit_invoice_id`,
  DROP COLUMN IF EXISTS `xendit_invoice_url`;

ALTER TABLE `home_service_requests`
  DROP COLUMN IF EXISTS `xendit_payment_request_id`,
  DROP COLUMN IF EXISTS `xendit_invoice_id`,
  DROP COLUMN IF EXISTS `xendit_invoice_url`;

ALTER TABLE `payment_transactions`
  MODIFY `provider` VARCHAR(20) NOT NULL DEFAULT 'PayMongo';
