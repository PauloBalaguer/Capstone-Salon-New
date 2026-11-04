-- Cash-specific checkout details (Step 3 patch): how much cash the
-- customer physically handed over and the change given back, so the
-- receipt can show both for Cash transactions. NULL for non-Cash methods.
ALTER TABLE payments
  ADD COLUMN cash_received DECIMAL(10,2) NULL AFTER tip_amount,
  ADD COLUMN change_given DECIMAL(10,2) NULL AFTER cash_received;
