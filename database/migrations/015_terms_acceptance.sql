-- Migration 015: terms & conditions acceptance
-- Panel feedback: "Each booking and reservation should have terms and
-- conditions." Adds an audit-trail timestamp set only when the customer
-- checked the agreement box at submission time (see backend/customer/
-- submitBooking.php, backend/public/submitGuestBooking.php, backend/
-- customer/submitHomeServiceRequest.php, backend/public/submitGuestHomeService.php,
-- all of which now reject the request outright if agreedToTerms isn't set).
-- Staff-created bookings (admin/createBooking.php, cashier/createWalkIn.php)
-- are made on the client's behalf, not self-service, so they intentionally
-- leave this NULL -- there's no digital checkbox for staff to click through.

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `terms_accepted_at` TIMESTAMP NULL AFTER `notes`;

ALTER TABLE `home_service_requests`
  ADD COLUMN IF NOT EXISTS `terms_accepted_at` TIMESTAMP NULL AFTER `requests`;
