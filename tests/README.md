# Tests

Self-checking PHP scripts. Each one copies the dev database (`new capstone_salon`)
into a throwaway database, runs the real backend code against the copy, prints
one `PASS` / `FAIL` line per check, drops the copy, and exits with code 1 if
anything failed. Your dev data is only read, never changed.

No API keys are needed. The tests point the app at an empty settings folder, so
nothing is emailed, texted or charged. PayMongo is a local mock server.

## Requirements (XAMPP on Windows)

- **MySQL running** in the XAMPP Control Panel, on port **3307** with user `root`
  and no password (the same settings as `backend/shared/config/setup/database.php`).
  If your MySQL uses 3306, change `-P3307` in `tests/support/harness.php` and
  `tests/paymongo_methods_test.php`.
- The dev database `new capstone_salon` imported, with at least two customer
  accounts, the admin and the Daraga cashier (the normal dev data has these).
- `composer install` done once (the app loads its settings through it).
- Free local ports **8765** and **8766** (the mock PayMongo API).

Apache doesn't need to be running.

## Running

From the project folder (`C:\xampp\htdocs\Capstone-Salon`) in PowerShell:

```powershell
C:\xampp\php\php.exe tests\booking_concurrency_test.php
C:\xampp\php\php.exe tests\event_team_test.php
C:\xampp\php\php.exe tests\booking_exceptions_test.php
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
| `paymongo_methods_test.php` | The separate GCash / Maya / Card methods in detail, including more failure cases: a Maya payment cancelled and retried, a card declined, an abandoned payment, and a late payment after the hold expired (Refund Due). |
| `env_test.php` | The private `.env` loading. |

## How it works

- `support/harness.php` copies the database (and applies migrations 053–054 to
  the copy, in case the dev database doesn't have them yet), then provides
  `check()` and `run_endpoint()`.
- `support/endpoint.php` runs one real endpoint (for example
  `backend/customer/submitBooking.php`) in a child PHP process against the copy,
  with a pretend logged-in session. The endpoint code is not modified or copied.
- The PayMongo mock is the server part of `paymongo_methods_test.php`, started
  with `php -S`.

## When a test fails

- **"Could not clone the database."**: MySQL isn't running, or isn't on port 3307.
- **"The dev database needs ..."**: the dev data is missing the accounts or
  stylists the test builds on. Re-import the dev data or add them.
- A `FAIL` line names the case that broke. Run that one test again to confirm,
  then look at the endpoint or class it mentions.
- If a test is stopped half way, its copy (`capstone_*_test`) may be left
  behind. The next run drops and recreates it.
