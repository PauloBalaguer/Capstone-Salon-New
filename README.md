# capstone-salon-management-system
Salon Management System Capstone Project

Developed using

- PHP
- MySQL
- HTML
- Tailwind CSS
- JavaScript

Progress:
-Database
-login

## Project structure

Each panel has the same three folders: `frontend/` (HTML pages and their CSS),
`function/` (JavaScript) and `backend/` (PHP endpoints). Anything used by more
than one panel lives in `shared/`.

```
index.html          landing page
public/             landing / guest panel (guest booking, tracking, PayMongo result + webhook)
auth/               login, register, OTP, password reset
customer/           customer portal
admin/              admin portal
cashier/            cashier portal
staff/              staff (employee) portal
shared/
  frontend/         shared CSS, image/, icon/, video/
  function/         shared JS (booking validation, arrival review, address form, ...)
  backend/config/   database, session, mail, PayMongo and business-rule classes
  backend/cron/     scheduled workers (email queue, reminders)
  backend/data/     static data (Albay locations)
  backend/storage/  PHP session files (ignored by Git)
database/
  schema/           capstone.sql (full schema)
  migrations/       numbered SQL migrations
  seeders/          demo / landing data
  scripts/          CLI helpers (create_admin, create_cashier, sync_*, refresh_*)
  data/             import reference data
  docs/             database implementation notes
```

## Transactional email (Brevo API)

The existing email helpers now use PHP cURL and Brevo's HTTPS API. No Composer
installation is needed. OTP generation, validation and expiry remain in their
existing endpoints. The mailer returns true only for HTTP 201 with a nonempty
`messageId`. The database queue's `Sent` status means Brevo accepted the request;
it does not prove inbox delivery.

### Local XAMPP setup

1. Open `shared/backend/config/brevo_credentials.php`. It has already been created
   locally with empty placeholders. On another clone, copy
   `shared/backend/config/brevo_credentials.example.php` to that private filename.
2. Enter your regular Brevo API key in `api_key` and your Brevo-verified Gmail
   sender address in `sender_email`. Do this in your editor, never in chat.
   Leave `sender_name` as `Leo Mejillano Salon & Beauty` and `api_url` as
   `https://api.brevo.com/v3/smtp/email`. SMTP keys and Gmail App Passwords
   are not used.
3. Start Apache and MySQL in XAMPP. PHP 8+ with cURL, OpenSSL and mbstring is
   needed; the inspected XAMPP CLI is PHP 8.2.12 and has these extensions.
   Apache and CLI should use the same PHP configuration. If you enable an
   extension in `C:/xampp/php/php.ini`, restart Apache.
4. Open `http://localhost/Capstone-Salon/`. Keep your existing database setup.
   The migration requires no database changes. The private config is ignored
   by Git and direct Apache requests to it are denied by `.htaccess`.
5. If cURL reports error 60, configure `curl.cainfo` in PHP's `php.ini` with
   a trusted, current CA certificate bundle and restart Apache. Keep TLS
   verification enabled. Requests connect within 5 seconds and time out
   after 15 seconds overall.

Existing pending messages remain in `email_outbox`. Shutdown processing still
sends committed queued messages, and the cron worker retries Pending entries
up to the existing three-attempt limit. HTTP 401/403, 429, 5xx and network
failures return false; they never mark a row Sent. Run the existing worker
every few minutes with Windows Task Scheduler:

```powershell
& C:/xampp/php/php.exe C:/xampp/htdocs/Capstone-Salon/shared/backend/cron/sendQueuedEmails.php
```

Use `C:/xampp/php/php.exe` as the task's program, the script's absolute path as
its argument, and the project folder as its starting directory. Keep the
existing appointment reminder task:

```powershell
& C:/xampp/php/php.exe C:/xampp/htdocs/Capstone-Salon/shared/backend/cron/sendAppointmentReminders.php
```

