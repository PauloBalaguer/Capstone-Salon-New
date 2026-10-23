<?php
// Run on a configured development DB. --migrate applies only migration 050.
require_once __DIR__ . '/../backend/config/database.php';
$pdo = Database::getInstance();
if (in_array('--migrate', $argv, true)) {
    $before = $pdo->query('SELECT COUNT(*) FROM home_service_requests')->fetchColumn();
    $pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/050_structured_addresses.sql'));
    $pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/050_structured_addresses.sql'));
    if ($pdo->query('SELECT COUNT(*) FROM home_service_requests')->fetchColumn() != $before) throw new RuntimeException('Migration changed history count.');
    echo "PASS: additive migration applied twice; historical request count unchanged.\n";
}
// Temporary clones have no production triggers, foreign keys, or customer data.
$pdo->exec('CREATE TEMPORARY TABLE address_test_customers LIKE customers');
$pdo->exec('CREATE TEMPORARY TABLE address_test_requests LIKE home_service_requests');
$pdo->exec("INSERT INTO address_test_customers (first_name, last_name, phone_number, address, structured_address) VALUES ('Address', 'Test', '09999999999', 'Original profile', '{\"street\":\"Original profile\"}')");
$customer = $pdo->lastInsertId();
$pdo->prepare("INSERT INTO address_test_requests (customer_id, reference_code, address, structured_address, event_type, preferred_date) VALUES (?, 'ADDRESS-TEST', 'Alternate venue', '{\"street\":\"Alternate venue\"}', 'Standard Home Service', '2030-01-01')")->execute([$customer]);
if ($pdo->query('SELECT address FROM address_test_customers')->fetchColumn() !== 'Original profile') throw new RuntimeException('Alternate service address overwrote profile.');
$pdo->exec("UPDATE address_test_customers SET address = 'New profile', structured_address = '{\"street\":\"New profile\"}'");
$historical = $pdo->query('SELECT address, structured_address FROM address_test_requests')->fetch();
if ($historical['address'] !== 'Alternate venue' || json_decode($historical['structured_address'], true)['street'] !== 'Alternate venue') throw new RuntimeException('Profile update altered booking snapshot.');
echo "PASS: alternate service address leaves profile intact; profile update preserves historical snapshot. Temporary rows only.\n";
