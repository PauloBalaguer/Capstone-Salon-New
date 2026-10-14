<?php
require_once __DIR__ . '/../../backend/config/Address.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$lookup = function ($level, $parent) {
    $rows = [
        'provinces/' => [['code' => '050500000', 'name' => 'Albay'], ['code' => '041000000', 'name' => 'Batangas']],
        'cities/050500000' => [['code' => '050506000', 'name' => 'Daraga']],
        'cities/041000000' => [['code' => '041005000', 'name' => 'Batangas City']],
        'barangays/050506000' => [['code' => '050506001', 'name' => 'Alcala']],
        'barangays/041005000' => [['code' => '041005001', 'name' => 'Alangilan']],
    ];
    return $rows["$level/$parent"] ?? [];
};
$input = ['province' => '050500000', 'city' => '050506000', 'barangay' => '050506001', 'house' => '12', 'street' => 'Test Street'];
[$valid, $errors] = Address::validate($input, true, $lookup);
check(!$errors && str_contains($valid['formatted'], 'Daraga'), 'Valid hierarchy should pass and use canonical names.');
[$value, $errors] = Address::validate($input + ['provinceName' => 'Forged'], true, $lookup);
check($value['provinceName'] === 'Albay', 'Never trust client names.');
[$value, $errors] = Address::validate(array_replace($input, ['city' => '041005000']), true, $lookup);
check(isset($errors['city'], $errors['barangay']), 'Reject city under wrong province.');
[$value, $errors] = Address::validate(array_replace($input, ['barangay' => '041005001']), true, $lookup);
check(isset($errors['barangay']), 'Reject barangay under wrong municipality.');
foreach (['', '999999999', '../test', ['050500000']] as $bad) {
    [$value, $errors] = Address::validate(array_replace($input, ['province' => $bad]), true, $lookup);
    check(isset($errors['province']), 'Reject unsupported or malformed location ID.');
}
$outside = ['province' => '041000000', 'city' => '041005000', 'barangay' => '041005001', 'street' => 'Test Street'];
check(isset(Address::validate($outside, false, $lookup)[1]['province']), 'Profile must respect the confirmed Albay scope.');
check(isset(Address::validate($outside, true, $lookup)[1]['province']), 'Home-service business rule should reject outside area.');
check(count(Address::formLocations('provinces', '', $lookup)) === 1, 'Form shows only Albay.');
check(Address::formLocations('cities', '050500000', $lookup)[0]['name'] === 'Daraga', 'Albay city choices remain available.');
foreach ([['cities', '041000000'], ['barangays', '041005000']] as [$level, $parent]) {
    try { Address::formLocations($level, $parent, $lookup); throw new RuntimeException('Outside location request was accepted.'); }
    catch (InvalidArgumentException $expected) {}
}
check(Address::validate([], false, $lookup) === [null, []], 'Profile address is optional.');
check(isset(Address::validate([], true, $lookup)[1]['province']), 'Home service requires location.');
check(isset(Address::validate(array_replace($input, ['house' => '', 'street' => '']), true, $lookup)[1]['street']), 'Home service requires a usable venue detail.');
check(!Address::validate(array_replace($input, ['house' => '', 'street' => '', 'subdivision' => 'Purok 1']), true, $lookup)[1], 'Do not require a street in areas that use purok.');
check(isset(Address::validate(array_replace($input, ['street' => str_repeat('x', 151)]), true, $lookup)[1]['street']), 'Reject overlong field.');
echo "PASS: hierarchy, manipulated IDs, canonical names, optional profile, venue details, and separate service-area rule.\n";

// Verify the shipped local dataset on every run; no network is required.
{
    $rows = Address::locations('provinces');
    check(count($rows) === 1 && $rows[0]['name'] === 'Albay', 'No other provinces or region groups should exist.');
    $cities = Address::locations('cities', '050500000');
    check(count($cities) === 18, 'All 18 Albay cities/municipalities from the supplied reference must load.');
    $formProvinces = Address::formLocations('provinces');
    check(count($formProvinces) === 1 && $formProvinces[0]['code'] === '050500000', 'The live form endpoint must expose Albay only.');
    $total = 0;
    $seen = [];
    foreach ($cities as $city) {
        check(str_starts_with($city['code'], '0505'), 'Only Albay cities may be included.');
        $barangays = Address::locations('barangays', $city['code']);
        check(count($barangays) > 0, 'Every Albay city must have barangays.');
        foreach ($barangays as $barangay) {
            check(str_starts_with($barangay['code'], substr($city['code'], 0, 6)), 'Barangay must belong to its municipality.');
            check(!isset($seen[$barangay['code']]), 'Barangay codes must be unique.');
            $seen[$barangay['code']] = true;
        }
        $total += count($barangays);
    }
    check($total === 720, 'Expected complete imported Albay barangay list.');
    check(Address::locations('cities', '130000000') === [], 'Other regions have no options.');
    check(Address::locations('barangays', '041005000') === [], 'Other provinces have no barangays.');
    echo "PASS: local Albay-only dataset; 18 cities/municipalities and 720 barangays; no other regions.\n";
}
