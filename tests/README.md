# Tests

Self-checking PHP scripts. Each one copies the dev database (`DB_NAME` from your
private `.env`) into a throwaway database, runs the real backend code against the
copy, prints one `PASS` / `FAIL` line per check, drops the copy, and exits with
code 1 if anything failed. Your dev data is only read, never changed.
`loyalty_card_test.php` is the exception: it runs on the dev database itself,
inside a transaction it rolls back.

No API keys are needed. The tests point the app at an empty settings folder, so
nothing is emailed, texted or charged. PayMongo is a local mock server.

## Requirements (XAMPP on Windows)

- **MySQL running** in the XAMPP Control Panel with user `root` and no password.
  The tests use the `DB_NAME` and `DB_PORT` from your private `.env`, like the app
  (defaults: `new_capstone_salon`, port 3306); `tests/support/dev_db.php` reads
  them without loading any API key.
- That dev database imported, with at least two customer accounts, the admin
  and the Daraga cashier (the normal dev data has these).
- `composer install` done once (the app loads its settings through it).
- Free local ports **8765** and **8766** (the mock PayMongo API) and **8767** (the
  webhook endpoint in `paymongo_methods_test.php`).

Apache doesn't need to be running.

## Running

From the project folder (`C:\xampp\htdocs\Capstone-Salon`) in PowerShell:

```powershell
C:\xampp\php\php.exe tests\booking_concurrency_test.php
C:\xampp\php\php.exe tests\event_team_test.php
C:\xampp\php\php.exe tests\booking_exceptions_test.php
C:\xampp\php\php.exe tests\guest_otp_test.php
C:\xampp\php\php.exe tests\arrival_request_test.php
C:\xampp\php\php.exe tests\staff_portal_test.php
C:\xampp\php\php.exe tests\admin_staff_password_test.php
C:\xampp\php\php.exe tests\guest_home_service_account_test.php
C:\xampp\php\php.exe tests\track_booking_test.php
C:\xampp\php\php.exe tests\home_service_estimate_test.php
C:\xampp\php\php.exe tests\salon_cancel_test.php
C:\xampp\php\php.exe tests\remember_me_test.php
C:\xampp\php\php.exe tests\guest_signup_test.php
C:\xampp\php\php.exe tests\trusted_device_test.php
C:\xampp\php\php.exe tests\paymongo_methods_test.php
C:\xampp\php\php.exe tests\env_test.php
```

All of them in one go; this stops at the first failing test:

```powershell
foreach ($t in Get-ChildItem tests\*_test.php) { C:\xampp\php\php.exe $t.FullName; if ($LASTEXITCODE) { "FAILED: $($t.Name)"; break } }
```

Each test takes about 5–15 seconds, mostly to copy the database. Lines like
`SETVAL(...)` at the start come from copying the database and can be ignored.

## What each test covers

