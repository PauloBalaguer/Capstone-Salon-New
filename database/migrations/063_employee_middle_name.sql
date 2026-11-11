-- Keep middle names separate from first and last names in staff profiles.
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) NULL DEFAULT NULL AFTER first_name;
