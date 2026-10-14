# Step 5: public booking references

New salon bookings use `LM-DAR-2026-000145`, `LM-YAS-2026-000146`, or
`LM-CAB-2026-000147`. Home services use `LM-HOM-2026-000148` because they
have no branch. The year is the issue year in Philippine time. The sequence
is global across booking types, branches, and years; six digits is a minimum,
not a limit. Internal table primary keys are unchanged.

Apply `migrations/024_booking_references.sql` to an existing database while
booking writes are paused. The same SQL is included in `capstone.sql` for
fresh installations. Requires MariaDB 10.3+; tested on XAMPP MariaDB 10.4.32.

Database insert triggers allocate references when the backend inserts a blank
or omitted reference, and each endpoint reads the stored value after inserting.
Existing public references are preserved, while missing references are backfilled.
The immutable registry reserves issued codes across both tables even after a
booking is deleted. Unique indexes and registry constraints reject duplicates.
Updates cannot change a booking's reference. Cancellation and branch changes
therefore preserve the original reference.

Sequence allocation survives rollback. Gaps are expected. Never reset the
sequence or truncate/drop the registry during ordinary maintenance. Include
the sequence, registry, function, and triggers in database backups. Like other
database constraints, these protections assume administrators do not disable
or remove them. Explicit historical references remain supported for seed/import
scripts, but are subject to the same uniqueness and non-reuse protections.

Customer and admin dashboards, My Appointments, confirmation responses, home
service cards, tracking, and booking notifications use the public reference.
Tracking still requires the booking phone number; the sequential reference is
not an authentication credential. Existing email/reminder integrations retain
the reference; this step does not add an email or SMS delivery provider.

Run integration checks from the project root:

```powershell
& C:/xampp/php/php.exe database/tests/booking_references.php
```

The test uses the local XAMPP root connection and an isolated temporary database,
then removes that database. It covers branch formats, legacy backfill, duplicate
and cross-table rejection, immutability, deletion, rollback, migration re-runs,
40 concurrent inserts, all five booking endpoints, tracking, customer/admin
dashboard responses, home-service admin actions, and notification references.
It creates no bookings in the application database and sends no email/SMS.
