-- 056: Attach guest bookings to the customer's account.
--
-- A guest booking made with an email that belongs to a registered customer
-- used to create a separate, account-less `customers` row. Those bookings
-- never showed on the customer's dashboard (Total Services Availed, My
-- Appointments, loyalty, reviews), because the portal only reads the row
-- linked to the login (customers.user_id).
--
-- For every such guest row this migration:
--   * moves its appointments, home service requests, feedback, loyalty
--     stamps/rewards and waitlist entries to the account's customer row;
--   * keeps the name/phone the guest typed in the appointment's notes;
--   * adds the guest row's loyalty points to the account;
--   * keeps the guest row (nothing is deleted) but marks it merged and clears
--     its email, so the migration is safe to run again.
--
-- From now on submitGuestBooking.php reuses the account's customer row
-- directly, so new duplicates are not created.

START TRANSACTION;

-- Guest row -> account customer row, matched on email (case-insensitive).
DROP TEMPORARY TABLE IF EXISTS merge_guest_customers;
CREATE TEMPORARY TABLE merge_guest_customers AS
SELECT g.id AS guest_id, acc.id AS account_id,
       TRIM(CONCAT_WS(' ', g.first_name, g.last_name)) AS guest_name,
       NULLIF(TRIM(g.phone_number), '') AS guest_phone,
       g.loyalty_points AS guest_points
FROM customers g
JOIN users u ON u.email IS NOT NULL AND LOWER(u.email) = LOWER(g.email)
JOIN customers acc ON acc.user_id = u.id
WHERE g.user_id IS NULL
  AND g.email IS NOT NULL AND g.email <> ''
  AND g.id <> acc.id;

-- Keep who the guest said they were before the booking moves to the account
-- (guest bookings normally already note this as "Guest booking for ...").
UPDATE appointments a
JOIN merge_guest_customers m ON m.guest_id = a.customer_id
SET a.notes = IF(a.notes LIKE 'Guest booking for %', a.notes, CONCAT_WS('\n', NULLIF(a.notes, ''),
      CONCAT('Booked as guest: ', NULLIF(m.guest_name, ''), IF(m.guest_phone IS NULL, '', CONCAT(' (', m.guest_phone, ')'))))),
    a.customer_id = m.account_id;

UPDATE home_service_requests r JOIN merge_guest_customers m ON m.guest_id = r.customer_id SET r.customer_id = m.account_id;
UPDATE feedback f              JOIN merge_guest_customers m ON m.guest_id = f.customer_id SET f.customer_id = m.account_id;
UPDATE loyalty_stamps s        JOIN merge_guest_customers m ON m.guest_id = s.customer_id SET s.customer_id = m.account_id;
UPDATE loyalty_rewards r       JOIN merge_guest_customers m ON m.guest_id = r.customer_id SET r.customer_id = m.account_id;
UPDATE stylist_waitlist w      JOIN merge_guest_customers m ON m.guest_id = w.customer_id SET w.customer_id = m.account_id;

-- Loyalty points earned on guest bookings belong to the account too.
UPDATE customers acc
JOIN (SELECT account_id, SUM(guest_points) AS pts FROM merge_guest_customers GROUP BY account_id) t ON t.account_id = acc.id
SET acc.loyalty_points = acc.loyalty_points + t.pts;

-- Leave the guest rows in place (history), but empty and clearly marked.
UPDATE customers g
JOIN merge_guest_customers m ON m.guest_id = g.id
SET g.notes = CONCAT_WS('\n', NULLIF(g.notes, ''), CONCAT('Merged into customer #', m.account_id, ' (', g.email, ') by migration 056.')),
    g.loyalty_points = 0,
    g.email = NULL;

DROP TEMPORARY TABLE merge_guest_customers;

COMMIT;
