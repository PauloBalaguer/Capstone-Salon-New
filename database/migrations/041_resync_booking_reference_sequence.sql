-- Migration 041: Resync booking_reference_sequence with the registry
--
-- 039_fix_booking_reference_sequence.sql recreated `booking_reference_sequence`
-- fresh at 1, reasoning that no real bookings existed yet. That was true for
-- `appointments`/`home_service_requests`, but `booking_reference_registry`
-- (which is never purged -- see 024_booking_references.sql, "issued
-- references must never be reused") already held rows from earlier test
-- bookings, e.g. LM-DAR-2026-000003..000007. After 039, the sequence handed
-- out 1, 2, 3, ... again, and next_booking_reference() collided with those
-- already-issued codes: every appointment/home-service insert failed with
-- "Duplicate entry 'LM-DAR-2026-000003' for key 'PRIMARY'" once the
-- sequence caught back up to an existing registry entry.
--
-- This advances the sequence past the highest LM-<BRANCH>-<YEAR>-<NNNNNN>
-- suffix already in the registry (0 if none), so newly issued references
-- never collide with historical ones. Safe to rerun any time, in particular
-- after restoring a dump that recreated the sequence without preserving its
-- position (the same failure mode 039 hit).

-- SETVAL only accepts a literal for its value argument (MariaDB parses it
-- as part of the sequence DML grammar, not as a normal function call), so
-- the computed max has to be spliced in via PREPARE/EXECUTE rather than
-- passed as a user variable directly.
SET @next_free := (
  SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference_code, '-', -1) AS UNSIGNED)), 0)
  FROM booking_reference_registry
  WHERE reference_code REGEXP '^LM-[A-Z0-9]+-[0-9]{4}-[0-9]+$'
);
SET @resync_sql := CONCAT('SELECT SETVAL(booking_reference_sequence, ', @next_free, ', 1)');
PREPARE resync_stmt FROM @resync_sql;
EXECUTE resync_stmt;
DEALLOCATE PREPARE resync_stmt;
