-- Additive only. Existing free-text addresses and historical requests remain intact.
ALTER TABLE customers ADD COLUMN IF NOT EXISTS structured_address JSON NULL;
ALTER TABLE home_service_requests ADD COLUMN IF NOT EXISTS structured_address JSON NULL;