| Test | Cases (panel checklist items 1 and 29) |
|---|---|
| `booking_concurrency_test.php` | **Two customers book the same stylist and slot at the same time** from two parallel PHP processes: exactly one booking succeeds, the other gets "slot unavailable". The test proves both requests were inside their booking transaction at the same moment. Also: overlapping start times raced in parallel, and two different stylists at the same time (both allowed). **Overlapping windows** (starting or ending halfway through another booking), the **30-minute cleaning buffer**, and **several services booked back to back** (the stylist is busy for their total). |
| `event_team_test.php` | **Two events that need the same team on the same day**, through the admin staff-assignment endpoint. The team is blocked for travel + setup + service + buffer (migration 054), not the old flat 2 hours. Covers: whole-team checks, a back-to-back assignment, salon bookings blocked during an event, a customer reschedule refused when the team is busy, and what the public availability check shows. |
| `booking_exceptions_test.php` | **A failed PayMongo (GCash) payment, then a retry** through the real result-page and "Try again" endpoints. The test then follows the booking through staff verification and cashier checkout, and checks that **the revenue report matches the payment log** and the cashier payment exactly. **No-show**: the deposit is kept (Forfeited), logged once, and nothing happens automatically. **Late arrival within and beyond the grace period** (`SALON_LATE_GRACE_MINUTES`): the arrival review says which, and changes nothing. **Cancellation with a deposit**: the non-refundable policy is applied once, only to money actually held. |
| `guest_otp_test.php` | Guest booking verification. With no email, the booking needs the code **texted to the mobile number**: refused with no code, with a wrong code or with a reused code, and a clear error when the text can't be sent. With an email, the emailed code is used. |
| `paymongo_methods_test.php` | The separate GCash / Maya / Card methods in detail, including more failure cases. **GCash (a PayMongo Source)**: charged exactly once when it becomes chargeable, however often the result page and webhook sync; Fail / Expire on the GCash page; a payment declined after authorization; never charged once the booking was released; older GCash bookings with a Payment Intent still sync. **The webhook** through the real endpoint: `source.chargeable` creates the payment once, a bad signature is refused, and a replaced attempt is not charged. Also a Maya payment cancelled and retried, a card declined, an abandoned payment, and a late Maya payment after the hold expired (Refund Due). |
| `arrival_request_test.php` | **Late or absent customers: the stylist asks, the cashier decides.** A no-show request is only accepted past the grace period and changes nothing by itself, and it reaches only that branch's cashiers. The cashier's No-Show is refused inside the grace period, for future bookings, for visits already checked out and for other branches, and keeps the deposit once. |
| `staff_portal_test.php` | **Staff Portal.** Supplies logged at the same moment keep the stock right, and only count against a recent booking of that stylist. A photo too large for MySQL gets a clear refusal. Parallel Start Breaks give one break and no false error. |
| `admin_staff_password_test.php` | **Staff passwords.** Staff can't change their own password; only the admin sets a new one from the Staff page. Weak or mismatched passwords are refused and the password never appears in the response. |
| `guest_home_service_account_test.php` | **Guest home service with an account's number.** Refused with a different email; accepted with that account's own email, and the request goes into that account. Numbers without an account work as before. |
| `track_booking_test.php` | **Track your booking.** The reference alone shows the status; the home-service venue, notes, rescheduling and reviews need the mobile number or email on file, and a wrong one finds nothing. Lookups by reference alone are rate limited. |
| `home_service_estimate_test.php` | **Home service estimate.** Services × clients (or the wedding package) + the city's travel fee, DP and balance; the server saves its own estimate on submit, not the browser's; only admins can edit the price list, and negative prices are refused. |
| `salon_cancel_test.php` | **Salon cancellation.** The admin must choose refund or reschedule and give a reason; refund makes the deposit Refund Due with amount, reason, admin and date on the payment record, then Refunded; reschedule keeps the booking and deposit; the customer is emailed and texted. Customer cancellations and no-shows still forfeit the deposit. |
| `remember_me_test.php` | **"Remember me" on the login page.** Whether the browser is still signed in, which dashboard that leads to, and that sessions last as long as the 30-day cookie. Reads no database rows. |
| `guest_signup_test.php` | **Signing up with a number that has guest bookings.** A number with its own account stays blocked. A guest number can sign up, but its bookings and points only join the account after the email code and then a texted code. Covers wrong, expired and repeated codes, a stolen or missing token, coming back through login, the number being taken meanwhile, and finishing with a different number. |
| `trusted_device_test.php` | **"Remember me" recognising a customer's browser.** Only the right cookie with the right email signs in without a password. It ends on expiry, a password change, deactivation or "Not you?". Customers only, nothing usable is stored, and a problem with this feature never blocks a normal login. |
| `env_test.php` | The private `.env` loading. |

## How it works

- `support/harness.php` copies the database (and applies migrations 053, 054, 055 (loyalty card), 057, 058, 059 and 060 to
  the copy, in case the dev database doesn't have them yet), then provides
  `check()` and `run_endpoint()`.
- `support/endpoint.php` runs one real endpoint (for example
  `backend/customer/submitBooking.php`) in a child PHP process against the copy,
  with a pretend logged-in session. The endpoint code is not modified or copied.
- The PayMongo mock is the server part of `paymongo_methods_test.php`, started
  with `php -S`.

## When a test fails

- **"Could not clone the database."** or **"Database connection failed."**: MySQL
  isn't running, or isn't on the `DB_PORT` in your `.env`.
- **"The dev database needs ..."**: the dev data is missing the accounts or
  stylists the test builds on. Re-import the dev data or add them.
- A `FAIL` line names the case that broke. Run that one test again to confirm,
  then look at the endpoint or class it mentions.
- If a test is stopped half way, its copy (`capstone_*_test`) may be left
  behind. The next run drops and recreates it.
