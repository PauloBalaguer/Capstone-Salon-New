-- Migration 022: per-service reservation payment requirement
--
-- The Customer Portal's Services & Promos page previously had no
-- data-driven way to tell the customer whether a service needs a partial
-- ("Half Payment", the salon's standard 20%-of-total deposit policy) or a
-- "Full Payment" reservation -- every service showed the same generic
-- deposit note regardless of what it actually was. This adds a real column
-- so that distinction comes from the service record itself, not a
-- hardcoded value in the frontend.
--
-- Existing rows default to 'Half Payment'. Bridal/Wedding services are
-- flipped to 'Full Payment' as a one-time seed default below; adjust
-- per-service afterwards as needed (there is currently no admin UI field
-- for this column -- it can be edited directly in the `services` table
-- until one is added to backend/admin/saveService.php).

ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `payment_requirement` ENUM('Half Payment', 'Full Payment') NOT NULL DEFAULT 'Half Payment' AFTER `loyalty_multiplier`;

UPDATE `services`
  SET `payment_requirement` = 'Full Payment'
  WHERE `category` IN ('Bridal', 'Wedding')
     OR `service_name` LIKE '%Bridal%'
     OR `service_name` LIKE '%Wedding%';
