<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/config/ServiceDuration.php';
function durationCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach ([['09:00', 30, '09:30'], ['13:00', 60, '14:00'], ['10:00', 120, '12:00'], ['09:00', 240, '13:00']] as [$start, $minutes, $expected]) {
    durationCheck(ServiceDuration::end('2026-10-20 ' . $start, $minutes)->format('H:i') === $expected, 'Incorrect estimated end time.');
}
foreach ([30 => '30 minutes', 45 => '45 minutes', 60 => '1 hour', 75 => '1 hour 15 minutes', 90 => '1 hour 30 minutes', 120 => '2 hours', 150 => '2 hours 30 minutes', 240 => '4 hours'] as $minutes => $text) {
    durationCheck(ServiceDuration::format($minutes) === $text, 'Incorrect duration text.');
}
foreach ([0, -30, '', '0', '-30', '30abc', '30.5', '3e1', [], null, true, '2147483648'] as $bad) durationCheck(ServiceDuration::parse($bad) === null, 'Invalid duration was accepted.');
foreach ([1, 10, 15, 30, 45, '60'] as $valid) durationCheck(ServiceDuration::parse($valid) === (int) $valid, 'Positive integer was rejected.');
durationCheck(ServiceDuration::end('2026-10-20 23:30', 60)->format('Y-m-d H:i') === '2026-10-21 00:30', 'Date rollover failed.');
echo "PASS: four requested end-time examples, eight readable formats, integer validation, short services, and date rollover.\n";
