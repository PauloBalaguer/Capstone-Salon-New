-- Migration 014: home service staff assignment
-- Objective: "integrate a home service booking system that allows
-- administrators to schedule, organize, and assign staff for off-site
-- appointments." home_service_requests had no way to assign a staff member
-- at all, and no admin page surfaced these requests -- this adds the column
-- the admin panel needs (backend/admin/getDashboardData.php,
-- assignStaff.php, updateBookingStatus.php, pages/admin/appointments.html).
-- Off-site jobs aren't tied to one branch, so unlike appointments.employee_id
-- this isn't paired with a branch_id/staff_services qualification check --
-- any active employee can be assigned.

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `employee_id` INT UNSIGNED NULL AFTER `customer_id`;
ALTER TABLE `home_service_requests`
  ADD INDEX IF NOT EXISTS `fk_home_service_requests_employees_idx` (`employee_id` ASC);

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_home_service_requests_employees');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `home_service_requests` ADD CONSTRAINT `fk_home_service_requests_employees` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
