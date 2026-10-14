-- Migration 037: Account Setup Tokens
--
-- Replaces the temp-password flow for Admin-created Staff/Cashier accounts.
-- saveStaff.php/saveCashier.php now insert an unusable random password and
-- create a row here instead; the account only becomes usable once the new
-- hire follows the emailed link to backend/auth/completeAccountSetup.php
-- and sets their own password. Shaped like `password_resets`
-- (database/capstone.sql:258-263), but keyed by user_id (not email, since
-- the account is created before the recipient ever interacts with the
-- system) and holds a hash of the token rather than the token itself --
-- unlike a short manually-typed OTP, this token rides in a URL, which can
-- end up in browser history/server logs, so only its hash is worth storing.

CREATE TABLE IF NOT EXISTS `account_setup_tokens` (
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_account_setup_tokens_users`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;
