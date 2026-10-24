<?php
// CLI only. Preview by default; --apply changes duration fields only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/shared/config/setup/database.php';
require_once __DIR__ . '/../../backend/shared/config/booking/ServiceDuration.php';

function durationNameKey(string $name): string
{
    $name = mb_strtolower($name, 'UTF-8');
    $name = str_replace(['w/', '&', 'hair cut', 'blowdry', 'footspa', 'softgel'], ['with ', 'and', 'haircut', 'blow dry', 'foot spa', 'soft gel'], $name);
    $name = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)));
}

$reference = json_decode(file_get_contents(__DIR__ . '/../data/service-duration-reference.json'), true, 512, JSON_THROW_ON_ERROR);
$entries = $reference['entries'];
$pdo = Database::getInstance();
$rows = $pdo->query('SELECT s.*, b.branch_key FROM services s JOIN branches b ON b.id=s.branch_id ORDER BY b.branch_key, s.id')->fetchAll();
$matches = []; $unmatched = []; $used = [];
foreach ($rows as $row) {
    $groupWide = str_starts_with($row['category'] ?? '', 'Group-Wide');
    $scope = $groupWide ? 'group-wide' : $row['branch_key'];
    $candidates = [];
    foreach ($entries as $index => $entry) {
        if ($entry['branch'] === $scope && durationNameKey($entry['name']) === durationNameKey($row['service_name'])) $candidates[$index] = $entry;
    }
    if (count(array_unique(array_column($candidates, 'minutes'))) > 1) {
        $category = durationNameKey($row['category'] ?? '');
        $candidates = array_filter($candidates, fn($entry) => str_contains($category, durationNameKey($entry['section'])));
    }
    $values = array_unique(array_column($candidates, 'minutes'));
    if (count($values) !== 1) {
        $unmatched[] = $row + ['reason' => count($values) > 1 ? 'Ambiguous document durations' : 'No clear branch-specific name match'];
        continue;
    }
    $minutes = (int) reset($values);
    if (ServiceDuration::parse($minutes) === null) throw new RuntimeException('Invalid document duration.');
    foreach (array_keys($candidates) as $index) $used[$index] = true;
    $matches[] = $row + ['document_minutes' => $minutes, 'document_names' => implode('; ', array_unique(array_column($candidates, 'name')))];
}
$apply = in_array('--apply', $argv, true);
$changed = 0;
if ($apply) {
    $backup = tempnam(sys_get_temp_dir(), 'salon-duration-backup-');
    if (!$backup || file_put_contents($backup, json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot back up service records.');
    echo "Service backup: $backup\n";
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE services SET duration_minutes = ?, duration_label = ? WHERE id = ? AND duration_minutes = ? AND duration_label <=> ?');
        foreach ($matches as $row) {
            $label = ServiceDuration::format($row['document_minutes']);
            if ((int) $row['duration_minutes'] === $row['document_minutes'] && $row['duration_label'] === $label) continue;
            $update->execute([$row['document_minutes'], $label, $row['id'], $row['duration_minutes'], $row['duration_label']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('A service changed during import; nothing was applied.');
            $changed++;
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
function mdCell($value): string { return str_replace(["\r", "\n", '|'], ['', ' ', '\\|'], (string) $value); }
$report = "# Service duration matching report\n\nSource: `" . $reference['source'] . "`. Only treatment duration values were used; document buffer recommendations were excluded.\n\n";
$report .= 'Mode: ' . ($apply ? 'Applied' : 'Preview') . '. Matched ' . count($matches) . ' existing service records; ' . count($unmatched) . ' records left unchanged. ' . ($apply ? "$changed duration/label updates applied." : 'No database changes.') . "\n\n";
$report .= "Matching is branch-specific, with only case, punctuation, and clear spelling normalization (hair cut/haircut, blowdry/blow dry, footspa/foot spa, softgel/soft gel, w\//with, &/and). No fuzzy matching, new services, renames, price changes, or branch merging. Group-wide reference entries apply only to existing Group-Wide catalog categories. Conflicting durations require a matching section/category.\n\n";
$report .= "## Matched existing services\n\n| Branch | ID | Existing service | Category | Previous minutes | Document minutes | Document name |\n|---|---:|---|---|---:|---:|---|\n";
foreach ($matches as $row) $report .= '| ' . implode(' | ', array_map('mdCell', [$row['branch_key'], $row['id'], $row['service_name'], $row['category'], $row['duration_minutes'], $row['document_minutes'], $row['document_names']])) . " |\n";
$report .= "\n## Existing services left unchanged\n\n| Branch | ID | Existing service | Category | Retained minutes | Reason |\n|---|---:|---|---|---:|---|\n";
foreach ($unmatched as $row) $report .= '| ' . implode(' | ', array_map('mdCell', [$row['branch_key'], $row['id'], $row['service_name'], $row['category'], $row['duration_minutes'], $row['reason']])) . " |\n";
$report .= "\n## Document rows without a clear existing-service match\n\n| Scope | Section | Document service | Minutes |\n|---|---|---|---:|\n";
foreach ($entries as $index => $entry) if (!isset($used[$index])) $report .= '| ' . implode(' | ', array_map('mdCell', [$entry['branch'], $entry['section'], $entry['name'], $entry['minutes']])) . " |\n";
$report .= "\nHome-service rows are intentionally excluded because this task does not change home-service or wedding scheduling. Unmatched services retain their current duration for admin review.\n";
file_put_contents(__DIR__ . '/../docs/SERVICE_DURATION_MATCHES.md', $report);
echo ($apply ? 'Applied' : 'Preview') . ': ' . count($matches) . ' matched; ' . count($unmatched) . ' unchanged; ' . count($entries) - count($used) . " unmatched document rows.\nReport: database/docs/SERVICE_DURATION_MATCHES.md\n";
