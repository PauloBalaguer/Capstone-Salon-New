<?php

/**
 * Philippine mobile numbers: accepted as 09XXXXXXXXX or +639XXXXXXXXX
 * (spaces, dashes, dots and brackets are ignored) and always stored as
 * 09XXXXXXXXX, so lookups by phone_number match however it was typed.
 * The browser uses the same rule (function/shared/booking-validation.js).
 * SMS sending converts to +639... separately (setup/sms.php normalizePhPhone).
 */
final class PhoneNumber
{
    public const MESSAGE = 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX (11 digits starting with 09).';

    /** '09XXXXXXXXX', or null when it isn't a PH mobile number. */
    public static function normalize(?string $input): ?string
    {
        $value = preg_replace('/[\s\-().]/', '', (string) $input);
        return preg_match('/^(?:09|\+639)(\d{9})$/D', $value, $m) ? '09' . $m[1] : null;
    }

    public static function selfCheck(): void
    {
        foreach (['09171234567', '+639171234567', '0917 123 4567', '+63 917-123-4567', '(0917) 123-4567'] as $ok) {
            if (self::normalize($ok) !== '09171234567') throw new LogicException("should accept {$ok}");
        }
        foreach (['1234', '', '9171234567', '639171234567', '0917123456', '091712345678', '08171234567', '+638171234567', '0917abc4567', '+6309171234567'] as $bad) {
            if (self::normalize($bad) !== null) throw new LogicException("should refuse {$bad}");
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    PhoneNumber::selfCheck();
    echo "PhoneNumber self-check passed.\n";
}
