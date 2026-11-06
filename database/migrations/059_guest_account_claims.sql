-- Migration 059: signing up with a mobile number already used for guest bookings.
--
-- customers.phone_number is unique, and a guest booking creates a customers row
-- with no login. So a new account can't take that number until its owner proves
-- it with a texted code. Until then the signup waits here and the account stays
-- inactive; the row is deleted once the account is linked.
--
-- Adds one empty table and changes no existing data. Safe to run again.
CREATE TABLE IF NOT EXISTS `guest_account_claims` (
  `user_id` INT UNSIGNED NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `notify_sms` TINYINT(1) NOT NULL DEFAULT 1,
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `token_hash` CHAR(64) NULL COMMENT 'SHA-256 of the token that lets this browser finish the step',
  `code` CHAR(6) NULL,
  `code_expires_at` DATETIME NULL,
  `code_sent_at` DATETIME NULL,
  `code_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  INDEX `idx_guest_claims_phone` (`phone_number`),
  CONSTRAINT `fk_guest_claims_users`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;
