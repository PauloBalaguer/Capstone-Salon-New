-- Step 5: Close Business Day needs to actually lock a day (one closure per
-- branch per business date) and support an Admin/Owner-only reopen, neither
-- of which the old "log a snapshot every click" closeEod.php tracked.
ALTER TABLE daily_closures
  ADD COLUMN business_date DATE NOT NULL DEFAULT (CURRENT_DATE) AFTER branch_id,
  ADD COLUMN is_reopened TINYINT(1) NOT NULL DEFAULT 0 AFTER gross_revenue,
  ADD COLUMN reopened_by INT UNSIGNED NULL AFTER is_reopened,
  ADD COLUMN reopened_at TIMESTAMP NULL AFTER reopened_by,
  ADD CONSTRAINT fk_daily_closures_reopened_by FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD UNIQUE KEY uq_daily_closures_branch_date (branch_id, business_date);
