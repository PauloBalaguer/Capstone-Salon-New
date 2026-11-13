<?php
/**
 * Public: booking rules that come from configuration (no login required).
 * lateArrivalRule is null while SALON_LATE_GRACE_MINUTES is unset, so pages hide it.
 * holdMinutes is PayMongo::HOLD_MINUTES.
 */
require_once __DIR__.'/../../backend/shared/config/setup/database.php';
require_once __DIR__.'/../../backend/shared/config/booking/Scheduling.php';
require_once __DIR__.'/../../backend/shared/config/payments/PayMongo.php';
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'lateGraceMinutes' => Scheduling::arrivalGraceMinutes(),
    // How long an unpaid online booking holds its slot (the booking Terms quote it).
    'holdMinutes' => PayMongo::HOLD_MINUTES,
    'lateArrivalRule' => Scheduling::lateArrivalRule(),
]);
