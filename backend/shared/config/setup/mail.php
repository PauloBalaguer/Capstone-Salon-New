<?php

require_once __DIR__ . '/env.php';

/**
 * Brevo Transactional Email API transport shared by all existing email helpers.
 * true means API acceptance, not inbox delivery. No simulated delivery or OTP logs.
 */
function sendBrevoEmail(string $email, string $subject, string $text, ?string $html = null): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log('Brevo email not sent: invalid recipient address.');
        return false;
    }

    $credentials = [
        'api_key' => env('BREVO_API_KEY'),
        'sender_email' => env('BREVO_SENDER_EMAIL'),
        'sender_name' => env('BREVO_SENDER_NAME', 'Leo Mejillano Salon & Beauty'),
        // Pinned provider endpoint, never configurable, so a typo/redirect cannot leak the API key.
        'api_url' => 'https://api.brevo.com/v3/smtp/email',
    ];
    foreach (['api_key', 'sender_email', 'sender_name'] as $key) {
        if (trim($credentials[$key]) === '') {
            error_log('Brevo email not sent: sender configuration is incomplete.');
            return false;
        }
    }
    if (!filter_var($credentials['sender_email'], FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $credentials['sender_name'])
        || preg_match('/\s/', $credentials['api_key'])) {
        error_log('Brevo email not sent: invalid sender or API key configuration.');
        return false;
    }
    if (!function_exists('curl_init') || !function_exists('curl_exec')) {
        error_log('Brevo email not sent: PHP cURL is unavailable.');
        return false;
    }

    $payload = [
        'sender' => ['email' => $credentials['sender_email'], 'name' => $credentials['sender_name']],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'textContent' => $text,
    ];
    if ($html !== null) $payload['htmlContent'] = $html;

    $handle = null;
    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $handle = curl_init($credentials['api_url']);
        if ($handle === false) {
            error_log('Brevo email not sent: could not initialize cURL.');
            return false;
        }
        if (!curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'api-key: ' . $credentials['api_key'],
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ])) {
            error_log('Brevo email not sent: could not configure cURL.');
            return false;
        }
        $response = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($response === false || $errorNumber !== 0) {
            // Numeric diagnostics only: no addresses, keys, bodies or OTPs in logs.
            error_log('Brevo email request failed: cURL error ' . $errorNumber . '.');
            return false;
        }
        if ($status !== 201) {
            error_log('Brevo email request rejected: HTTP ' . $status . '.');
            return false;
        }
        $result = json_decode($response, true);
        if (!is_array($result) || !is_string($result['messageId'] ?? null) || trim($result['messageId']) === '') {
            error_log('Brevo email not accepted: response has no message ID.');
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('Brevo email not sent: payload encoding or transport failure.');
        return false;
    } finally {
        if ($handle !== null && $handle !== false) curl_close($handle);
    }
}

/**
 * Renders an OTP-style email as HTML with just the code visually highlighted,
 * everything else stays plain text.
 */
