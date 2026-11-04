-- Migration 010: Guest booking email OTP verification
-- Adds a table for the landing-page guest appointment booking flow to
-- verify the guest controls the email they submitted before an
-- appointment is created. Mirrors the shape of `password_resets`.

CREATE TABLE IF NOT EXISTS booking_verifications (
  email VARCHAR(255) NOT NULL PRIMARY KEY,
  code VARCHAR(10) NOT NULL,
  expires_at DATETIME NOT NULL
);
