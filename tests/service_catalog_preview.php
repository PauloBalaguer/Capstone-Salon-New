<?php
require_once __DIR__ . '/../backend/config/ReservationPayment.php';
foreach ([[49, '50% Down Payment', 49], [150, '50% Down Payment', 100], [499, '50% Down Payment', 249.5], [2499, 'Full Payment', 2499], [499, 'No Online Reservation', 0]] as [$price, $rule, $expected]) {
    $quote = ReservationPayment::quote([['id'=>1,'service_name'=>'Test','price'=>$price,'payment_requirement'=>$rule]]);
    if (abs($quote['amountDue'] - $expected) > 0.001) throw new RuntimeException('Incorrect catalog preview');
    echo "PASS price $price, $rule: {$quote['amountDue']}\n";
}