function renderOtpEmailHtml(string $intro, string $code, string $note): string
{
    $intro = nl2br(htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'));
    $note = nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8'));
    $code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    return '<div style="font-family:Arial, Helvetica, sans-serif;font-size:15px;color:#1a1a1a;line-height:1.5;">'
        . '<p>' . $intro . '</p>'
        . '<p style="margin:20px 0;">'
        . '<span style="display:inline-block;font-size:28px;font-weight:700;letter-spacing:6px;'
        . 'color:#7a1f3d;background:#fdf0f3;border:1px solid #f0c9d4;border-radius:8px;padding:12px 20px;">'
        . $code . '</span></p>'
        . '<p>' . $note . '</p>'
        . '</div>';
}

/**
 * Sends the account-verification OTP to $email. Returns true only after
 * Brevo accepts the API request.
 */
function sendOtpEmail(string $email, string $otp): bool
{
    $body = "Your Leo Mejillano Salon & Aesthetics verification code is: {$otp}\n\nThis code expires in 10 minutes.";
    $subject = 'Your verification code';
    $html = renderOtpEmailHtml(
        'Your Leo Mejillano Salon & Aesthetics verification code is:',
        $otp,
        'This code expires in 10 minutes.'
    );
    $text = $body;

    return sendBrevoEmail($email, $subject, $text, $html);
}

/**
 * Sends the password-reset OTP to $email. Returns true only after Brevo
 * accepts the API request.
 */
function sendPasswordResetOtpEmail(string $email, string $code): bool
{
    $body = "Your Leo Mejillano Salon & Aesthetics password reset code is: {$code}\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this email.";
    $subject = 'Your password reset code';
    $html = renderOtpEmailHtml(
        'Your Leo Mejillano Salon & Aesthetics password reset code is:',
        $code,
        "This code expires in 10 minutes. If you didn't request this, you can ignore this email."
    );
    $text = $body;

    return sendBrevoEmail($email, $subject, $text, $html);
}

/**
 * Sends the confirmation code for an in-app password change (the account
 * holder is already logged in and already passed their current password --
 * this is the second factor before the new password takes effect). Distinct
 * copy from sendPasswordResetOtpEmail() so it reads as "you did this",
 * not "someone is resetting your account". Returns true only after Brevo
 * accepts the API request.
 */
function sendPasswordChangeOtpEmail(string $email, string $code): bool
{
    $body = "We received a request to change your Leo Mejillano Salon & Aesthetics account password. Your confirmation code is: {$code}\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this email and your password will stay the same.";
    $subject = 'Confirm your password change';
    $html = renderOtpEmailHtml(
        'Confirm the password change on your Leo Mejillano Salon & Aesthetics account:',
        $code,
        "This code expires in 10 minutes. If you didn't request this, you can ignore this email and your password will stay the same."
    );
    $text = $body;

    return sendBrevoEmail($email, $subject, $text, $html);
}

/**
 * Sends a "your appointment is coming up" reminder. Returns true only after
 * Brevo accepts the API request.
 */
function sendAppointmentReminderEmail(
    string $email,
    string $customerName,
    string $serviceNames,
    string $branchName,
    string $appointmentDateTime,
    string $referenceCode
): bool {
    $when = date('l, F j \a\t g:i A', strtotime($appointmentDateTime));
    $body = "Hi {$customerName},\n\n"
        . "This is a reminder of your upcoming appointment at Leo Mejillano Salon & Aesthetics:\n\n"
        . "Service: {$serviceNames}\nBranch: {$branchName}\nWhen: {$when}\nReference: {$referenceCode}\n\n"
        . "We look forward to seeing you!";
    $subject = "Appointment Reminder - {$when}";
    $text = $body;
    $html = null;

    return sendBrevoEmail($email, $subject, $text, $html);
}

/**
 * Sends the account-setup link to a Staff/Cashier account an Admin just
 * created. Returns true only after Brevo accepts the API request.
 */
function sendAccountSetupEmail(string $email, string $name, string $role, string $setupUrl): bool
{
    $body = "Hi {$name},\n\n"
        . "An Admin created a {$role} account for you at Leo Mejillano Salon & Aesthetics. "
        . "Follow this link to set your password and activate it:\n\n{$setupUrl}\n\n"
        . "This link expires in 24 hours. If you weren't expecting this, you can ignore this email.";
    $subject = 'Set up your Leo Mejillano Salon & Aesthetics account';
    $html = '<div style="font-family:Arial, Helvetica, sans-serif;font-size:15px;color:#1a1a1a;line-height:1.5;">'
        . '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>An Admin created a ' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8')
        . ' account for you at Leo Mejillano Salon &amp; Aesthetics. Follow the link below to set your password and activate it.</p>'
        . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($setupUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="display:inline-block;background:#7a1f3d;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;">Set your password</a></p>'
        . '<p>This link expires in 24 hours. If you weren\'t expecting this, you can ignore this email.</p>'
        . '</div>';
    $text = $body;

    return sendBrevoEmail($email, $subject, $text, $html);
}

/**
 * Sends the guest-booking verification OTP to $email. Returns true only
 * after Brevo accepts it. Never log booking verification codes as delivery.
 */
function sendBookingOtpEmail(string $email, string $code): bool
{
    $body = "Your Leo Mejillano Salon & Aesthetics booking verification code is: {$code}\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this email.";
    $subject = 'Your booking verification code';
    $html = renderOtpEmailHtml(
        'Your Leo Mejillano Salon & Aesthetics booking verification code is:',
        $code,
        "This code expires in 10 minutes. If you didn't request this, you can ignore this email."
    );
    $text = $body;

    return sendBrevoEmail($email, $subject, $text, $html);
}

/**
 * Sends a plain booking/status notification (no code to highlight) to
 * $email. Used by EmailOutbox for every queued customer email. Returns true
 * only after Brevo accepts the API request. Missing credentials and send failures
 * return false so queued mail is never incorrectly labelled as delivered.
 */
function sendNotificationEmail(string $email, string $subject, string $message): bool
{
    $paragraphs = array_map(
        fn($line) => '<p>' . nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</p>',
        array_filter(explode("\n\n", $message), fn($p) => trim($p) !== '')
    );
    $html = '<div style="font-family:Arial, Helvetica, sans-serif;font-size:15px;color:#1a1a1a;line-height:1.5;">'
        . implode('', $paragraphs)
        . '<p style="color:#777;font-size:12px;">Leo Mejillano Salon &amp; Beauty &middot; This is an automated message.</p>'
        . '</div>';
    $text = $message;

    return sendBrevoEmail($email, $subject, $text, $html);
}
