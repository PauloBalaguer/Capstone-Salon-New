-- Home service remaining balance.
--
-- Every peso paid on a home service is now a row in home_service_payments:
-- the reservation fee (kind 'deposit') and the remaining balance (kind
-- 'balance', cash/GCash/Maya recorded by the admin, or online via a PayMongo
-- link). payment_status becomes 'Fully Paid' once the payments cover the
-- quote. (payments is tied 1:1 to salon appointments, so it can't hold these.)

CREATE TABLE IF NOT EXISTS home_service_payments (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  home_service_request_id INT(10) UNSIGNED NOT NULL,
  kind ENUM('deposit', 'balance') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method VARCHAR(20) NOT NULL,
  reference VARCHAR(255) NULL DEFAULT NULL,
  recorded_by INT(10) UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hs_payments_request (home_service_request_id),
  CONSTRAINT fk_hs_payments_request FOREIGN KEY (home_service_request_id)
    REFERENCES home_service_requests (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_hs_payments_user FOREIGN KEY (recorded_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- PayMongo checkout for the balance (separate from the DP's checkout).
ALTER TABLE home_service_requests
  ADD COLUMN balance_checkout_id VARCHAR(100) NULL DEFAULT NULL AFTER online_payment_token,
  ADD COLUMN balance_checkout_url VARCHAR(500) NULL DEFAULT NULL AFTER balance_checkout_id,
  ADD COLUMN balance_payment_token CHAR(32) NULL DEFAULT NULL AFTER balance_checkout_url,
  ADD KEY idx_home_service_balance_checkout (balance_checkout_id);

-- Deposits already paid become payment rows.
INSERT INTO home_service_payments (home_service_request_id, kind, amount, method, reference, recorded_by, created_at)
SELECT h.id, 'deposit', h.deposit_amount, COALESCE(h.deposit_method, 'Cash'), h.deposit_reference, h.deposit_recorded_by,
       COALESCE(h.deposit_recorded_at, NOW())
FROM home_service_requests h
WHERE h.deposit_paid = 1 AND h.deposit_amount > 0
  AND NOT EXISTS (SELECT 1 FROM home_service_payments p WHERE p.home_service_request_id = h.id AND p.kind = 'deposit');
