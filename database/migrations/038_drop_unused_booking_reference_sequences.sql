-- Migration 038: Drop unused booking_reference_sequences table
--
-- Leftover from an earlier, abandoned per-scope/per-year counter design for
-- booking reference numbers (scope_key, year, last_number). Superseded by
-- the real MariaDB SEQUENCE `booking_reference_sequence` (singular) paired
-- with `booking_reference_registry` -- see migration 024_booking_references.sql,
-- which is what actually generates reference codes today. This table was
-- always empty and has zero references anywhere in the codebase.

DROP TABLE IF EXISTS `booking_reference_sequences`;
