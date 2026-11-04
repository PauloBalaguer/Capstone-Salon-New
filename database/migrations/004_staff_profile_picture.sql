-- Migration: profile picture for Staff accounts, mirroring the column
-- `customers` already got in 002_customer_backend.sql. Staff has no
-- equivalent yet since the staff portal (pages/staff/*.html) was added
-- after that migration.

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `profile_picture` LONGTEXT NULL AFTER `phone_number`;
