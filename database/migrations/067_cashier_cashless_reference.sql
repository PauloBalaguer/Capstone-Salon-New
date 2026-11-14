-- Preserve existing payments. Cashless checkout references and EOD method totals.
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `payment_reference` VARCHAR(100) NULL DEFAULT NULL;

ALTER TABLE `daily_closures`
  ADD COLUMN IF NOT EXISTS `payment_method_totals` TEXT NULL DEFAULT NULL;