These workers send real pending mail once credentials are configured. Inspect
the pending recipients before enabling them for a real delivery test. Failed
entries remain Failed after three attempts; the migration does not reset
attempts, resend Sent mail, or change notification preferences. Rate-limited
mail uses the existing scheduled retry cadence. The existing queue mutex and
deduplication remain; as before, an ambiguous network timeout after provider
acceptance can cause a duplicate on retry.

### Test one real registration OTP yourself

1. Configure the two private values above and confirm that Brevo permits
   transactional sending for your account.
2. Register through `auth/frontend/register.html` using a Gmail inbox you control,
   a new test account and a unique test contact number. This creates a real
   account in your local application.
3. Check that inbox and Spam for the verification email. Enter its six-digit
   code within the existing 10-minute expiry, then verify that login works.
   If delivery failed, correct the configuration and use the existing resend
   action; registration already created the inactive account.
4. In Brevo, open **Transactional > Email > Logs**, filter by your test
   recipient and time, and inspect the event details. `Delivered` means the
   recipient server accepted the mail. Inspect blocked/bounce/error events
   if it does not arrive. API acceptance and delivery are separate events.

There is no longer a log-file or localhost response fallback exposing OTPs.
Registration reports when the account was created but email could not be
sent. Resend and password-reset endpoints retain their generic responses
to avoid revealing whether an account exists; those responses are not proof
of delivery. Existing password-change and staff-setup callers retain their
current flow; use Brevo logs/PHP error logs to investigate delivery failures.
Mailer diagnostics include HTTP status or numeric cURL error only, never
keys, OTPs, message bodies or raw provider responses.

### Gmail sender limitations

Your verified Gmail sender can be entered in the config, but you cannot
authenticate `gmail.com` as a domain you own. Brevo may replace its visible
sender domain with a Brevo-managed sending domain to meet provider rules.
Verification alone does not guarantee inbox placement. For production
branding and authentication, use your own domain with the DNS records Brevo
provides; that is optional future setup, not part of this migration.

References: [Brevo send API](https://developers.brevo.com/reference/send-transac-email),
[transactional logs](https://help.brevo.com/hc/en-us/articles/360021533839-Manage-your-transactional-logs-and-email-previews),
[free-domain authentication limitations](https://help.brevo.com/hc/en-us/articles/16045394674066-Troubleshooting-Domain-authentication).

### Migration file inventory

- Created: `shared/backend/config/brevo_credentials.php` (private, ignored),
  `shared/backend/config/brevo_credentials.example.php`, `tests/brevo_mail.php`.
- Modified: `shared/backend/config/mail.php`, `shared/backend/config/EmailOutbox.php`,
  `shared/backend/cron/sendQueuedEmails.php`, `shared/backend/cron/sendAppointmentReminders.php`,
  `auth/backend/register.php`, `auth/backend/resendOtp.php`, `.gitignore`,
  `.htaccess`, `README.md`, `tests/booking_otp_delivery.php`,
  `tests/customer_notifications.php`, `tests/event_availability.php`,
  `tests/run_isolated.php`. The customer notification and event tests only
  needed SMTP wording updated; the runner includes the new Brevo suite.
- Removed after dependency checks and mocked tests:
  `shared/backend/config/mail_credentials.example.php` and
  `backend/lib/PHPMailer/{Exception.php,PHPMailer.php,SMTP.php,LICENSE}`.
  These tracked files are recoverable from Git history. The old private
  `mail_credentials.php` did not exist locally.
- Inspected and preserved: `CustomerNotifier.php`, `StaffNotifier.php`,
  `AccountSetup.php`, OTP validation/expiry logic, booking/payment callers
  and the database schema. StaffNotifier handles in-app notifications only.

The private mail credential paths were not tracked and did not appear in the
available local Git history. No real credentials were printed or committed.
The old private path remains ignored to protect any legacy local copies.
This check cannot establish what may exist in unavailable remote history.
If a secret was ever committed elsewhere, revoke/rotate it; ignoring or
deleting a file does not remove it from past commits.

