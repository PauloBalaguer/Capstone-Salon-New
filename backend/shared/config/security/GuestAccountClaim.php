<?php

require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/../notifications/SmsOutbox.php';

/**
 * Signing up with a mobile number that only has guest bookings
 * (customers.user_id IS NULL). Signup verifies the email, not the phone, so a
 * texted code must prove the number before its bookings and loyalty points join
 * the account: otherwise anyone could claim them by typing someone's number.
 * The account stays inactive until then (migration 059).
 */
final class GuestAccountClaim
{
    private const CODE_MINUTES = 10;
    private const RESEND_SECONDS = 60;
    private const MAX_CODE_ATTEMPTS = 5;

    public static function forUser(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM guest_account_claims WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The email is proven (just now, or by a password at login): hand this
     * browser a token for the phone step and text the code. A new token replaces
     * any older one, so only the latest tab can finish.
     */
    public static function startPhoneStep(PDO $pdo, array $claim): array
    {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE guest_account_claims SET email_verified = 1, token_hash = ? WHERE user_id = ?')
            ->execute([hash('sha256', $token), $claim['user_id']]);

        return [
            'needsPhoneCode' => true,
            'claimToken' => $token,
            'phoneHint' => substr($claim['phone_number'], -4),
            'codeSent' => self::sendCode($pdo, $claim),
        ];
    }

    /** False when the text couldn't go out; a code already delivered then stays valid. */
    public static function sendCode(PDO $pdo, array $claim): bool
    {
        $stmt = $pdo->prepare('SELECT code IS NOT NULL AND code_expires_at > NOW() AND code_sent_at > NOW() - INTERVAL ? SECOND FROM guest_account_claims WHERE user_id = ?');
        $stmt->execute([self::RESEND_SECONDS, $claim['user_id']]);
        if ($stmt->fetchColumn()) {
            return true; // just sent, e.g. signing in twice in a row
        }

        // Nobody can burn the SMS quota, or spam a stranger, with one number.
        $limiter = new RateLimiter($claim['phone_number'], 'guest_claim_sms', 3, 3600);
        if ($limiter->isExceeded()) {
            return false;
        }
        $limiter->record();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!SmsOutbox::sendNow($pdo, $claim['phone_number'], 'ACCOUNT_OTP', renderSms('ACCOUNT_OTP', ['{code}' => $code]), renderSms('ACCOUNT_OTP', ['{code}' => '******']))) {
            return false;
        }
        $pdo->prepare('UPDATE guest_account_claims SET code = ?, code_expires_at = NOW() + INTERVAL ? MINUTE, code_sent_at = NOW(), code_attempts = 0 WHERE user_id = ?')
            ->execute([$code, self::CODE_MINUTES, $claim['user_id']]);
        return true;
    }

    /** Null if the code is right; otherwise why not. A wrong code counts towards the limit. */
    public static function codeProblem(PDO $pdo, array $claim, string $code): ?string
    {
        $stmt = $pdo->prepare('SELECT code IS NOT NULL AND code_expires_at > NOW() FROM guest_account_claims WHERE user_id = ?');
        $stmt->execute([$claim['user_id']]);
        if (!$stmt->fetchColumn()) {
            return 'That code has expired. Please request a new one.';
        }
        if ((int) $claim['code_attempts'] >= self::MAX_CODE_ATTEMPTS) {
            return 'Too many wrong codes. Please request a new one.';
        }
        if (!hash_equals((string) $claim['code'], $code)) {
            $pdo->prepare('UPDATE guest_account_claims SET code_attempts = code_attempts + 1 WHERE user_id = ?')->execute([$claim['user_id']]);
            return 'That code is not correct. Please try again.';
        }
        return null;
    }

    /**
     * The number is proven: its guest record becomes this account's record, so
     * every booking, review and loyalty point stays attached without being moved.
     * False if the number got an account of its own in the meantime.
     */
    public static function linkGuestRecord(PDO $pdo, array $claim): bool
    {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, user_id FROM customers WHERE phone_number = ? FOR UPDATE');
        $stmt->execute([$claim['phone_number']]);
        $record = $stmt->fetch();
        if ($record && $record['user_id'] !== null) {
            $pdo->rollBack();
            return false;
        }

        if ($record) {
            // The account's email takes over from any email typed for a guest booking.
            $pdo->prepare('UPDATE customers SET user_id = ?, first_name = ?, last_name = ?, notify_sms = ?, email = NULL WHERE id = ?')
                ->execute([$claim['user_id'], $claim['first_name'], $claim['last_name'], $claim['notify_sms'], $record['id']]);
        } else {
            self::createCustomer($pdo, $claim, $claim['phone_number']);
        }
        self::activate($pdo, $claim);
        $pdo->commit();
        return true;
    }

    /** Can't prove the number: the account starts with a free one, and the guest bookings stay guest bookings. */
    public static function useOtherNumber(PDO $pdo, array $claim, string $phone): void
    {
        $pdo->beginTransaction();
        self::createCustomer($pdo, $claim, $phone);
        self::activate($pdo, $claim);
        $pdo->commit();
    }

    private static function createCustomer(PDO $pdo, array $claim, string $phone): void
    {
        $pdo->prepare('INSERT INTO customers (user_id, first_name, last_name, phone_number, notify_sms) VALUES (?, ?, ?, ?, ?)')
            ->execute([$claim['user_id'], $claim['first_name'], $claim['last_name'], $phone, $claim['notify_sms']]);
    }

    private static function activate(PDO $pdo, array $claim): void
    {
        $pdo->prepare('UPDATE users SET is_active = 1, otp_code = NULL, otp_expiry = NULL WHERE id = ?')->execute([$claim['user_id']]);
        $pdo->prepare('DELETE FROM guest_account_claims WHERE user_id = ?')->execute([$claim['user_id']]);
    }
}
