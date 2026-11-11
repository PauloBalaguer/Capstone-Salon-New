-- Migration 062: 24/7 booking requests -- staff leave, branch holidays,
-- home-service responsible branch and return travel.
--
-- Adds tables/columns only; changes no existing booking data. Safe to run
-- again (IF NOT EXISTS everywhere).
--
-- 1. staff_leave: date-specific leave for one employee (inclusive range).
--    An approved leave day blocks every salon booking and home visit for
--    that stylist, even when the branch is open.
CREATE TABLE IF NOT EXISTS `staff_leave` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `reason` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_staff_leave_employee_dates` (`employee_id`, `start_date`, `end_date`),
  CONSTRAINT `fk_staff_leave_employees`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- 2. branch_holidays: one row per branch per date.
--      closed        -> Closed all day
--      special_hours -> open_time .. close_time replace that day's hours
--      open          -> Open as usual (recorded, no change)
--    NOT daily_closures: that table is the cashier end-of-day lock.
CREATE TABLE IF NOT EXISTS `branch_holidays` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `holiday_date` DATE NOT NULL,
  `holiday_type` ENUM('closed', 'special_hours', 'open') NOT NULL DEFAULT 'closed',
  `open_time` TIME NULL,
  `close_time` TIME NULL,
  `name` VARCHAR(120) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_branch_holiday_date` (`branch_id`, `holiday_date`),
  CONSTRAINT `fk_branch_holidays_branches`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;

-- 3. Responsible branch for a home service request. NULL on older rows:
--    Admin sees "Branch not set" and can fix it.
ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `branch_id` INT UNSIGNED NULL AFTER `customer_id`,
  ADD INDEX IF NOT EXISTS `idx_home_service_branch` (`branch_id`);

-- 4. Which branches accept home service requests (default: all).
ALTER TABLE `branches`
  ADD COLUMN IF NOT EXISTS `offers_home_service` BOOLEAN NOT NULL DEFAULT TRUE AFTER `slot_limit`;

-- 5. Return travel (venue back to salon). NULL = same as travel_minutes.
--    Seeded durations from migration 054 are still placeholders
--    (durations_confirmed = 0) until the salon confirms them.
ALTER TABLE `wedding_packages`
  ADD COLUMN IF NOT EXISTS `return_travel_minutes` SMALLINT UNSIGNED NULL AFTER `service_minutes`;
ALTER TABLE `event_type_durations`
  ADD COLUMN IF NOT EXISTS `return_travel_minutes` SMALLINT UNSIGNED NULL AFTER `service_minutes`;

-- 6. Leave/holiday follow-up flag on confirmed bookings. Set when Admin
--    saves a leave or closure that affects the booking; the booking itself
--    is not moved or cancelled.
ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `needs_followup` VARCHAR(255) NULL;
ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `needs_followup` VARCHAR(255) NULL;
