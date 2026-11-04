-- Migration 018: admin-managed wedding packages
--
-- The public landing page's "Wedding Packages" section (index.html) used to
-- be 4 hardcoded cards. This gives admins a CRUD screen (alongside Featured
-- Promotions & Campaigns) to edit those packages and add new ones, which
-- index.html then renders dynamically via backend/public/getWeddingPackages.php.
--
-- `features` is stored as newline-delimited text (one bullet per admin
-- textarea line) rather than a child table, matching how the rest of this
-- schema keeps small list-like fields as plain text/arrays instead of joins.
-- `style` captures the 3 visual tiers the original hardcoded cards used
-- (Package A/B plain, Package C tinted "highlight", Package D solid "premium").

CREATE TABLE IF NOT EXISTS `wedding_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `reservation_fee` DECIMAL(10,2) NULL,
  `features` TEXT NOT NULL,
  `style` ENUM('Plain','Highlight','Premium') NOT NULL DEFAULT 'Plain',
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_wedding_package_name_unique` (`package_name` ASC)
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

INSERT INTO `wedding_packages` (`package_name`, `price`, `reservation_fee`, `features`, `style`, `display_order`, `is_active`) VALUES
('Package A', 5000.00, NULL,
  'Hairstyle and traditional make-up for the bride prep look and ceremony look, with unlimited touch-up until she leaves the hotel for the ceremony.\nGrooming for the groom (only if he is at the same preparation venue).\nHairstyle and traditional make-up for 2 heads.',
  'Plain', 1, TRUE),
('Package B', 8000.00, NULL,
  'Hairstyle and airbrush make-up for the bride prep look and ceremony look, with unlimited touch-up until she leaves the hotel for the ceremony.\nGrooming for the groom (only if he is at the same preparation venue).\nHairstyle and traditional make-up for 2 heads.',
  'Plain', 2, TRUE),
('Package C', 10000.00, NULL,
  'Hairstyle and airbrush make-up for the bride prep look and ceremony look, with unlimited touch-up until she leaves the hotel for the ceremony.\nReception look / change look for the bride.\nGrooming for the groom (only if he is at the same preparation venue).\nHairstyle and traditional make-up for 2 heads.',
  'Highlight', 3, TRUE),
('Package D', 12000.00, 2000.00,
  'Hairstyle and airbrush make-up for the bride prep look and ceremony look, with unlimited touch-up until she leaves the hotel for the ceremony.\nReception look / change look for the bride.\nGrooming for the groom (only if he is at the same preparation venue).\nHairstyle and traditional make-up for 7 heads.',
  'Premium', 4, TRUE)
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);
