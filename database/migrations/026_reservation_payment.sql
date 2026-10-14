-- Step 7: preserve the quoted reservation requirement and amount on each booking.
-- Existing bookings retain their original payment records (NULL quote snapshot).
ALTER TABLE appointments
  ADD COLUMN IF NOT EXISTS reservation_requirement VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS reservation_amount_due DECIMAL(10,2) NULL;

-- Accept the historical enum during migration, then normalize the customer label.
ALTER TABLE services MODIFY payment_requirement ENUM('Half Payment', '50% Down Payment', 'Full Payment') NOT NULL DEFAULT '50% Down Payment';
UPDATE services SET payment_requirement = '50% Down Payment' WHERE payment_requirement = 'Half Payment';
ALTER TABLE services MODIFY payment_requirement ENUM('50% Down Payment', 'Full Payment') NOT NULL DEFAULT '50% Down Payment';
