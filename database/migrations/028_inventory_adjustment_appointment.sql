-- Links a supply-usage log entry (inventory_adjustments) to the appointment
-- it was used for, so staff's "Supplies & Usage" page can show Booking
-- Reference / Client / Service without parsing free-text reasons.
ALTER TABLE inventory_adjustments
  ADD COLUMN appointment_id INT UNSIGNED NULL AFTER inventory_id,
  ADD CONSTRAINT fk_inventory_adjustments_appointment
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL;
