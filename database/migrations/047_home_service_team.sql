-- Home service teams + customer rescheduling.
--
-- A home service (e.g. a wedding for 7 clients) can now have several
-- stylists. home_service_staff holds the whole team;
-- home_service_requests.employee_id stays as the team lead (first picked) so
-- older code that reads one stylist keeps working.

CREATE TABLE IF NOT EXISTS home_service_staff (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  home_service_request_id INT(10) UNSIGNED NOT NULL,
  employee_id INT(10) UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY idx_home_service_staff_unique (home_service_request_id, employee_id),
  KEY idx_home_service_staff_employee (employee_id),
  CONSTRAINT fk_home_service_staff_request FOREIGN KEY (home_service_request_id)
    REFERENCES home_service_requests (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_home_service_staff_employee FOREIGN KEY (employee_id)
    REFERENCES employees (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Existing single assignments become one-person teams.
INSERT IGNORE INTO home_service_staff (home_service_request_id, employee_id)
SELECT id, employee_id FROM home_service_requests WHERE employee_id IS NOT NULL;

-- How many times the customer moved the date online (for the admin's eyes).
ALTER TABLE home_service_requests
  ADD COLUMN reschedule_count INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER preferred_time;
