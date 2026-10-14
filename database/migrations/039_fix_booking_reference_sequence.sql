-- Migration 039: Fix booking_reference_sequence
--
-- `booking_reference_sequence` was found to be a plain table with the same
-- column shape as a MariaDB SEQUENCE (next_not_cached_value, minimum_value,
-- ...), not an actual SEQUENCE object -- likely the result of a dump/restore
-- that didn't preserve `CREATE SEQUENCE` semantics. `next_booking_reference()`
-- (migration 024_booking_references.sql) calls `NEXT VALUE FOR
-- booking_reference_sequence`, which only works against a real SEQUENCE, so
-- every appointment/home-service insert was failing at the trigger. No real
-- bookings exist yet in this database (appointments/home_service_requests are
-- both empty), so it's safe to recreate it fresh starting at 1.

DROP TABLE IF EXISTS `booking_reference_sequence`;
CREATE SEQUENCE IF NOT EXISTS `booking_reference_sequence` START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE;
