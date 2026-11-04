-- Migration: add email OTP verification to registration.
--
-- backend/auth/register.php now generates a 6-digit OTP on signup and keeps
-- the account inactive (is_active = 0) until it's confirmed via
-- backend/auth/verifyOtp.php (see also backend/auth/resendOtp.php and
-- pages/login/verify-otp.html). These columns were already present in
-- database/capstone.sql's CREATE TABLE for fresh installs, but existing
-- databases provisioned before that need them added explicitly.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `otp_code` VARCHAR(10) NULL AFTER `is_active`;
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `otp_expiry` TIMESTAMP NULL AFTER `otp_code`;
