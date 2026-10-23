# Service duration implementation

Service duration and estimated end time now use the existing branch-specific
`services.duration_minutes` integer. Customer and guest selection, review, and
confirmation show readable duration and estimated end time. Admins can save any
positive whole number of minutes, including the reference's 10- and 15-minute
services. No buffers were added.

## Database and architecture

The application uses PHP endpoints with PDO, Alpine.js, and plain JavaScript;
there are no ORM models. The existing `services` table already has
`duration_minutes INT NOT NULL`, `duration_label`, and `branch_id`. No schema change
or migration was necessary. Service records remain separate by branch and ID.

The initial import updated only `services.duration_minutes` and its derived
`duration_label` (plus the database's automatic update timestamp). It created,
deleted, and renamed no service records. Before/after checks confirmed that all
385 service records remain and that prices, branch IDs, categories, and other
service fields are unchanged. Customer, employee, user, appointment,
appointment-service, home-service, branch, payment, and payment-item data was
compared before/after and remained unchanged. A service backup was written to
`C:\Users\micha\AppData\Local\Temp\salBA4C.tmp` before the import.

## Modified files

| File | Change |
|---|---|
| `backend/admin/saveService.php` | Strict positive-integer duration validation; retain stored duration when omitted on edit; derive the compatibility label from minutes. |
| `backend/public/getBranchServices.php` | Return readable duration derived from stored minutes, so admin edits appear in the public service selector. |
| `backend/customer/getDashboardData.php` | Return consistent catalog duration labels and appointment end times. |
| `backend/customer/submitBooking.php` | Return authoritative summed duration and estimated end time with the appointment confirmation. |
| `backend/public/submitGuestBooking.php` | Return authoritative duration and end time with the guest confirmation. |
| `assets/js/admin/promotions.js` | Validate positive whole minutes before Add/Edit Service submission. |
| `pages/admin/promotions.html` | Estimated Duration numeric input, minimum 1, and readable catalog duration. |
| `pages/admin/appointments.html` | Readable Estimated Duration and Estimated End Time in the existing booking cards. |
| `assets/js/customer/dashboard-helpers.js` | Format service duration from integer minutes instead of stale/range labels. |
| `assets/js/customer/dashboard-booking.js` | Selected-duration and end-time helpers; use the server's duration after booking. |
| `pages/customer/services-promos.html` | Duration on selected services; live estimated end, review, and confirmation details. |
| `assets/js/main.js` | Guest service durations, live selected-service end time, review, and confirmation details. |
| `index.html` | Load the shared duration helper for the guest flow. |

The existing `backend/config/Scheduling.php::totalDurationMinutes()` and
`backend/admin/getDashboardData.php` calculations were reused without modifying
their scheduling/conflict logic. The existing appointment and appointment-service
tables continue to store bookings and their selected services.

## New files

| File | Purpose |
|---|---|
| `backend/config/ServiceDuration.php` | Integer validation, readable duration, and start-plus-minutes calculation. |
| `assets/js/service-duration.js` | Shared customer/admin duration formatting and end-time previews. |
| `database/data/service-duration-reference.json` | 315 duration rows extracted from the supplied DOCX, retaining branch and section context. |
| `database/sync_service_durations.php` | Preview/apply the initial duration import; safe branch-aware matching and service backup. |
| `tests/service_duration.php` | Duration formatting, validation, and end-time examples. |
| `tests/service_duration_admin.php` | Actual admin endpoint and customer catalog checks using temporary tables only. |
| `database/SERVICE_DURATION_MATCHES.md` | Complete lists of matched records, unchanged records, and unmatched reference rows. |
| `database/SERVICE_DURATION_IMPLEMENTATION.md` | This implementation and testing report. |

## Calculations and display

`Scheduling::totalDurationMinutes()` sums the selected services' stored minutes.
Multiple services continue to run back-to-back, following the existing workflow.
The estimated end is appointment start plus that sum; neither appointment nor
travel buffers are included. The API returns the result using the existing
camel-case convention: `durationMinutes` and `endTime`. End time is computed,
not stored in a new database column.

`ServiceDuration::format()` and `ServiceDuration.format()` convert integer minutes
into whole hours and remaining minutes: 60 becomes `1 hour`, 75 becomes
`1 hour 15 minutes`, 90 becomes `1 hour 30 minutes`, and 240 becomes `4 hours`.
Browser end-time previews use the Philippine timezone explicitly. Compatibility
`duration_label` values are derived text; integer minutes remain authoritative.

## Reference matching results

The source is `Leo_Mejillano_Estimated_Service_Durations_by_Branch.docx`.
Only its service-duration table values were imported. Recommendations about
appointment buffers, travel buffers, and scheduling redesign were excluded in
accordance with the user's request.

| Branch | Matched existing records |
|---|---:|
| Daraga, including its aesthetics catalog | 134 |
| Yashano | 83 |
| Cabangan | 104 |
| Total | 321 |

Of the 321 matched records, 320 already had the documented numeric value.
Daraga service ID 2, `Organic Rebonding (Any Length)`, changed from 210 to
240 minutes. Compatibility labels were made consistent for all matched records.

64 existing catalog records were left unchanged because a clear source match
was unavailable. Examples include `Signature Haircut`, `Organic Rebonding Spec`,
and `Haircut w/ Blowdry (Premium)`. 32 reference rows had no clear match, including
18 home-service rows intentionally excluded and 14 other unmatched rows.
Examples include `Stemcell facial`, differently worded hair/makeup packages,
and group-wide permanent-makeup packages. No durations were guessed.

See [the complete matching report](SERVICE_DURATION_MATCHES.md) for every
matched/unmatched service ID, existing name, category, previous minutes, and
reference value. Matching uses branch and explicit Group-Wide categories, with
only case/punctuation and clear spelling normalization; ambiguous durations
require matching section context. It never merges service records.

The importer defaults to preview. `--apply` is an explicit initial-value operation;
do not rerun it routinely after admins customize durations. Normal admin edits
are saved directly to the chosen service record and need no source-code changes.

## Automated checks completed

- All changed/new PHP files passed syntax checks; changed JavaScript parsed successfully.
- Four requested start/end examples passed in PHP and JavaScript: 09:00 + 30 → 09:30; 13:00 + 60 → 14:00; 10:00 + 120 → 12:00; 09:00 + 240 → 13:00.
- Eight requested readable formats, multiple selected services, and midnight rollover passed.
- Actual admin endpoint tests passed for duration-only editing, price-only editing, omitted duration preservation, and creating a 10-minute service.
- Zero, negative, text, fractional, overflowing, and missing new-service durations were rejected.
- Editing one branch's Haircut left the other branch's record unchanged; the public catalog immediately exposed `45 minutes` after the edit.
- Existing 47 booking-validation checks passed. Temporary endpoint test rows and notifications were discarded with their connections; no real test bookings/payments were submitted.
- No browser is connected in this environment, so rendered visual verification remains a manual check.

Run:

```powershell
C:\xampp\php\php.exe tests/service_duration.php
C:\xampp\php\php.exe tests/service_duration_admin.php
C:\xampp\php\php.exe tests/booking_validation.php
```

## Manual testing steps

1. In Admin → Services & Promos, edit a branch's Haircut to 30 minutes. Open the customer service selector and verify `Estimated Duration: 30 minutes`.
2. Choose Haircut at 9:00 AM and verify 9:30 AM as estimated end. Verify the same duration/end in review and confirmation.
3. Choose Basic Facial (60) at 1:00 PM → 2:00 PM; Hair Color (120) at 10:00 AM → 12:00 PM; and a clearly matched Organic Rebond (240) at 9:00 AM → 1:00 PM.
4. Change Haircut from 30 to 45 minutes in one branch. Reload the customer selector and verify `45 minutes`. Check the other branch's Haircut remains unchanged.
5. Try 0, -30, fractional minutes, and invalid text in Add/Edit Service. Verify rejection. Verify 10 and 15 minutes are accepted.
6. Edit only service price and verify duration is retained. Edit only duration and verify price, branch, category, payment requirement, and other fields are retained.
7. Repeat service selection/review/confirmation as a guest. Check the live estimated end changes when the selected start or selected services change.
8. Open Admin → Appointments and verify Estimated Duration and Estimated End Time for the resulting appointment. Complete booking submission only with an authorized test customer; this implementation did not submit real bookings during testing.

## Existing issues and limits

The admin's old 20-minute minimum conflicted with documented 10- and 15-minute
services, integer casting accepted malformed input, and stored text labels could
disagree with minutes. These validation/display paths are corrected. Booking
confirmation responses previously omitted duration/end details; they now include them.

Existing appointments derive duration from the current linked service records.
This architecture is preserved: an admin duration change can alter a previously
booked appointment's displayed estimate, without changing its stored start time,
price, status, or payment data. A historical duration snapshot would require a
separate additive schema/workflow change and was not introduced in this minimal task.
Unmatched legacy records retain their existing durations for admin review.
