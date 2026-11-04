-- Migration 023: separate appointment status from payment status
--
-- Previously the only signal for "has the deposit been checked" was the
-- boolean `deposit_recorded_by IS NOT NULL`, and the appointment status
-- enum had no room for a reschedule conversation. These were never
-- customer-settable -- only backend/admin/updateBookingStatus.php and
-- backend/cashier/updateStatus.php write either column, both gated by
-- role checks -- but they were also not independently modeled. Two
-- statuses now exist in parallel:
--   appointments.status         -- the appointment lifecycle
--   appointments.payment_status -- the deposit/payment lifecycle
-- `payment_status` defaults to 'Payment Required' for schema completeness,
-- though every current booking path collects a deposit before the row is
-- even created, so new rows are inserted straight into 'Awaiting
-- Verification' (see submitBooking.php/submitGuestBooking.php et al).

ALTER TABLE `appointments`
  MODIFY COLUMN `status` ENUM('Pending', 'Confirmed', 'In Progress', 'Completed', 'Cancelled', 'Reviewed', 'No-Show', 'Reschedule Requested', 'Reschedule Required') NOT NULL DEFAULT 'Pending';

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `payment_status` ENUM('Payment Required', 'Awaiting Verification', 'Down Payment Verified', 'Fully Paid', 'Rejected', 'Refunded', 'Forfeited') NOT NULL DEFAULT 'Payment Required' AFTER `deposit_recorded_at`;

UPDATE `appointments`
  SET `payment_status` = CASE
    WHEN `deposit_recorded_by` IS NOT NULL AND `status` = 'Completed' THEN 'Fully Paid'
    WHEN `deposit_recorded_by` IS NOT NULL THEN 'Down Payment Verified'
    WHEN `deposit_paid` = 1 THEN 'Awaiting Verification'
    ELSE 'Payment Required'
  END
  WHERE `payment_status` = 'Payment Required';

-- Home service requests carry the same deposit-verification pattern, so
-- they get the same payment_status column (there is no separate line-item
-- "services" catalog for a home service request, so its Confirmed
-- transition always resolves to 'Down Payment Verified', never
-- 'Fully Paid' -- see backend/admin/updateBookingStatus.php).
ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `payment_status` ENUM('Payment Required', 'Awaiting Verification', 'Down Payment Verified', 'Fully Paid', 'Rejected', 'Refunded', 'Forfeited') NOT NULL DEFAULT 'Payment Required' AFTER `deposit_recorded_at`;

UPDATE `home_service_requests`
  SET `payment_status` = CASE
    WHEN `deposit_recorded_by` IS NOT NULL THEN 'Down Payment Verified'
    WHEN `deposit_paid` = 1 THEN 'Awaiting Verification'
    ELSE 'Payment Required'
  END
  WHERE `payment_status` = 'Payment Required';
