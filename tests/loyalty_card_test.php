<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/shared/config/setup/database.php';
require_once __DIR__ . '/../backend/shared/config/helpers/LoyaltyCard.php';
$pdo = Database::getInstance();
$checks = 0;
function verify(string $name, bool $condition): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    $checks++; echo 'PASS ' . $name . PHP_EOL;
}
function rejects(string $name, callable $action): void {
    try { $action(); } catch (RuntimeException $e) { verify($name, true); return; }
    verify($name, false);
}
try {
    $pdo->beginTransaction();
    $customerId = (int) $pdo->query('SELECT id FROM customers c WHERE NOT EXISTS (SELECT 1 FROM loyalty_stamps s WHERE s.customer_id = c.id) LIMIT 1')->fetchColumn();
    $branches = $pdo->query('SELECT id FROM branches ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    if (!$customerId || count($branches) < 2) throw new RuntimeException('Test needs a customer with no stamps and two branches.');
    $fixtureIds = range(-2055011, -2055000);
    $check = $pdo->query('SELECT COUNT(*) FROM loyalty_stamps WHERE appointment_id BETWEEN -2055011 AND -2055000');
    if ($check->fetchColumn()) throw new RuntimeException('Fixture IDs already exist.');
    $appointment = ['id' => -2055000, 'customer_id' => $customerId, 'branch_id' => $branches[0], 'total_price' => 1000, 'deposit_paid' => 0, 'deposit_amount' => 0, 'loyalty_points_used' => 0];
    LoyaltyCard::earn($pdo, $appointment, 499.99);
    verify('Below PHP500 earns no stamp', LoyaltyCard::summary($pdo, $customerId)['totalStamps'] === 0);
    LoyaltyCard::earn($pdo, $appointment, 500);
    LoyaltyCard::earn($pdo, $appointment, 500);
    verify('PHP500 earns exactly one stamp, even on retry', LoyaltyCard::summary($pdo, $customerId)['totalStamps'] === 1);
    for ($i = 1; $i < 5; $i++) {
        $appointment['id'] = -2055000 - $i;
        $appointment['branch_id'] = $branches[$i % 2];
        LoyaltyCard::earn($pdo, $appointment, 500);
    }
    $card = LoyaltyCard::summary($pdo, $customerId);
    verify('Stamps combine across branches', $card['stamps'] === 5);
    verify('Fifth stamp unlocks one 20% reward', count($card['rewards']) === 1 && $card['rewards'][0]['type'] === 'discount20');
    $discountId = (int) $card['rewards'][0]['id'];
    $appointment['id'] = -2055010;
    $appointment['branch_id'] = $branches[1];
    $appointment['deposit_paid'] = 1;
    $appointment['deposit_amount'] = 900;
    rejects('Deposit cannot exceed discounted services', fn() => LoyaltyCard::redeem($pdo, $appointment, [], $discountId, 0));
    $appointment['deposit_amount'] = 100;
    $appointment['loyalty_points_used'] = 1;
    rejects('Legacy points cannot stack with rewards', fn() => LoyaltyCard::redeem($pdo, $appointment, [], $discountId, 0));
    $appointment['loyalty_points_used'] = 0;
    verify('20% reward redeems at a different branch', LoyaltyCard::redeem($pdo, $appointment, [], $discountId, 0) === 200.0);
    rejects('Same reward cannot be redeemed twice', fn() => LoyaltyCard::redeem($pdo, $appointment, [], $discountId, 0));
    verify('Redeemed reward disappears from available rewards', count(LoyaltyCard::summary($pdo, $customerId)['rewards']) === 0);
    for ($i = 5; $i < 10; $i++) {
        $appointment['id'] = -2055000 - $i;
        $appointment['branch_id'] = $branches[$i % 2];
        LoyaltyCard::earn($pdo, $appointment, 500);
    }
    $card = LoyaltyCard::summary($pdo, $customerId);
    verify('Ten stamps starts a new card', $card['stamps'] === 0 && $card['completedCards'] === 1);
    verify('Tenth stamp unlocks free treatment', count($card['rewards']) === 1 && $card['rewards'][0]['type'] === 'treatment');
    $services = [['id' => 1, 'price' => 600, 'loyalty_treatment_eligible' => 1], ['id' => 2, 'price' => 400, 'loyalty_treatment_eligible' => 0]];
    verify('Free treatment uses net allocated service price', LoyaltyCard::discount('treatment', 800, $services, 1) === 480.0);
    rejects('Other treatments cannot be redeemed', fn() => LoyaltyCard::discount('treatment', 1000, $services, 2));
    rejects('Unbooked treatment cannot be redeemed', fn() => LoyaltyCard::discount('treatment', 1000, $services, 99));
    $appointment['id'] = -2055011;
    $appointment['deposit_paid'] = 0;
    $appointment['deposit_amount'] = 0;
    verify('Free treatment can be redeemed on later visit', LoyaltyCard::redeem($pdo, $appointment, $services, (int) $card['rewards'][0]['id'], 1) === 600.0);
    $pdo->rollBack();
    echo "All {$checks} checks passed; fixtures rolled back.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1);
}
