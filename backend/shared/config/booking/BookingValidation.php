<?php

/** Validate raw request values before endpoint casts can hide malformed input. */
final class BookingValidation
{
    public static function errors(array $input, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $rule) {
            [$type, $required, $label] = $rule;
            $value = $input[$field] ?? '';
            if ($value === '' || $value === null || $value === []) {
                if ($required) $errors[$field] = "Please provide {$label}.";
                continue;
            }
            $valid = false;
            if ($type === 'ids' || $type === 'staffMap' || $type === 'strings') {
                $values = is_array($value) ? $value : [$value];
                $valid = count($values) <= 100 && ($type !== 'staffMap' || is_array($value));
                foreach ($values as $key => $item) {
                    if ($type === 'strings') {
                        $valid = $valid && is_string($item) && trim($item) !== '';
                    } else {
                        $valid = $valid && self::positiveId($item);
                        if ($type === 'staffMap') $valid = $valid && self::positiveId($key);
                    }
                }
                if ($type === 'ids') $valid = $valid && count(array_unique($values, SORT_REGULAR)) === count($values);
            } elseif (is_string($value) || is_int($value) || is_float($value)) {
                $value = trim((string) $value);
                switch ($type) {
                    case 'id': case 'quantity': $valid = self::positiveId($value); break;
                    case 'email': $valid = strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false; break;
                    case 'mobile': $valid = preg_match('/^09[0-9]{9}$/D', $value) === 1; break;
                    case 'name':
                        $valid = mb_strlen($value) <= 255 && preg_match("/^[\\p{L}\\p{M} .’'\\-]+$/u", $value) === 1
                            && preg_match('/\\p{L}/u', $value) === 1;
                        break;
                    case 'date':
                        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                        $valid = $date && $date->format('Y-m-d') === $value;
                        break;
                    case 'time':
                        $valid = preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?$/D', $value) === 1
                            || preg_match('/^(?:0?[1-9]|1[0-2]):[0-5][0-9] (?:AM|PM)$/D', $value) === 1;
                        break;
                    case 'money':
                        $valid = preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value) === 1
                            && is_finite((float) $value) && (float) $value <= 99999999.99;
                        break;
                    case 'address': $valid = mb_strlen($value) >= 5 && mb_strlen($value) <= 500; break;
                    default: $valid = $value !== '' && strlen($value) <= 255;
                }
            }
            if (!$valid) {
                $description = preg_replace('/^(?:a|an) /', '', $label);
                $errors[$field] = "Please enter a valid {$description}.";
                if (in_array($type, ['id', 'ids', 'staffMap'], true)) $errors[$field] = "Please select valid {$description}.";
                if ($type === 'quantity') $errors[$field] = 'Please enter a whole number of clients (at least 1).';
                if ($type === 'name') $errors[$field] = 'Please enter your name using letters, spaces, apostrophes, periods or hyphens.';
                if ($type === 'date') $errors[$field] = 'Please select a valid appointment date.';
                if ($type === 'time') $errors[$field] = 'Please select a valid appointment time.';
                if ($type === 'money') $errors[$field] = 'Please enter a non-negative amount with at most two decimal places.';
            }
        }
        return $errors;
    }

    private static function positiveId($value): bool
    {
        return (is_string($value) || is_int($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) !== false;
    }

    public static function enforce(array $input, array $rules): void
    {
        $errors = self::errors($input, $rules);
        // All remaining endpoint inputs are scalars unless explicitly declared collections.
        foreach ($input as $field => $value) {
            if (is_array($value) && !in_array($rules[$field][0] ?? '', ['ids', 'staffMap', 'strings'], true)) {
                $errors[$field] = 'Please provide a single value for this field.';
            }
        }
        if (!$errors) return;
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => reset($errors), 'errors' => $errors]);
        exit;
    }
}
