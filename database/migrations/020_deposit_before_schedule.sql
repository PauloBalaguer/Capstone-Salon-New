-- Migration 020: reservation deposit required before viewing the schedule
--
-- Policy: guests (index.html) and logged-in customers (services-promos.html)
-- must submit a reservation deposit -- amount, method (Cash/GCash/Maya), and
-- for GCash/Maya a self-reported transaction reference -- before the
-- date/time schedule grid is shown at all. There's no live payment gateway
-- in this project, so this is a self-report-then-staff-verifies model:
-- deposit_paid/deposit_amount/deposit_method are now set at booking
-- submission time (by the customer) instead of later by staff.
-- `deposit_recorded_by IS NULL` means "self-reported, not yet verified by
-- staff"; staff verifying via the existing Confirm Booking flow sets it to
-- their user id, same column that already tracked who recorded a deposit.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `deposit_reference` VARCHAR(100) NULL AFTER `deposit_method`;
