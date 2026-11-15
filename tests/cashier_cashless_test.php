<?php
require __DIR__ . '/support/harness.php';
require_once __DIR__ . '/../backend/shared/config/payments/RevenueReport.php';

$pdo = clone_database('capstone_cashless_test');
$people = test_people($pdo);
$day = free_day($pdo, 35);
$staff = $pdo->prepare('SELECT id FROM employees WHERE branch_id = ? AND is_active = 1 LIMIT 1');
$staff->execute([1]);
$staffId = (int) $staff->fetchColumn();
$create = function (int $branch = 1) use ($pdo, $people, $staffId, $day): string {
    $pdo->prepare("INSERT INTO appointments (customer_id, employee_id, branch_id, appointment_datetime, total_price, status,
        deposit_paid, deposit_amount, deposit_method, deposit_recorded_by, deposit_recorded_at)
        VALUES (?, ?, ?, ?, 500, 'In Progress', 1, 100, 'Cash', ?, NOW())")
        ->execute([$people['customerA'], $staffId, $branch, $day . ' 10:00:00', $people['adminId']]);
    $stmt = $pdo->prepare('SELECT reference_code FROM appointments WHERE id = ?');
    $stmt->execute([$pdo->lastInsertId()]);
    return $stmt->fetchColumn();
};
$pay = fn(array $post, ?array $session = null) => run_endpoint(['script' => 'backend/cashier/payment.php', 'session' => $session ?? $people['cashier'], 'post' => $post]);
$get = fn(string $file, array $query, ?array $session = null) => run_endpoint(['script' => "backend/{$file}.php", 'method' => 'GET', 'session' => $session ?? $people['cashier'], 'get' => $query]);
$ref = $create();
$column = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'payment_reference'");
$column->execute();
if (!(int) $column->fetchColumn()) {
    $r = $pay(['reference' => $ref, 'paymentMethod' => 'Card', 'paymentReference' => 'CARD-SETUP']);
    check('before migration: cashless is safely refused instead of losing its reference', !$r['success'] && str_contains($r['message'], 'setup'));
    $r = $pay(['reference' => $ref, 'paymentMethod' => 'Cash', 'cashReceived' => '400']);
    check('before migration: cash checkout still works', $r['success'] ?? false);
    $r = $get('cashier/receipt', ['reference' => $ref]);
    check('before migration: receipt loads with null payment reference', ($r['success'] ?? false) && $r['receipt']['paymentReference'] === null);
    $r = $get('cashier/getPaymentRecords', []);
    check('before migration: payment records still load', $r['success'] ?? false);
} else {
    $pay(['reference' => $ref, 'paymentMethod' => 'Cash', 'cashReceived' => '400']);
}
$migration = file_get_contents(__DIR__ . '/../database/migrations/067_cashier_cashless_reference.sql');
foreach ([1, 2] as $run) {
    foreach (explode(';', $migration) as $sql) if (trim($sql) !== '') $pdo->prepare($sql)->execute();
}
check('migration can run twice without losing existing payments', ($get('cashier/receipt', ['reference' => $ref])['success'] ?? false) === true);

$before = RevenueReport::checkoutByMethod($pdo, 1);
$refs = [];
foreach (['GCash', 'Maya', 'Card'] as $method) {
    $reference = $create();
    $refs[$method] = $reference;
    foreach (['', '   ', str_repeat('X', 101), '<script>'] as $bad) {
        $r = $pay(['reference' => $reference, 'paymentMethod' => $method, 'paymentReference' => $bad]);
        check("{$method}: missing or invalid reference refused", !$r['success'] && isset($r['errors']['paymentReference']));
    }
    $post = ['reference' => $reference, 'paymentMethod' => $method, 'paymentReference' => $method . '-123456', 'tip' => '50'];
    $r = $pay($post, $people['sessionA']);
    check("{$method}: customer cannot record a cashier payment", !$r['success']);
    $r = $pay($post);
    check("{$method}: valid reference completes checkout", $r['success'] ?? false);
    $receipt = $get('cashier/receipt', ['reference' => $reference])['receipt'] ?? [];
    check("{$method}: receipt preserves reference, deposit deducted, cash-only tip excluded", ($receipt['paymentReference'] ?? '') === $post['paymentReference']
        && ($receipt['paymentMethod'] ?? '') === $method && ($receipt['total'] ?? 0) == 400 && ($receipt['tip'] ?? -1) == 0 && $receipt['cashReceived'] === null);
    $r = $pay($post);
    check("{$method}: repeat checkout refused", !$r['success']);
}
$cashRef = $create();
$r = $pay(['reference' => $cashRef, 'paymentMethod' => 'Cash', 'tip' => '25', 'cashReceived' => '400']);
check('cash: insufficient cash still refused', !$r['success']);
$r = $pay(['reference' => $cashRef, 'paymentMethod' => 'Cash', 'tip' => '25', 'cashReceived' => '500']);
check('cash: no reference required', $r['success'] ?? false);
$receipt = $get('cashier/receipt', ['reference' => $cashRef])['receipt'] ?? [];
check('cash: tip and change unchanged', ($receipt['total'] ?? 0) == 425 && ($receipt['changeGiven'] ?? 0) == 75 && $receipt['paymentReference'] === null);
$other = $create(2);
$r = $pay(['reference' => $other, 'paymentMethod' => 'Card', 'paymentReference' => 'OTHER-1']);
check('cashier cannot charge another branch booking', !$r['success']);

$records = $get('cashier/getPaymentRecords', [])['records'] ?? [];
$report = $get('admin/getRevenueReport', ['branch' => 'daraga'], $people['admin']);
foreach ($refs as $method => $reference) {
    check("{$method}: payment records include transaction reference", count(array_filter($records, fn($r) => $r['reference'] === $reference && $r['paymentReference'] === $method . '-123456')) === 1);
    $rows = array_filter($report['collections'] ?? [], fn($r) => $r['reference'] === $reference && $r['kind'] === 'Cashier checkout');
    check("{$method}: existing admin method breakdown receives exact checkout amount", count($rows) === 1 && reset($rows)['method'] === $method && reset($rows)['amount'] == 400);
}
$totals = RevenueReport::checkoutByMethod($pdo, 1);
check('EOD groups checkout totals without counting deposits twice', $totals['Cash'] - $before['Cash'] == 425
    && $totals['GCash'] - $before['GCash'] == 400 && $totals['Maya'] - $before['Maya'] == 400 && $totals['Card'] - $before['Card'] == 400);
$dashboard = $get('cashier/getDashboardData', []);
check('cashier dashboard exposes method totals', ($dashboard['eodByBranch']['daraga']['methodTotals'] ?? []) == $totals);
$pdo->prepare('UPDATE daily_closures SET is_reopened = 1 WHERE branch_id = ? AND business_date = CURDATE()')->execute([1]);
$closed = run_endpoint(['script' => 'backend/cashier/closeEod.php', 'session' => $people['cashier']]);
check('close day returns matching method totals', ($closed['success'] ?? false) && ($closed['methodTotals'] ?? []) == $totals
    && abs(array_sum($totals) - ($closed['grossRevenue'] ?? 0)) < 0.01);
$snapshot = $pdo->prepare('SELECT payment_method_totals FROM daily_closures WHERE branch_id = ? AND business_date = CURDATE()');
$snapshot->execute([1]);
check('EOD preserves method breakdown in its audit snapshot', json_decode($snapshot->fetchColumn() ?: '{}', true) == $totals);
finish();
