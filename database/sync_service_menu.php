<?php
// CLI only: synchronize booking services with the website's source price menu.
// Preview: php database/sync_service_menu.php; apply: append --apply.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/config/database.php';

function menuLiteral(string $source, string $constant): array {
    if (!preg_match('/const ' . preg_quote($constant, '/') . ' = (\{.*?\n  \});/s', $source, $match)) {
        throw new RuntimeException('Menu not found: ' . $constant);
    }
    // Parse the limited data literal without evaluating JavaScript.
    $json = preg_replace_callback("/'(?:\\\\.|[^'\\\\])*'|\\b[a-zA-Z_][a-zA-Z_0-9]*(?=\\s*:)/s", function ($token) {
        $value = $token[0];
        return json_encode($value[0] === "'" ? stripslashes(substr($value, 1, -1)) : $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }, $match[1]);
    $json = preg_replace('/,\s*([}\]])/', '$1', $json);
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

$source = file_get_contents(__DIR__ . '/../assets/js/main.js');
$catalog = menuLiteral($source, 'SERVICE_DATA');
$shared = menuLiteral($source, 'GROUP_WIDE_MENU');
$pdo = Database::getInstance();
$apply = in_array('--apply', $argv, true);
$branches = $pdo->query('SELECT id, branch_key FROM branches')->fetchAll(PDO::FETCH_KEY_PAIR);
$existing = $pdo->query('SELECT * FROM services ORDER BY id')->fetchAll();
if ($apply) {
    $backup = tempnam(sys_get_temp_dir(), 'salon-services-');
    if (!$backup || file_put_contents($backup, json_encode($existing, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Could not back up services.');
    }
    echo "Service backup: $backup\n";
}
$pdo->beginTransaction();
try {
    foreach ($catalog as $branchKey => $data) {
        $branchId = array_search($branchKey, $branches, true);
        if ($branchId === false) throw new RuntimeException('Unknown branch: ' . $branchKey);
        $used = []; $wanted = []; $created = 0;
        foreach (array_merge($data['menus'], [$shared]) as $menu) {
            if (str_contains($menu['title'], 'Home Service')) continue;
            foreach ($menu['cats'] as $category) {
                if ($category['name'] === 'Gift Certificates') continue;
                foreach ($category['items'] as $item) {
                    $price = str_replace(',', '', $item[1]);
                    if (!is_numeric($price)) throw new RuntimeException('Invalid price: ' . $item[0]);
                    $group = $menu['title'] . ' / ' . $category['name'];
                    if (strlen($group) > 100) throw new RuntimeException('Category too long: ' . $group);
                    $key = $item[0] . '|' . $price . '|' . $group;
                    if (isset($wanted[$key])) continue;
                    $wanted[$key] = true;
                    $match = null;
                    foreach ($existing as $row) {
                        if ((int)$row['branch_id'] !== (int)$branchId || isset($used[$row['id']])) continue;
                        if ($row['service_name'] === $item[0] && (float)$row['price'] === (float)$price) {
                            if ($match === null || $row['category'] === $group) $match = $row;
                            if ($row['category'] === $group) break;
                        }
                    }
                    if ($match) {
                        $id = $match['id'];
                        $pdo->prepare('UPDATE services SET category = ?, is_active = 1 WHERE id = ?')->execute([$group, $id]);
                    } else {
                        $pdo->prepare('INSERT INTO services (branch_id, service_name, category, price, duration_minutes, duration_label, is_active) VALUES (?, ?, ?, ?, 30, ?, 1)')
                            ->execute([$branchId, $item[0], $group, $price, '']);
                        $id = $pdo->lastInsertId();
                        $created++;
                    }
                    $used[$id] = true;
                }
            }
        }
        // Retire demo entries without deleting historical appointment links.
        foreach ($existing as $row) {
            if ((int)$row['branch_id'] === (int)$branchId && !isset($used[$row['id']])) {
                $pdo->prepare('UPDATE services SET is_active = 0 WHERE id = ?')->execute([$row['id']]);
            }
        }
        echo "$branchKey: " . count($used) . " menu entries, $created new\n";
    }
    if ($apply) { $pdo->commit(); echo "Applied. New entries use the 30-minute scheduling default; confirm durations in admin.\n"; }
    else { $pdo->rollBack(); echo "Preview only; no service changes saved.\n"; }
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
