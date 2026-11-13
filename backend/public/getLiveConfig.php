<?php

/**
 * Live updates: the browser's half of the Pusher settings.
 *
 * Returns { success, enabled, key, cluster }. The key and cluster are public
 * by design (Pusher puts them in every browser); the secret never leaves the
 * server. With no Pusher settings it returns enabled:false and the panels
 * simply keep their once-a-minute check. No login, no session, no database.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/setup/live-updates.php';

sendCorsHeaders();
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

$config = LiveUpdates::publicConfig();
echo json_encode($config === null
    ? ['success' => true, 'enabled' => false]
    : ['success' => true, 'enabled' => true] + $config);
