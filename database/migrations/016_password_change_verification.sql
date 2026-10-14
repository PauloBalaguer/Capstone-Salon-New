-- Migration 016: email OTP verification for in-app password changes
--
-- backend/customer/changePassword.php and backend/employee/changePassword.php
-- previously updated users.password immediately once the current password
-- checked out. They now only stage the change: a 6-digit code is emailed to
-- the account's real address, and backend/customer/confirmPasswordChange.php
-- / backend/employee/confirmPasswordChange.php apply the new password only
-- after that code is confirmed. Mirrors the shape of `password_resets` /
-- `booking_verifications`, keyed by user_id since the requester is already
-- an authenticated session rather than a bare email.

CREATE TABLE IF NOT EXISTS `password_change_verifications` (
  `user_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_password_change_verifications_users`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;
