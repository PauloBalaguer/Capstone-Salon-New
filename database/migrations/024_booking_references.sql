-- Step 5. MariaDB 10.3+ (the project's XAMPP database).
-- Run with the application paused. Keep existing public references valid.
-- The global sequence is independent of booking primary keys and never cycles.
-- Sequence values survive rollback; gaps are intentional. Never reset it or
-- purge the registry, including when deleting/cancelling bookings.
CREATE SEQUENCE IF NOT EXISTS booking_reference_sequence START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;

CREATE TABLE IF NOT EXISTS booking_reference_registry (
  reference_code VARCHAR(48) NOT NULL PRIMARY KEY,
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DELIMITER //
CREATE OR REPLACE FUNCTION next_booking_reference(branch_id_value INT UNSIGNED)
RETURNS VARCHAR(48) NOT DETERMINISTIC MODIFIES SQL DATA
BEGIN
  DECLARE branch_code VARCHAR(16) DEFAULT 'HOM';
  DECLARE sequence_value BIGINT;
  DECLARE public_reference VARCHAR(48);
  IF branch_id_value IS NOT NULL THEN
    SELECT CASE branch_key WHEN 'daraga' THEN 'DAR' WHEN 'yashano' THEN 'YAS'
      WHEN 'cabangan' THEN 'CAB' ELSE CONCAT('BR', id) END
      INTO branch_code FROM branches WHERE id = branch_id_value;
  END IF;
  SET sequence_value = NEXT VALUE FOR booking_reference_sequence;
  SET public_reference = CONCAT('LM-', branch_code, '-', YEAR(UTC_TIMESTAMP() + INTERVAL 8 HOUR), '-',
    LPAD(sequence_value, GREATEST(6, CHAR_LENGTH(sequence_value)), '0'));
  RETURN public_reference;
END//
DELIMITER ;

ALTER TABLE appointments MODIFY reference_code VARCHAR(48) NULL;
ALTER TABLE home_service_requests MODIFY reference_code VARCHAR(48) NULL;
UPDATE appointments SET reference_code = next_booking_reference(branch_id) WHERE reference_code IS NULL OR reference_code = '';
UPDATE home_service_requests SET reference_code = next_booking_reference(NULL) WHERE reference_code IS NULL OR reference_code = '';

-- Preserve historical reservations on repeated migration runs.
INSERT INTO booking_reference_registry (reference_code)
SELECT reference_code FROM appointments WHERE reference_code NOT IN (SELECT reference_code FROM booking_reference_registry);
INSERT INTO booking_reference_registry (reference_code)
SELECT reference_code FROM home_service_requests WHERE reference_code NOT IN (SELECT reference_code FROM booking_reference_registry);

ALTER TABLE appointments MODIFY reference_code VARCHAR(48) NOT NULL DEFAULT '',
  ADD UNIQUE INDEX IF NOT EXISTS idx_reference_code_unique (reference_code);
ALTER TABLE home_service_requests MODIFY reference_code VARCHAR(48) NOT NULL DEFAULT '',
  ADD UNIQUE INDEX IF NOT EXISTS idx_home_service_reference_code_unique (reference_code);

DELIMITER //
CREATE OR REPLACE TRIGGER appointments_reference_insert BEFORE INSERT ON appointments FOR EACH ROW
BEGIN
  IF NEW.reference_code IS NULL OR NEW.reference_code = '' THEN
    SET NEW.reference_code = next_booking_reference(NEW.branch_id);
  END IF;
  INSERT INTO booking_reference_registry (reference_code) VALUES (NEW.reference_code);
END//
CREATE OR REPLACE TRIGGER home_service_reference_insert BEFORE INSERT ON home_service_requests FOR EACH ROW
BEGIN
  IF NEW.reference_code IS NULL OR NEW.reference_code = '' THEN
    SET NEW.reference_code = next_booking_reference(NULL);
  END IF;
  INSERT INTO booking_reference_registry (reference_code) VALUES (NEW.reference_code);
END//
CREATE OR REPLACE TRIGGER appointments_reference_immutable BEFORE UPDATE ON appointments FOR EACH ROW
BEGIN
  IF NOT (NEW.reference_code <=> OLD.reference_code) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking references cannot be changed';
  END IF;
END//
CREATE OR REPLACE TRIGGER home_service_reference_immutable BEFORE UPDATE ON home_service_requests FOR EACH ROW
BEGIN
  IF NOT (NEW.reference_code <=> OLD.reference_code) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking references cannot be changed';
  END IF;
END//
CREATE OR REPLACE TRIGGER booking_registry_no_delete BEFORE DELETE ON booking_reference_registry FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued booking references must never be reused';
END//
CREATE OR REPLACE TRIGGER booking_registry_no_update BEFORE UPDATE ON booking_reference_registry FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued booking references cannot be changed';
END//
DELIMITER ;
