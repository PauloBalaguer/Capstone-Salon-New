-- One stylist per service.
--
-- A booking with several services (e.g. Haircut + Facial) can now give each
-- service its own qualified stylist. Services run back-to-back in the order
-- they were booked (appointment_services.id order), so each stylist is only
-- busy for their own service's window -- see Scheduling::staffBusyWindows().
--
-- appointment_services.employee_id NULL = the service is done by the
-- booking's main stylist (appointments.employee_id), which is how every
-- booking made before this migration keeps working unchanged.
-- appointments.employee_id stays the stylist of the FIRST service.

ALTER TABLE appointment_services
  ADD COLUMN employee_id INT(10) UNSIGNED NULL DEFAULT NULL AFTER service_id,
  ADD KEY idx_appointment_services_employee (employee_id),
  ADD CONSTRAINT fk_appointment_services_employees FOREIGN KEY (employee_id)
    REFERENCES employees (id) ON DELETE SET NULL ON UPDATE CASCADE;

-- Tidy the Daraga menu: its only uncategorised service floated above every
-- category in the booking list.
UPDATE services
SET category = 'Salon Services / Hair Services'
WHERE service_name = 'Signature Haircut' AND (category IS NULL OR category = '');
