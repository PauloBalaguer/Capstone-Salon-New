<?php

/**
 * Public: Get Wedding Packages
 *
 * No login required — powers the "Wedding Packages" section on the public
 * landing page (index.html).
 */

require_once '../config/cors.php';
require_once '../config/database.php';

sendCorsHeaders();
header('Content-Type: application/json');

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->query('
        SELECT id, package_name AS packageName, price, reservation_fee AS reservationFee, features, style
        FROM wedding_packages
        WHERE is_active = 1
        ORDER BY display_order, id
    ');

    $packages = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['price'] = (float) $row['price'];
        $row['reservationFee'] = $row['reservationFee'] !== null ? (float) $row['reservationFee'] : null;
        $row['features'] = array_values(array_filter(array_map('trim', explode("\n", $row['features'])), function ($line) {
            return $line !== '';
        }));
        return $row;
    }, $stmt->fetchAll());

    echo json_encode(['success' => true, 'packages' => $packages]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('getWeddingPackages error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while loading wedding packages.']);
}
