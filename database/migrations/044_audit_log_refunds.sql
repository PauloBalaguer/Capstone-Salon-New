-- Migration 044: activity/audit log and PayMongo refunds
--
-- audit_log: one row per successful write action by any user (staff,
-- customer, or guest), plus sign-ins and failed sign-ins. Written by
-- backend/config/AuditLog.php; read by Admin -> Reports -> Activity Log.
--
-- appointments.paymongo_refund_id: the PayMongo refund created by
-- backend/admin/refundDeposit.php, so a pending refund can be re-checked
-- and a deposit is never refunded twice. 'Refund Processing' is the
-- payment_status while PayMongo is still completing that refund.
-- Safe to rerun.

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `user_role` VARCHAR(30) NULL,
  `actor` VARCHAR(255) NULL,
  `action` VARCHAR(100) NOT NULL,
  `label` VARCHAR(150) NOT NULL,
  `entity_ref` VARCHAR(100) NULL,
  `outcome` ENUM('success', 'failed') NOT NULL DEFAULT 'success',
  `message` VARCHAR(255) NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_audit_created` (`created_at`),
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_entity` (`entity_ref`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `paymongo_refund_id` VARCHAR(64) NULL AFTER `paymongo_checkout_url`;

ALTER TABLE `appointments`
  MODIFY `payment_status` ENUM('Payment Required','Awaiting Verification','Down Payment Verified','Fully Paid','Rejected','Refund Due','Refund Processing','Refunded','Forfeited') NOT NULL DEFAULT 'Payment Required';
