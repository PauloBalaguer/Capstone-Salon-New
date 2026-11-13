<?php
/**
 * Home service estimate (migration 065): services x clients (or the wedding
 * package price) + the travel fee for the venue's city, DP and balance.
 * Recalculated and saved by the server on submit; the admin price editor
 * validates its input and only admins can use it.
 *
 * Clones the dev database into a throwaway one, prints one line per check,
 * exits 1 on any failure.
 */
require __DIR__ . '/support/harness.php';

$pdo = clone_database('capstone_hs_estimate_test');
require_once dirname(__DIR__) . '/backend/shared/config/booking/HomeServiceRequest.php'; // migration 065 is applied by the harness
$p = test_people($pdo);
$pdo->exec('DELETE FROM rate_limit');
// Known prices for the checks (the migration's values are placeholders).
$pdo->exec("UPDATE home_service_rates SET price_per_client = 500 WHERE service_name = 'Hair Styling'");
$pdo->exec("UPDATE home_service_rates SET price_per_client = 800 WHERE service_name = 'Event Makeup'");
$pdo->exec("UPDATE home_service_travel_fees SET fee = 0 WHERE city_code = '050503000'");   // Daraga
$pdo->exec("UPDATE home_service_travel_fees SET fee = 300 WHERE city_code = '050504000'"); // Guinobatan

$e = HomeServiceRequest::estimate($pdo, 'Birthday', '', ['Hair Styling', 'Event Makeup'], 3, '050504000');
check('services x clients + travel fee', $e['servicesTotal'] == 3900 && $e['travelFee'] == 300 && $e['total'] == 4200);
check('DP is 30% rounded to ₱100, balance is the rest', $e['dp'] == 1300 && !$e['dpFixed'] && $e['balance'] == 2900);
$e = HomeServiceRequest::estimate($pdo, 'Birthday', '', ['Hair Styling'], 2, '050503000');
check('no travel fee in a free city', $e['travelFee'] == 0 && $e['total'] == 1000);
$e = HomeServiceRequest::estimate($pdo, 'Birthday', '', ['Hair Styling'], 2, '999999999');
check('a city with no fee gives no total', $e['travelFee'] === null && $e['total'] === null);
$package = $pdo->query("SELECT price, reservation_fee FROM wedding_packages WHERE package_name = 'Package D'")->fetch();
$e = HomeServiceRequest::estimate($pdo, 'Wedding', 'D', [], 1, '050504000');
check('wedding uses the package price and its fixed reservation fee', $package && $e['servicesTotal'] == $package['price'] && $e['total'] == $package['price'] + 300
    && ($package['reservation_fee'] === null || ($e['dpFixed'] && $e['dp'] == $package['reservation_fee'])));

// Submit: the server saves its own estimate (the browser sends no totals).
$r = run_endpoint(['script' => 'backend/public/submitGuestHomeService.php', 'post' => [
    'fullname' => 'Estimate Guest', 'contact' => '09170000555', 'email' => 'estimate.guest@example.test',
    'structuredAddress' => json_encode(['province' => '050500000', 'city' => '050504000', 'barangay' => '050504025', 'house' => '12', 'street' => 'Rizal Street', 'subdivision' => '', 'landmark' => '']),
    'venueDetails' => 'Hall', 'eventType' => 'Birthday', 'services' => ['Hair Styling', 'Event Makeup'],
    'date' => free_day($pdo, 20), 'time' => '10:00', 'clients' => '3', 'agreedToTerms' => 'true', 'branchKey' => 'daraga',
    'estimated_total' => '1', 'travel_fee' => '0',
]]);
$saved = $pdo->query("SELECT travel_fee, estimated_total FROM home_service_requests WHERE reference_code = " . $pdo->quote((string) ($r['reference'] ?? ''))) ->fetch();
check('request accepted', ($r['success'] ?? false) === true);
check('saved estimate is the server\'s, not the browser\'s', $saved && (float) $saved['estimated_total'] === 4200.0 && (float) $saved['travel_fee'] === 300.0);

// Admin price editor.
$save = fn(array $session, array $prices) => run_endpoint(['script' => 'backend/admin/saveHomeServicePrices.php', 'session' => $session, 'post' => ['prices' => json_encode($prices)]]);
check('a customer cannot change prices', !($save($p['sessionA'], ['rates' => ['Hair Styling' => 1]])['success'] ?? false));
check('a negative price is refused', !($save($p['admin'], ['rates' => ['Hair Styling' => -5]])['success'] ?? false));
$r = $save($p['admin'], ['rates' => ['Hair Styling' => 650, 'Not a service' => 1], 'travelFees' => ['050504000' => 450]]);
check('admin saves prices', ($r['success'] ?? false) === true
    && (float) $pdo->query("SELECT price_per_client FROM home_service_rates WHERE service_name = 'Hair Styling'")->fetchColumn() === 650.0
    && (float) $pdo->query("SELECT fee FROM home_service_travel_fees WHERE city_code = '050504000'")->fetchColumn() === 450.0
    && (int) $pdo->query("SELECT COUNT(*) FROM home_service_rates WHERE service_name = 'Not a service'")->fetchColumn() === 0);
finish();
