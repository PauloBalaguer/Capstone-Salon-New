-- Migration 065: home service prices, travel fees and a saved estimate.
--
-- The request forms show a "starts at" price per service and a live
-- estimate: services x clients (or the wedding package price) + travel fee.
-- The server recalculates it on submit (HomeServiceRequest::estimate()) and
-- saves it on the request, so the admin's quote starts from it.
--
-- PLACEHOLDER PRICES: every amount below is an example the salon still has
-- to confirm. The admin edits them in Settings > Event durations.
-- Adds two tables and two empty columns; changes no existing data. Safe to run again.

CREATE TABLE IF NOT EXISTS `home_service_rates` (
  `service_name` VARCHAR(60) NOT NULL COMMENT 'HomeServiceRequest::SERVICES',
  `price_per_client` DECIMAL(10,2) NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`service_name`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

INSERT IGNORE INTO `home_service_rates` (`service_name`, `price_per_client`) VALUES
  ('Hair Styling', 500.00),
  ('Event Makeup', 800.00),
  ('Brow Services', 300.00),
  ('Lash Services', 400.00),
  ('Hair & Makeup Package', 1200.00);

-- One fee per Albay city/municipality (PSGC code, as in the address form).
-- Placeholder zones: Daraga and Legazpi (where the branches are) free,
-- nearby towns 300, the rest of Albay 600.
CREATE TABLE IF NOT EXISTS `home_service_travel_fees` (
  `city_code` CHAR(9) NOT NULL,
  `city_name` VARCHAR(100) NOT NULL,
  `fee` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`city_code`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

INSERT IGNORE INTO `home_service_travel_fees` (`city_code`, `city_name`, `fee`) VALUES
  ('050503000', 'Daraga', 0.00),
  ('050506000', 'City of Legazpi', 0.00),
  ('050502000', 'Camalig', 300.00),
  ('050516000', 'Santo Domingo', 300.00),
  ('050504000', 'Guinobatan', 300.00),
  ('050511000', 'Manito', 300.00),
  ('050501000', 'Bacacay', 600.00),
  ('050505000', 'Jovellar', 600.00),
  ('050507000', 'Libon', 600.00),
  ('050508000', 'City of Ligao', 600.00),
  ('050509000', 'Malilipot', 600.00),
  ('050510000', 'Malinao', 600.00),
  ('050512000', 'Oas', 600.00),
  ('050513000', 'Pio Duran', 600.00),
  ('050514000', 'Polangui', 600.00),
  ('050515000', 'Rapu-Rapu', 600.00),
  ('050517000', 'City of Tabaco', 600.00),
  ('050518000', 'Tiwi', 600.00);

-- The estimate saved at submit (NULL on older requests).
ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `travel_fee` DECIMAL(10,2) NULL AFTER `quote_price`,
  ADD COLUMN IF NOT EXISTS `estimated_total` DECIMAL(10,2) NULL AFTER `travel_fee`;
