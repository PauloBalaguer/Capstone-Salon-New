-- Migration 019: per-service loyalty weighting (customer scoring)
--
-- Panel feedback asked for "customer scoring" where "different services
-- might affect the scoring" -- until now every service earned loyalty
-- points at the same flat rate (1 point per PHP20 spent), so a bridal
-- package and a basic trim scored identically per peso. This adds a
-- per-service multiplier the admin can set from the Service Catalog
-- (backend/admin/saveService.php) so specific services can be weighted
-- to earn more (or fewer) points than their price alone would imply.
-- backend/admin/completeCheckout.php and backend/cashier/payment.php
-- apply it as a weighted average across the appointment's services.

ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `loyalty_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER `price`;
