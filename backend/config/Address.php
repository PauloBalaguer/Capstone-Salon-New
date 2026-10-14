<?php

/** Central local Albay dataset shared by forms and server validation. */
class Address
{
    /** Apply the confirmed Albay-only scope to all address-form requests. */
    public static function formLocations(string $level, string $parent = '', ?callable $lookup = null): array
    {
        $lookup = $lookup ?? [self::class, 'locations'];
        $rule = require __DIR__ . '/ServiceArea.php';
        $provinces = $rule['address_provinces'];
        if ($level === 'provinces') return array_values(array_filter($lookup('provinces', ''), fn($row) => in_array($row['code'], $provinces, true)));
        if ($level === 'cities' && in_array($parent, $provinces, true)) return $lookup($level, $parent);
        if ($level === 'barangays') {
            foreach ($provinces as $province) foreach ($lookup('cities', $province) as $city) {
                if ($city['code'] === $parent) return $lookup($level, $parent);
            }
        }
        throw new InvalidArgumentException('Only locations within Albay Province are supported for these address forms.');
    }

    public static function locations(string $level, string $parent = ''): array
    {
        if (!in_array($level, ['provinces', 'cities', 'barangays'], true)
            || ($level !== 'provinces' && !preg_match('/^[0-9]{9}$/D', $parent))) {
            throw new InvalidArgumentException('Unsupported location request.');
        }
        static $data = null;
        if ($data === null) {
            $file = __DIR__ . '/../data/albay-locations.json';
            $data = is_file($file) ? json_decode(file_get_contents($file), true) : null;
            if (!is_array($data) || !isset($data['provinces'], $data['cities'], $data['barangays'])) {
                throw new RuntimeException('Albay location data is unavailable. Please contact the salon.');
            }
        }
        if ($level === 'provinces') return $data['provinces'];
        // Unknown/out-of-area parents expose no options and cannot pass validation.
        return $data[$level][$parent] ?? [];
    }

    public static function validate($input, bool $homeService, ?callable $lookup = null): array
    {
        $lookup = $lookup ?? [self::class, 'locations'];
        $errors = [];
        if (!is_array($input)) return [null, ['address' => 'Please enter a structured address.']];
        $data = [];
        foreach (['province', 'city', 'barangay', 'house', 'street', 'subdivision', 'landmark'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen(trim($value)) > 150) $errors[$key] = 'Use at most 150 characters.';
            $data[$key] = is_string($value) ? trim($value) : '';
        }
        if (!$homeService && !array_filter($data) && !$errors) return [null, []];
        $rule = require __DIR__ . '/ServiceArea.php';
        if (!in_array($data['province'], $rule['address_provinces'], true)) {
            return [null, ['province' => 'Please select Albay Province. These address forms support Albay only.']];
        }
        $levels = ['province' => ['provinces', ''], 'city' => ['cities', $data['province']], 'barangay' => ['barangays', $data['city']]];
        foreach ($levels as $key => [$level, $parent]) {
            if (!preg_match('/^[0-9]{9}$/D', $data[$key])) {
                $errors[$key] = 'Please select a supported ' . ($key === 'city' ? 'city/municipality' : $key) . '.';
                continue;
            }
            if (($key === 'city' && isset($errors['province'])) || ($key === 'barangay' && isset($errors['city']))) {
                $errors[$key] = 'Please select a valid parent location first.'; continue;
            }
            $match = null;
            foreach ($lookup($level, $parent) as $row) if ($row['code'] === $data[$key]) $match = $row['name'];
            if ($match === null) $errors[$key] = 'This location does not belong to the selected parent or is unsupported.';
            else $data[$key . 'Name'] = $match;
        }
        if ($homeService) {
            $rule = require __DIR__ . '/ServiceArea.php';
            if (!isset($errors['province']) && !in_array($data['province'], $rule['home_service_provinces'], true)) {
                $errors['province'] = 'Home service is currently available only within Albay Province.';
            }
            if ($data['house'] === '' && $data['street'] === '' && $data['subdivision'] === '') {
                $errors['street'] = 'Enter a house/unit, street, or subdivision/purok/sitio so the team can find the venue.';
            }
        }
        if ($errors) return [null, $errors];
        $data['formatted'] = implode(', ', array_filter([$data['house'], $data['street'], $data['subdivision'], $data['barangayName'], $data['cityName'], $data['provinceName'], $data['landmark']]));
        if (mb_strlen($data['formatted']) > 500) return [null, ['address' => 'Please shorten the detailed address to 500 characters in total.']];
        return [$data, []];
    }

    public static function fromPost(bool $homeService): ?array
    {
        try {
            [$address, $errors] = self::validate(json_decode(is_string($_POST['structuredAddress'] ?? null) ? $_POST['structuredAddress'] : '', true), $homeService);
            if ($errors) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => reset($errors), 'errors' => $errors]);
                exit;
            }
            return $address;
        } catch (RuntimeException $e) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'errors' => ['address' => $e->getMessage()]]);
            exit;
        }
    }
}
