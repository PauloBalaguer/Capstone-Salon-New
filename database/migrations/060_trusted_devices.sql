-- Migration 060: "Remember me" recognises a customer's browser after sign out.
--
-- The browser holds a long random secret in a protected cookie; only its hash is
-- stored here, so reading this table doesn't give anyone a usable login. No
-- password is stored. password_fingerprint is a hash of users.password when the
-- device was remembered: changing the password, by any route, ends the device.
--
-- Adds one empty table and changes no existing data. Safe to run again.
CREATE TABLE IF NOT EXISTS `trusted_devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `password_fingerprint` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_trusted_devices_token` (`token_hash`),
  INDEX `idx_trusted_devices_expires` (`expires_at`),
  CONSTRAINT `fk_trusted_devices_users`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;
