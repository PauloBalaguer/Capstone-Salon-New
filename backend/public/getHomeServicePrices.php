<?php

/**
 * Public: home service price list for the request forms (guest and customer).
 * Per-client prices of the home service services, the travel fee per Albay
 * city code (migration 065) and the DP estimate percent. The forms only show
 * an estimate with these; the server recalculates it on submit
 * (HomeServiceRequest::estimate()). No login, no session.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/booking/HomeServiceRequest.php';

sendCorsHeaders();
header('Content-Type: application/json');
header('Cache-Control: public, max-age=60');

try {
    echo json_encode(['success' => true, 'dpPercent' => HomeServiceRequest::DP_ESTIMATE_PERCENT]
        + HomeServiceRequest::priceList(Database::getInstance()));
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getHomeServicePrices error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Prices are unavailable right now.']);
}
