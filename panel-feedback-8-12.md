# Panel feedback: items 8–12

All changes are local. Nothing was committed, pushed or deployed. Items 6–7 remain in the working tree. No new database migration is needed for items 8–12. Import migration **067_cashier_cashless_reference.sql** from item 7 into the intended database before using cashless checkout; it has only been run on test copies.

## 8. Walk-ins and staff clock-in

The cashier now distinguishes nobody clocked in from stylists being on break or away. The backend gives the same actionable instruction when a walk-in or immediate staff assignment cannot proceed. Logging in does **not** start attendance: the stylist must tap **Shift In**.

Reused `StaffShift`, attendance auto-close, the existing Shift In endpoint and `Scheduling::staffCanTakeBooking(..., true)`. The existing Staff Portal already has a mobile viewport, responsive layout and a 54px Shift In control.

Files:
- `backend/shared/config/helpers/StaffShift.php`
- `backend/cashier/createWalkIn.php`
- `backend/cashier/getQualifiedStaffForBooking.php`
- `function/cashier/dashboard.js`
- `function/cashier/staff.js`
- `frontend/cashier/dashboard.html`
- `frontend/cashier/staff.html`
- `tests/walkin_clockin_test.php`

Browser checks:
1. With all stylists clocked out, open New Walk-In. It must explain how to use Shift In; submitting directly must fail.
2. On a phone, sign into the Staff Portal and tap Shift In. Refresh the cashier staff list. A qualified, scheduled, free stylist can now be chosen.
3. A second Shift In must fail without creating another attendance row. A stylist on break must not be available for a walk-in.

Automated: `walkin_clockin_test.php`, `staff_portal_test.php`.

## 9. Cancelled online payment and retry

The result page clearly says **Payment not completed**, offers **Retry with [method]**, and offers a status check if the bank/wallet shows a debit. It no longer claims that no money was taken without evidence. Stale retry links are cleared when rendering a new state.

Reused the PayMongo retry endpoint, token verification, hold countdown, `HOLD_MINUTES = 30`, and `releaseExpiredHolds()`. The result endpoint now invokes that existing expiry handler when an authenticated payment-link check reaches the deadline, so the countdown does not depend on another page or the cron running first. The handler checks for payment before cancellation.

Files:
- `backend/public/paymongoStatus.php`
- `function/public/payment-result.js`
- `frontend/public/payment-result.html`
- `tests/payment_retry_hold_test.php`

Browser checks:
1. Cancel a sandbox GCash/Maya/card payment. The result should show the failure message, remaining hold and retry methods.
2. Retry before the deadline: the existing booking gets a new payment attempt, not a second booking.
3. After the original hold expires, refresh: the unpaid booking is released and retry is unavailable. A wrong token must fail and reveal no booking.

Automated: `payment_retry_hold_test.php`, `paymongo_methods_test.php`, `booking_exceptions_test.php`.

## 10. Checkout, report labels and thermal receipt

The summary order is service total, deposit paid, balance due, tip, cash received, change. Optional product and loyalty lines are preserved. Balance due includes retail products and excludes the tip; the final amount includes the tip. Zero deposits and zero cash tips remain visible.

Reused the existing checkout calculations, report collection bars and receipt. Admin pages use bar comparisons rather than Chart.js charts; those bars now have descriptive titles, category/axis labels, currency and range. The print stylesheet provides 74mm content plus 3mm margins for an 80mm roll. Select **80mm paper** in the printer dialog and disable browser headers/footers; the driver determines the roll/page height.

Files:
- `frontend/cashier/dashboard.html`
- `function/cashier/checkout-modal.js`
- `frontend/cashier/receipt.html`
- `frontend/admin/dashboard.html`
- `frontend/admin/reports.html`
- `tests/checkout_receipt_test.php`

Browser checks:
1. Checkout a booking with a deposit, retail product and tip. Confirm the summary and change against the receipt.
2. Cash received below the final amount must fail. Switching to cashless hides cash-only tip/change controls and requires a transaction reference.
3. Preview the receipt on 80mm paper; controls must disappear and long reference text must wrap.
4. Check admin dashboard/reports for the collection title, PHP scale and branch categories, including an empty date range.

Automated: `checkout_receipt_test.php`, `cashier_cashless_test.php`, `booking_exceptions_test.php`; `node tests/panel_presentation_test.js` checks displayed totals, change and insufficient cash.

## 11. Security and defense explanation

**How passwords are changed:** Customers and admins verify their current password and submit a new password that passes `PasswordPolicy`. A confirmation code is sent to the account's email; the password changes only after that code is verified. Codes expire after ten minutes. Staff and cashier password changes are performed by the admin from Staff/Settings. New managed accounts use an admin-issued, expiring setup link.

**How passwords are stored:** Runtime account creation and password-update paths use `password_hash(..., PASSWORD_DEFAULT)`. Password authentication uses `password_verify()`. Passwords are never compared as plaintext. The MD5/SHA1 uses elsewhere are integration signatures/version hashes, not password storage. Trusted-device tokens are hashed and tied to the password hash, so a password change invalidates remembered-device authentication.

