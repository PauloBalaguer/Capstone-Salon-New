-- Logins for containers, which can't keep session files (SESSION_DRIVER=database).
-- Adds one empty table and changes no existing data. Safe to run again.

CREATE TABLE IF NOT EXISTS `php_sessions` (
  `id` CHAR(64) NOT NULL COMMENT 'SHA-256 of the session ID, never the ID itself',
  `data` MEDIUMBLOB NOT NULL,
  `last_activity` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_php_sessions_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
