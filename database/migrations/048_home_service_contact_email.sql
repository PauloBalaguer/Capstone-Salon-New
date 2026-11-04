-- Guests track and reschedule a home service with reference + EMAIL (not the
-- mobile number), and get its updates by email. The guest form now requires
-- an email; it's stored on the request itself (a guest customer record is
-- shared by phone number, so one customer row can't hold per-request emails).

ALTER TABLE home_service_requests
  ADD COLUMN contact_email VARCHAR(255) NULL DEFAULT NULL AFTER customer_id,
  ADD KEY idx_home_service_contact_email (contact_email);

-- Backfill from the "Email: ..." line older guest requests kept in `requests`.
UPDATE home_service_requests
SET contact_email = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(requests, 'Email: ', -1), '\n', 1))
WHERE contact_email IS NULL AND requests LIKE '%Email: %';

-- Requests from customer accounts use the account's login email.
UPDATE home_service_requests h
JOIN customers c ON c.id = h.customer_id
JOIN users u ON u.id = c.user_id
SET h.contact_email = u.email
WHERE h.contact_email IS NULL;