**How roles are isolated:** Admin, cashier, customer and staff endpoints check the server session role before their protected actions. Staff endpoints delegate this check to `StaffShift::requireEmployee()`. The two account/password endpoints are Admin-only. Cashier endpoints intentionally also permit Admin, while cashier sessions remain branch-restricted. Public/auth endpoints instead use the appropriate password, OTP, token or webhook verification; they are not all login-only endpoints. Hiding buttons in the browser is not the authorization boundary.

Gaps fixed:
- Public Forgot Password/reset-code/reset endpoints now exclude Staff and Cashier accounts, including previously issued codes, enforcing the admin-only policy.
- Confirmation codes, reset codes and account setup tokens are consumed inside locked transactions. Two simultaneous uses cannot both change a password.
- Customer/admin password-change endpoints report email delivery failures honestly and keep the password unchanged.

Files:
- `backend/auth/forgotPassword.php`
- `backend/auth/verifyResetCode.php`
- `backend/auth/resetPassword.php`
- `backend/auth/completeAccountSetup.php`
- `backend/customer/changePassword.php`
- `backend/customer/confirmPasswordChange.php`
- `backend/account/changePassword.php`
- `backend/account/confirmPasswordChange.php`
- `tests/security_roles_passwords_test.php`

Browser checks:
1. Customer/admin: wrong current password, weak/mismatched new password, wrong/expired OTP and reused OTP must fail. A valid flow must allow login with the new password.
2. Staff/cashier: public Forgot Password must not issue a usable reset code. Admin reset remains available; the staff/cashier session cannot call the admin endpoint.
3. Sign in as a customer and request an admin/cashier/staff endpoint directly: it must refuse access.
4. With email delivery unavailable, request a password change: the page must report failure, not claim that a code was sent.

Automated: `security_roles_passwords_test.php` checks all 76 protected portal endpoints with a foreign role and exercises OTP/token concurrency. Related tests: `admin_staff_password_test.php`, `admin_cashier_password_test.php`, `trusted_device_test.php`. This is a targeted code audit and regression suite, not a penetration-test certification.

## 12. Public content

Added a visible late-arrival policy section and reused `LateArrivalRule`/`SALON_LATE_GRACE_MINUTES` for its text and booking Terms. An unset or unavailable policy does not invent a grace period. The before/after image IDs, alternate text and JavaScript mappings now agree with their visible sides.

Statistics have readable fallbacks from the initial HTML onward. Hours use the configured branch schedules, Sunday/holiday handling and Manila time through `Scheduling::operatingHours()`. Failed/invalid/timed-out requests show **Call to confirm hours** instead of guessing an open status; periodic refresh can recover. `getLiveConfig.php` remains the optional Pusher configuration endpoint and already returns a safe disabled response when unconfigured.

Files:
- `index.html`
- `function/public/main.js`
- `backend/public/getSiteStats.php`
- `tests/public_content_test.php`

Browser checks:
1. Check Hair/Brows/Skin comparisons at both slider ends. Labels and alternate text must match the photos.
2. Set a grace period, reload and check the policy and Terms. An unset period must not display a hardcoded 15 minutes.
3. Block `getSiteStats.php` in browser developer tools and reload: counts must show readable fallback text and hours must say to call. Restore the request and refresh to recover.
4. Simulate a closed holiday and a device timezone outside the Philippines: the open/closed label must still follow the branch's Manila schedule.

Automated: `public_content_test.php`, `node tests/panel_presentation_test.js` for fallback/recovery/Manila time, and `booking_exceptions_test.php` for the shared late-arrival rules.

## Shared file and owner commands

`tests/README.md` lists the new tests. Script cache versions were updated wherever JavaScript changed. Earlier item 6–7 changes were preserved.

After reviewing the diff, these commands stage all panel changes from items 6–12 currently in this working tree (including migration 067). They do not push `main` or deploy automatically:

```powershell
git diff --check
git diff --stat
git add backend/account backend/auth backend/cashier backend/customer backend/public
git add backend/shared/config/booking/GuestBookingLimit.php backend/shared/config/helpers/StaffShift.php backend/shared/config/payments/RevenueReport.php
git add frontend/admin/dashboard.html frontend/admin/reports.html frontend/cashier frontend/public/payment-result.html
git add function/cashier function/public index.html
git add database/migrations/067_cashier_cashless_reference.sql database/schema/capstone.sql
git add tests/README.md tests/guest_booking_limit_test.php tests/cashier_cashless_test.php tests/walkin_clockin_test.php tests/payment_retry_hold_test.php tests/checkout_receipt_test.php tests/security_roles_passwords_test.php tests/public_content_test.php
git add tests/panel_presentation_test.js
git add panel-feedback-8-12.md
git diff --cached --stat
git commit -m "Address salon panel feedback items 6 through 12"
git push newrepo panel-items-2-5
```

Real payment-provider transactions, real SMS/email delivery and a physical thermal printer were not exercised. Database tests use throwaway copies and mock payment services. Headless browser automation did not complete reliably; phone interaction and print-preview checks above remain manual. The staff clock-in endpoint and responsive markup were checked, but these do not replace a real phone check.
