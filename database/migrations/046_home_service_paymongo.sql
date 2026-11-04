-- Home service reservation fee (down payment) through PayMongo.
--
-- When the admin sets a quote with a reservation fee, a PayMongo checkout is
-- created for the request (backend/admin/setHomeServiceQuote.php) and the
-- customer pays it from the notification, the Track page, or their account.
-- A paid checkout confirms the request automatically -- PayMongo itself is
-- the proof of payment (PayMongo::syncHomeService). Same columns as the
-- salon appointments' PayMongo fields (migration 042).

ALTER TABLE home_service_requests
  ADD COLUMN paymongo_checkout_id VARCHAR(100) NULL DEFAULT NULL AFTER deposit_recorded_at,
  ADD COLUMN paymongo_checkout_url VARCHAR(500) NULL DEFAULT NULL AFTER paymongo_checkout_id,
  ADD COLUMN online_payment_token CHAR(32) NULL DEFAULT NULL AFTER paymongo_checkout_url,
  ADD KEY idx_home_service_paymongo_checkout (paymongo_checkout_id);
