<?php

/**
 * The frontend pages are usually opened through a static file server (e.g. VS Code
 * Live Server on port 5500) while the PHP endpoints run under Apache/XAMPP on a
 * different origin. Browsers block that kind of cross-origin fetch() unless the
 * server explicitly allows it via CORS headers. This helper reflects back the
 * request's Origin only if it's in our local-dev allowlist, and answers the
 * browser's CORS preflight (OPTIONS) request immediately.
 */
function sendCorsHeaders(): void
{
    $allowedOrigins = [
        'http://127.0.0.1:5500',
        'http://localhost:5500',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
