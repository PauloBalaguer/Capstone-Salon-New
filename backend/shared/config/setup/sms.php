<?php

/**
 * textbee.dev SMS gateway transport (texts go out from a registered Android
 * phone). Mirrors mail.php: true means textbee accepted the request, not
 * handset delivery. Queueing, quiet hours and the daily cap live in
 * backend/shared/config/notifications/SmsOutbox.php.
 */

require_once __DIR__ . '/env.php';

/** The sender shows as a plain phone number, so every text names the salon first. */
const SMS_PREFIX = 'Leo Mejillano Salon: ';

/**
 * Every SMS text, in one place. Philippine carriers (Globe/Smart, per NTC)
 * block texts with links, so NO URLs here. Keep them plain ASCII: one
 * accented letter or em dash switches the SMS to Unicode, which cuts the
 * limit from 160 to 70 characters. Placeholders: {ref} booking reference,
 * {when} schedule (e.g. "Oct 25, 2:30 PM"), {code} verification code.
 * Notification types without an entry get email/in-app only.
 */
const SMS_TEMPLATES = [
    'BOOKING_SUBMITTED' => 'We received your booking {ref} for {when}. We will text you once it is confirmed.',
    'CONFIRMED' => 'Your booking {ref} on {when} is confirmed. See you!',
    'CANCELLED' => 'Your booking {ref} on {when} was cancelled. Please contact the branch if this is unexpected.',
    'RESCHEDULE' => 'There is a schedule update for your booking {ref}. Please check your email for details.',
    'PAYMENT_VERIFIED' => 'Your payment for booking {ref} has been verified. Thank you!',
    'PAYMENT_ATTENTION' => 'Your payment for booking {ref} needs attention. Please contact the branch.',
    'WAITLIST_OPENING' => 'Your preferred stylist now has an opening. Please check your email to book it.',
    'QUOTE_READY' => 'Your home service quote for {ref} is ready. Please check your email for details.',
    'REMINDER' => 'Reminder: your appointment {ref} is on {when}. See you!',
    'BOOKING_OTP' => 'Your booking verification code is {code}. It expires in 10 minutes. Do not share this code.',
    'TEST' => 'This is a test message. SMS notifications are working.',
];

/** The {when} placeholder format, e.g. "Oct 25, 2:30 PM" (Philippine time). */
function formatSmsWhen(string $datetime): string
{
    return (new DateTimeImmutable($datetime, new DateTimeZone('Asia/Manila')))->format('M j, g:i A');
}

/** 09XXXXXXXXX, 9XXXXXXXXX, 639XXXXXXXXX, +639XXXXXXXXX (spaces/dashes/dots/brackets allowed) -> +639XXXXXXXXX, else null. */
function normalizePhPhone(string $phone): ?string
{
    $digits = preg_replace('/[\s\-().]/', '', $phone);
    return preg_match('/^(?:\+?63|0)?(9\d{9})$/', $digits, $m) ? '+63' . $m[1] : null;
}

/** Fills a template and adds the salon prefix. null if the type has no template or a placeholder had no value. */
function renderSms(string $type, array $vars = []): ?string
{
    if (!isset(SMS_TEMPLATES[$type])) return null;
    $text = strtr(SMS_TEMPLATES[$type], $vars);
    if (preg_match('/\{\w+\}/', $text)) return null;
    return mb_substr(SMS_PREFIX . $text, 0, 160);
}

/**
 * Sends one SMS through textbee. Returns true only on a 2xx response.
 * Logs numeric diagnostics only: no phone numbers, keys or message text.
 */
function sendSms(string $phone, string $message): bool
{
    $to = normalizePhPhone($phone);
    if ($to === null) {
        error_log('SMS not sent: invalid Philippine mobile number.');
        return false;
    }

    $apiKey = env('TEXTBEE_API_KEY');
    $deviceId = env('TEXTBEE_DEVICE_ID');
    // The device ID goes into the URL path, so it must be a plain token.
    if (!preg_match('/^\S+$/', $apiKey) || !preg_match('/^[\w-]+$/', $deviceId)) {
        error_log('SMS not sent: API key or device ID is missing or invalid.');
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('SMS not sent: PHP cURL is unavailable.');
        return false;
    }

    $handle = null;
    try {
        $handle = curl_init('https://api.textbee.dev/api/v1/gateway/devices/' . $deviceId . '/send-sms');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode(['recipients' => [$to], 'message' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($response === false || $errorNumber !== 0) {
            error_log('textbee SMS request failed: cURL error ' . $errorNumber . '.');
            return false;
        }
        if ($status < 200 || $status >= 300) {
            error_log('textbee SMS request rejected: HTTP ' . $status . '.');
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('SMS not sent: payload encoding or transport failure.');
        return false;
    } finally {
        if ($handle) curl_close($handle);
    }
}

// Self-check: php backend/shared/config/setup/sms.php
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $assert = function (bool $ok, string $what): void { if (!$ok) { echo "FAILED: {$what}\n"; exit(1); } };
    foreach (['09171234567', '9171234567', '639171234567', '+63 917-123-4567', '(0917) 123 4567'] as $ok) {
        $assert(normalizePhPhone($ok) === '+639171234567', $ok);
    }
    foreach (['', '0817123456', '08171234567', '091712345678', '+1 917 123 4567', 'abc'] as $bad) {
        $assert(normalizePhPhone($bad) === null, $bad);
    }
    foreach (SMS_TEMPLATES as $type => $template) {
        $text = renderSms($type, ['{ref}' => 'LM-HS-2026-00001', '{when}' => 'Sep 30, 12:30 PM', '{code}' => '123456']);
        $assert($text !== null && strlen($text) <= 160 && !preg_match('/https?:|www\.|\.com|[^\x20-\x7E]/i', $text), $type);
    }
    $assert(renderSms('CONFIRMED', []) === null, 'missing placeholder');
    echo "sms.php self-check passed.\n";
}
