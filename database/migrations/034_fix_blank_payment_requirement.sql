-- Migration 034: data repair for services.payment_requirement
--
-- Found while wiring up Step 5's Reservation Payment Rule field: most of
-- the seeded service catalog (355 rows) had payment_requirement = '' --
-- an invalid ENUM value, not the column's own 'Half Payment' default.
-- ReservationPayment::quote() throws for any value outside its allowed
-- set, so any booking on one of these services would fail at checkout.
-- Backfills them to the standard 'Half Payment' (= '50% Down Payment'),
-- same as every other service that was never explicitly configured.

UPDATE `services`
  SET `payment_requirement` = 'Half Payment'
  WHERE `payment_requirement` NOT IN ('Half Payment', 'Full Payment', 'No Online Reservation');
