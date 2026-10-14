<?php
// Included by booking_references.php inside its isolated test database.
require_once __DIR__ . '/../../backend/config/ReservationPayment.php';
$service = fn($id, $price, $requirement) => ['id' => $id, 'service_name' => 'Test ' . $id, 'price' => $price, 'payment_requirement' => $requirement];
foreach ([
    [[$service(1, '600.00', '50% Down Payment')], 600, 300, 300],
    [[$service(1, '1000.00', 'Full Payment')], 1000, 1000, 0],
    // Minimum ₱100 deposit (ReservationPayment::MIN_DEPOSIT), capped at the booking total.
    [[$service(1, '50.00', '50% Down Payment')], 50, 50, 0],
    [[$service(1, '150.00', '50% Down Payment')], 150, 100, 50],
    [[$service(1, '600.00', '50% Down Payment'), $service(2, '1000.00', 'Full Payment')], 1600, 1300, 300],
    [[$service(1, '99.99', '50% Down Payment')], 99.99, 99.99, 0],
    [[$service(1, '0.00', 'Full Payment')], 0, 0, 0],
] as [$lines, $total, $due, $balance]) {
    $quote = ReservationPayment::quote($lines);
    check(abs($quote['serviceTotal'] - $total) < 0.001 && abs($quote['amountDue'] - $due) < 0.001
        && abs($quote['remainingBalance'] - $balance) < 0.001, 'Reservation math failed: ' . json_encode($quote));
}
$fullPlan = ReservationPayment::quote([$service(1, '600.00', '50% Down Payment')], 0, false, 'full');
check($fullPlan['amountDue'] == 600 && $fullPlan['remainingBalance'] == 0 && $fullPlan['paymentPlan'] === 'full', 'Full payment plan failed');
$discounted = ReservationPayment::quote([$service(1, 600, '50% Down Payment'), $service(2, 1000, 'Full Payment')], 1600, true);
check($discounted['serviceTotal'] == 1440 && $discounted['amountDue'] == 1170 && $discounted['remainingBalance'] == 270, 'Discount allocation failed');

$pdo->exec("UPDATE services SET price=600, payment_requirement='50% Down Payment' WHERE id=1");
$pdo->exec("INSERT INTO services (id, branch_id, service_name, duration_minutes, price, payment_requirement) VALUES (2, 1, 'Full Test', 30, 1000, 'Full Payment')");
$pdo->exec('INSERT INTO staff_services (employee_id, service_id) VALUES (1, 2)');
$quoteResponse = endpoint($name, 'public/getReservationQuote.php', ['branch' => 'daraga', 'serviceIds' => [1], 'price' => 1, 'amountDue' => 1]);
check(($quoteResponse['quote']['amountDue'] ?? null) == 300, 'Quote endpoint trusted browser price');
$invalidBranch = endpoint($name, 'public/getReservationQuote.php', ['branch' => 'yashano', 'serviceIds' => [1]]);
check(!($invalidBranch['success'] ?? false), 'Quote accepted wrong-branch service');

$payload = ['branch' => 'daraga', 'serviceIds' => [1], 'date' => '2027-07-01', 'time' => '10:00',
    'customerName' => 'Reference Test', 'customerPhone' => '09999999001', 'paymentMethod' => 'Cash',
    'agreedToTerms' => '1', 'price' => 1, 'serviceTotal' => 1, 'depositAmount' => 999999,
    'amountDue' => 1, 'remainingBalance' => 0, 'paymentRequirement' => 'Full Payment'];
$half = endpoint($name, 'customer/submitBooking.php', $payload);
check(($half['success'] ?? false) && $half['appointment']['price'] == 600
    && $half['appointment']['amountDue'] == 300 && $half['appointment']['remainingBalance'] == 300
    && $half['appointment']['status'] === 'Pending' && $half['appointment']['paymentStatus'] === 'Awaiting Verification',
    'Customer booking trusted browser amounts or confirmed payment early: ' . json_encode($half));
$stored = $pdo->prepare('SELECT total_price, deposit_amount, reservation_amount_due, reservation_requirement FROM appointments WHERE reference_code=?');
$stored->execute([$half['reference']]); $row = $stored->fetch();
check($row['total_price'] == 600 && $row['deposit_amount'] == 300 && $row['reservation_amount_due'] == 300
    && $row['reservation_requirement'] === '50% Down Payment', 'Payment snapshot not stored');

$pdo->exec("UPDATE services SET price=650 WHERE id=1");
$stale = endpoint($name, 'customer/submitBooking.php', array_replace($payload, ['date' => '2027-07-02', 'quoteToken' => $quoteResponse['quote']['quoteToken']]));
check(!($stale['success'] ?? false) && ($stale['quoteChanged'] ?? false), 'Changed quote accepted');
$pdo->exec("UPDATE services SET price=600 WHERE id=1");
$full = endpoint($name, 'customer/submitBooking.php', array_replace($payload, ['serviceIds' => [2], 'date' => '2027-07-03', 'depositAmount' => -1]));
check(($full['success'] ?? false) && $full['appointment']['amountDue'] == 1000 && $full['appointment']['remainingBalance'] == 0, 'Full payment failed');
$mixed = endpoint($name, 'customer/submitBooking.php', array_replace($payload, ['serviceIds' => [1, 2], 'date' => '2027-07-04']));
check(($mixed['success'] ?? false) && $mixed['appointment']['amountDue'] == 1300 && $mixed['appointment']['remainingBalance'] == 300, 'Mixed service payment failed');

$pdo->exec("INSERT INTO booking_verifications (email, code, expires_at) VALUES ('payment-guest@example.invalid', '654321', NOW() + INTERVAL 1 HOUR)");
$guestPayment = endpoint($name, 'public/submitGuestBooking.php', [
    'fullname' => 'Payment Guest', 'contact' => '09999999005', 'email' => 'payment-guest@example.invalid', 'otp_code' => '654321',
    'branch' => 1, 'services' => [2], 'appointment_date' => '2027-07-05', 'time_slot' => '10:00',
    'payment_method' => 'Cash', 'deposit_amount' => 0.01, 'price' => 1, 'agreedToTerms' => '1',
]);
check(($guestPayment['success'] ?? false) && $guestPayment['payment']['amountDue'] == 1000 && $guestPayment['payment']['remainingBalance'] == 0, 'Guest payment trusted browser amount');

// A catalog edit must not lower the original reservation requirement.
$pdo->exec("UPDATE services SET price=10, payment_requirement='50% Down Payment' WHERE id=2");
foreach (['admin/updateBookingStatus.php', 'cashier/updateStatus.php'] as $endpointPath) {
    $underpaid = endpoint($name, $endpointPath, ['id' => $full['reference'], 'status' => 'Confirmed', 'depositAmount' => 100, 'depositMethod' => 'Cash'], 'Admin');
    check(!($underpaid['success'] ?? false), 'Underpayment confirmed');
}
$verified = endpoint($name, 'admin/updateBookingStatus.php', ['id' => $full['reference'], 'status' => 'Confirmed', 'depositAmount' => 1000, 'depositMethod' => 'Cash'], 'Admin');
check($verified['success'] ?? false, 'Full payment verification failed');
$state = $pdo->prepare('SELECT payment_status FROM appointments WHERE reference_code=?');
$state->execute([$full['reference']]);
check($state->fetchColumn() === 'Fully Paid', 'Payment status ignored actual verified total');
echo "PASS: Step 7 examples, no minimum deposit, mixed services, centavo rounding, discounts, zero totals, quote API, tampered customer/guest amounts, stored snapshots, changed quotes and underpayment rejection.\n";
