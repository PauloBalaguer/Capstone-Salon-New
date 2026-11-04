-- Shared customer card across every branch. Starts with new checkouts;
-- historical payments and existing point balances are left unchanged.
ALTER TABLE services ADD COLUMN IF NOT EXISTS loyalty_treatment_eligible TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS loyalty_stamps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  appointment_id INT NOT NULL,
  branch_id INT NOT NULL,
  service_amount DECIMAL(10,2) NOT NULL,
  earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stamp_appointment (appointment_id),
  KEY ix_stamp_customer (customer_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS loyalty_rewards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  earned_stamp_id BIGINT UNSIGNED NOT NULL,
  reward_type ENUM('discount20','treatment') NOT NULL,
  redeemed_appointment_id INT NULL,
  redeemed_service_id INT NULL,
  discount_amount DECIMAL(10,2) NULL,
  earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  redeemed_at DATETIME NULL,
  UNIQUE KEY uq_reward_stamp (earned_stamp_id),
  UNIQUE KEY uq_reward_checkout (redeemed_appointment_id),
  KEY ix_reward_customer (customer_id, redeemed_at)
) ENGINE=InnoDB;
