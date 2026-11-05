-- Migration 058: manual Shift In / Break / Shift Out for the Staff Portal.
--
-- Adds two new tables and changes no existing data. Safe to run again.
--
-- 1. attendance_breaks: every break taken during one attendance row
--    (attendance.clock_in_time / clock_out_time stay the shift itself).
--    break_end NULL = the break is still running. Times are server time
--    (MySQL NOW(), Asia/Manila), never the browser's clock.
CREATE TABLE IF NOT EXISTS `attendance_breaks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_id` INT UNSIGNED NOT NULL,
  `break_start` DATETIME NOT NULL,
  `break_end` DATETIME NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_attendance_breaks_attendance` (`attendance_id`, `break_end`),
  CONSTRAINT `fk_attendance_breaks_attendance`
    FOREIGN KEY (`attendance_id`)
    REFERENCES `attendance` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- 2. staff_schedules: optional per-employee working days. When an employee
--    has no rows here, the Staff Portal shows the branch's operating hours
--    (branches.opening_time / closing_time / sunday_*) as their schedule.
--    day_of_week follows ISO-8601: 1 = Monday ... 7 = Sunday.
--    Staff can only view this; it is set by Admin (or directly in the DB).
CREATE TABLE IF NOT EXISTS `staff_schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `day_of_week` TINYINT UNSIGNED NOT NULL,
  `start_time` TIME NULL,
  `end_time` TIME NULL,
  `is_rest_day` BOOLEAN NOT NULL DEFAULT FALSE,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_staff_schedule_day` (`employee_id`, `day_of_week`),
  CONSTRAINT `fk_staff_schedules_employees`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;
