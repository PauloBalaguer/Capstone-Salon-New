<?php
// CLI-only maintenance import. Runtime forms use the resulting local file.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function fetchAlbayList(string $path, string $prefix): array
{
    $curl = curl_init('https://psgc.gitlab.io/api/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $rows = $status === 200 && is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($rows) || !$rows) throw new RuntimeException('Could not import ' . $path . '; existing dataset unchanged.');
    $result = [];
    foreach ($rows as $row) {
        $code = $row['code'] ?? '';
        if (!is_string($code) || !preg_match('/^[0-9]{9}$/D', $code) || !str_starts_with($code, $prefix) || !is_string($row['name'] ?? null)) {
            throw new RuntimeException('Invalid or out-of-Albay location in import; existing dataset unchanged.');
        }
        $result[] = ['code' => $code, 'name' => $row['name']];
    }
    usort($result, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $result;
}
$cities = fetchAlbayList('provinces/050500000/cities-municipalities/', '0505');
if (count($cities) !== 18) throw new RuntimeException('Expected 18 Albay cities/municipalities; review upstream changes before replacing data.');
$barangays = [];
foreach ($cities as $city) $barangays[$city['code']] = fetchAlbayList('cities-municipalities/' . $city['code'] . '/barangays/', substr($city['code'], 0, 6));
$data = ['source' => 'https://psgc.gitlab.io/api/', 'retrieved_at' => gmdate('c'), 'provinces' => [['code' => '050500000', 'name' => 'Albay']], 'cities' => ['050500000' => $cities], 'barangays' => $barangays];
$dir = __DIR__ . '/../../backend/shared/data';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$file = $dir . '/albay-locations.json';
$temp = $file . '.tmp';
if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false || !rename($temp, $file)) {
    throw new RuntimeException('Could not save Albay dataset.');
}
echo 'Imported Albay only: ' . count($cities) . ' cities/municipalities, ' . array_sum(array_map('count', $barangays)) . " barangays.\n";
