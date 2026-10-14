-- Migration 017: preferred payment method at booking time
--
-- Lets a guest (index.html) or logged-in customer (pages/customer/services-promos.html)
-- indicate how they intend to pay when they submit a booking request. This is
-- separate from `deposit_method` (which records what staff actually collected
-- when confirming the reservation, set later via backend/admin/updateBookingStatus.php)
-- -- it's just the customer's stated preference, shown to staff as a hint.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `preferred_payment_method` VARCHAR(30) NULL AFTER `total_price`;
