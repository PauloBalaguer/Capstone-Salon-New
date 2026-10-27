-- Migration 054: realistic event / home-service durations
--
-- Every home service or event used to block its team for a flat 120 minutes
-- (Scheduling::HOME_SERVICE_DURATION_MINUTES). Each wedding package and each
-- event type now has its own times, editable by the admin:
--   travel_minutes  -- salon to venue
--   setup_minutes   -- unpacking / station setup at the venue
--   service_minutes -- the hair & make-up work itself
-- The team is blocked from (preferred time - travel - setup) until
-- (preferred time + service + the 30-minute cleaning buffer); see
-- Scheduling::homeServiceWindow(). A blank service_minutes falls back to 120.
--
-- !! PLACEHOLDER VALUES !! The minutes seeded below are estimates only. The
-- salon still has to confirm the real times. durations_confirmed stays 0 (the
-- admin screens show a "Placeholder" badge) until an admin ticks
-- "Confirmed by the salon" and saves:
--   Wedding packages -> Admin > Promotions > Wedding Packages > Edit
--   Other event types -> Admin > Settings > Event & Home Service Durations

ALTER TABLE `wedding_packages`
  ADD COLUMN IF NOT EXISTS `travel_minutes` SMALLINT UNSIGNED NULL AFTER `reservation_fee`,
  ADD COLUMN IF NOT EXISTS `setup_minutes` SMALLINT UNSIGNED NULL AFTER `travel_minutes`,
  ADD COLUMN IF NOT EXISTS `service_minutes` SMALLINT UNSIGNED NULL AFTER `setup_minutes`,
  ADD COLUMN IF NOT EXISTS `durations_confirmed` BOOLEAN NOT NULL DEFAULT FALSE AFTER `service_minutes`;

-- PLACEHOLDER: bride + groom + 2 heads (A, B), plus a reception look (C),
-- plus 7 heads (D).
UPDATE `wedding_packages` SET `travel_minutes` = 60, `setup_minutes` = 30, `service_minutes` = 240
  WHERE `package_name` = 'Package A' AND `service_minutes` IS NULL;
UPDATE `wedding_packages` SET `travel_minutes` = 60, `setup_minutes` = 30, `service_minutes` = 270
  WHERE `package_name` = 'Package B' AND `service_minutes` IS NULL;
UPDATE `wedding_packages` SET `travel_minutes` = 60, `setup_minutes` = 30, `service_minutes` = 330
  WHERE `package_name` = 'Package C' AND `service_minutes` IS NULL;
UPDATE `wedding_packages` SET `travel_minutes` = 60, `setup_minutes` = 45, `service_minutes` = 480
  WHERE `package_name` = 'Package D' AND `service_minutes` IS NULL;

-- One row per non-wedding event type offered on the booking forms. An event
-- typed in under "Other (please specify)" uses the 'Other' row.
CREATE TABLE IF NOT EXISTS `event_type_durations` (
  `event_type` VARCHAR(150) NOT NULL,
  `travel_minutes` SMALLINT UNSIGNED NULL,
  `setup_minutes` SMALLINT UNSIGNED NULL,
  `service_minutes` SMALLINT UNSIGNED NULL,
  `durations_confirmed` BOOLEAN NOT NULL DEFAULT FALSE,
  `display_order` INT NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_type`)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- PLACEHOLDER values -- see the note at the top.
INSERT IGNORE INTO `event_type_durations` (`event_type`, `travel_minutes`, `setup_minutes`, `service_minutes`, `display_order`) VALUES
('Standard Home Service', 30, 15, 90, 1),
('Group Package', 30, 20, 180, 2),
('Debut', 45, 30, 180, 3),
('Graduation', 30, 15, 90, 4),
('Gala', 30, 20, 120, 5),
('Personal Event', 30, 15, 90, 6),
('Photoshoot', 30, 20, 120, 7),
('Other', 30, 15, 120, 8);
