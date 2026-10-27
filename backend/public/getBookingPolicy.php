<?php
/**
 * Public: booking rules that come from configuration (no login required).
 * lateArrivalRule is null while SALON_LATE_GRACE_MINUTES is unset, so pages hide it.
 */
require_once __DIR__.'/../../backend/shared/config/setup/database.php';
require_once __DIR__.'/../../backend/shared/config/booking/Scheduling.php';
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'lateGraceMinutes' => Scheduling::arrivalGraceMinutes(),
    'lateArrivalRule' => Scheduling::lateArrivalRule(),
]);
