-- Migration: switch forgot-password from an emailed reset link/token to an
-- emailed 6-digit code, matching the registration OTP flow (see
-- backend/auth/forgotPassword.php and backend/auth/resetPassword.php).
-- This column was already renamed in database/capstone.sql's CREATE TABLE
-- for fresh installs, but existing databases provisioned before that need
-- the change applied explicitly.

ALTER TABLE `password_resets`
  CHANGE COLUMN `token` `code` VARCHAR(10) NOT NULL;
