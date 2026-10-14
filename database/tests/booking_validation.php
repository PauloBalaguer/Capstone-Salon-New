<?php
// Pure request-validation regression checks. No database or session writes.
require_once __DIR__ . '/../../backend/config/BookingValidation.php';

$cases = [
    ['mobile', '09171234567', true], ['mobile', '12345678901', false],
    ['mobile', ['09171234567'], false], ['mobile', '0917e234567', false],
    ['email', 'customer@example.com', true], ['email', 'customer@', false],
    ['id', '12', true], ['id', '12abc', false], ['id', '1.5', false],
    ['id', '-1', false], ['id', '2147483648', false], ['id', ['1'], false],
    ['ids', ['1', '2'], true], ['ids', ['1', '1'], false],
    ['ids', ['1', ['2']], false], ['ids', ['1', '2bad'], false],
    ['staffMap', [1 => '2'], true], ['staffMap', ['bad' => '2'], false],
    ['staffMap', [1 => ['2']], false], ['staffMap', '2', false],
    ['date', '2028-02-29', true], ['date', '2026-02-29', false],
    ['date', '2026-04-31', false], ['date', '2026-1-1', false],
    ['time', '09:30 AM', true], ['time', '13:30', true],
    ['time', '13:30:00', true], ['time', '25:00', false], ['time', '09:75 AM', false],
    ['quantity', '2', true], ['quantity', '2.5', false], ['quantity', '1e2', false],
    ['quantity', '0', false], ['money', '100.50', true], ['money', '0', true],
    ['money', '-10', false], ['money', 'NaN', false], ['money', 'INF', false],
    ['money', '1e3', false], ['money', '1.001', false], ['money', '100000000', false],
    ['address', '123 Main Street', true], ['address', '   ', false],
    ['address', str_repeat('a', 501), false], ['strings', [['Hair Styling']], false],
];
foreach ($cases as [$type, $value, $expected]) {
    $actual = !BookingValidation::errors(['field' => $value], ['field' => [$type, true, 'a booking value']]);
    if ($actual !== $expected) throw new RuntimeException('Unexpected validation result: ' . json_encode([$type, $value]));
}
if (BookingValidation::errors([], ['email' => ['email', false, 'an email address']])) throw new RuntimeException('Optional email rejected.');
if (!BookingValidation::errors([], ['name' => ['text', true, 'your name']])) throw new RuntimeException('Required name accepted.');
echo count($cases) + 2, " booking validation checks passed.\n";
