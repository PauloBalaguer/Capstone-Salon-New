-- Migration 012: staff-service specialization
-- Panel feedback: should a staff member be restricted to specific services
-- (e.g. nails only)? Lets an admin declare which services each staff member
-- is qualified to perform. Booking/assignment code paths require a stylist
-- to cover every service on a booking (not just one) before they can be
-- assigned -- see backend/customer/submitBooking.php, submitGuestBooking.php,
-- cashier/createWalkIn.php, admin/assignStaff.php, cashier/assignStaff.php.

CREATE TABLE IF NOT EXISTS `staff_services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `fk_staff_services_employees_idx` (`employee_id` ASC),
  INDEX `fk_staff_services_services_idx` (`service_id` ASC),
  UNIQUE INDEX `idx_staff_service_unique` (`employee_id` ASC, `service_id` ASC),
  CONSTRAINT `fk_staff_services_employees`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_staff_services_services`
    FOREIGN KEY (`service_id`)
    REFERENCES `services` (`id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4;
