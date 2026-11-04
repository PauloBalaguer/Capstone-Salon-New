-- Adds a permanence flag for accounts that must never be deactivated/removed
-- through the admin UI (e.g. the baseline cashier + admin accounts).
ALTER TABLE users ADD COLUMN is_protected TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
