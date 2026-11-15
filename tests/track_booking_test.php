<?php
/**
 * Track your booking: the reference alone shows the status view, without the
 * home-service venue/notes, rescheduling or reviews. With the mobile number
 * (or home-service email) on file it shows everything; a wrong one finds nothing.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_track_booking_test');
$pdo->exec('DELETE FROM rate_limit');
$track = fn(array $query) => run_endpoint(['script' => 'backend/public/trackBooking.php', 'method' => 'GET', 'get' => $query]);

$appt = $pdo->query("SELECT a.reference_code, c.phone_number FROM appointments a JOIN customers c ON c.id = a.customer_id
    WHERE a.reference_code IS NOT NULL ORDER BY a.id LIMIT 1")->fetch();
$hs = $pdo->query("SELECT h.reference_code, h.contact_email, c.phone_number FROM home_service_requests h JOIN customers c ON c.id = h.customer_id
    WHERE h.address IS NOT NULL AND h.address <> '' ORDER BY h.id LIMIT 1")->fetch();
if (!$appt || !$hs) exit("The dev database needs an appointment and a home service request.\n");

$r = $track(['reference' => $appt['reference_code']]);
check('salon booking: reference alone shows its status', ($r['success'] ?? false) === true && ($r['status'] ?? '') !== '');
check('salon booking: no review offered without the mobile number', ($r['canReview'] ?? true) === false);
$r = $track(['reference' => $appt['reference_code'], 'phone' => '09000000000']);
check('salon booking: a wrong mobile number finds nothing', ($r['success'] ?? true) === false);
$r = $track(['reference' => $appt['reference_code'], 'phone' => $appt['phone_number']]);
check('salon booking: the right mobile number works', ($r['success'] ?? false) === true);

$r = $track(['reference' => $hs['reference_code']]);
check('home service: reference alone shows its status', ($r['success'] ?? false) === true && ($r['status'] ?? '') !== '');
check('home service: venue and notes hidden without proof', array_key_exists('venue', $r) && $r['venue'] === null && $r['requests'] === null);
check('home service: no online reschedule without proof', ($r['canReschedule'] ?? true) === false);
$r = $track(['reference' => $hs['reference_code'], 'phone' => $hs['phone_number']]);
check('home service: the mobile number on file shows the venue', ($r['success'] ?? false) === true && ($r['venue'] ?? null) !== null);

$r = $track(['reference' => 'LM-DAR-2099-999999']);
check('an unknown reference finds nothing', ($r['success'] ?? true) === false);

$pdo->exec('DELETE FROM rate_limit');
for ($i = 0; $i < 30; $i++) $track(['reference' => 'LM-DAR-2099-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
$r = $track(['reference' => $appt['reference_code']]);
check('reference-only lookups are rate limited (guessing)', ($r['success'] ?? true) === false && str_contains((string) ($r['message'] ?? ''), 'Too many'));
finish();
