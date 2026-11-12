-- Reviews are private feedback for the salon owner (Admin), never shown on
-- the public website. Mark existing reviews private and make it the default.
UPDATE feedback SET is_public = 0 WHERE is_public = 1;
ALTER TABLE feedback ALTER COLUMN is_public SET DEFAULT 0;
