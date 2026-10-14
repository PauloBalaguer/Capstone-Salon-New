<?php

/**
 * Sends real email via Gmail SMTP using PHPMailer (vendored directly into
 * backend/lib/PHPMailer since this project has no Composer setup).
 * Credentials live in the gitignored backend/config/mail_credentials.php —
 * see mail_credentials.example.php for the template. If that file hasn't
 * been created yet (e.g. a fresh clone before setup), or the send fails for
 * any reason, this falls back to the old log-file simulation so registration
 * still works during development/demos.
 */

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function logSimulatedEmail(string $subject, string $to, string $body): void
{
    $logDirectory = __DIR__ . '/../../logs';
    if (!is_dir($logDirectory)) {
        mkdir($logDirectory, 0755, true);
    }
    file_put_contents($logDirectory . '/email.log', "[{$subject}] To {$to}: {$body}\n", FILE_APPEND);
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
 * Sends the account-verification OTP to $email. Returns true if a real
 * email was sent, false if it fell back to the log-file simulation.
 */
function sendOtpEmail(string $email, string $otp): bool
{
    $body = "Your Leo Mejillano Salon & Aesthetics verification code is: {$otp}\n\nThis code expires in 10 minutes.";
    $credentialsPath = __DIR__ . '/mail_credentials.php';

    if (!file_exists($credentialsPath)) {
        logSimulatedEmail('Account Verification OTP', $email, "{$otp} (expires in 10 minutes)");
        return false;
    }

    $credentials = require $credentialsPath;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $credentials['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['smtp_username'];
        $mail->Password = $credentials['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $credentials['smtp_port'];

        $mail->setFrom($credentials['from_email'], $credentials['from_name']);
        $mail->addAddress($email);

        $mail->Subject = 'Your verification code';
        $mail->isHTML(true);
        $mail->Body = renderOtpEmailHtml(
            'Your Leo Mejillano Salon & Aesthetics verification code is:',
            $otp,
            'This code expires in 10 minutes.'
        );
        $mail->AltBody = $body;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('OTP email send failed: ' . $mail->ErrorInfo);
        logSimulatedEmail('Account Verification OTP (send failed, see error_log)', $email, "{$otp} (expires in 10 minutes)");
        return false;
    }
}

/**
 * Sends the password-reset OTP to $email. Returns true if a real email was
 * sent, false if it fell back to the log-file simulation.
 */
function sendPasswordResetOtpEmail(string $email, string $code): bool
{
    $body = "Your Leo Mejillano Salon & Aesthetics password reset code is: {$code}\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this email.";
    $credentialsPath = __DIR__ . '/mail_credentials.php';

    if (!file_exists($credentialsPath)) {
        logSimulatedEmail('Password Reset OTP', $email, "{$code} (expires in 10 minutes)");
        return false;
    }

    $credentials = require $credentialsPath;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $credentials['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['smtp_username'];
        $mail->Password = $credentials['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $credentials['smtp_port'];

        $mail->setFrom($credentials['from_email'], $credentials['from_name']);
        $mail->addAddress($email);

        $mail->Subject = 'Your password reset code';
        $mail->isHTML(true);
        $mail->Body = renderOtpEmailHtml(
            'Your Leo Mejillano Salon & Aesthetics password reset code is:',
            $code,
            "This code expires in 10 minutes. If you didn't request this, you can ignore this email."
        );
        $mail->AltBody = $body;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Password reset OTP email send failed: ' . $mail->ErrorInfo);
        logSimulatedEmail('Password Reset OTP (send failed, see error_log)', $email, "{$code} (expires in 10 minutes)");
        return false;
    }
}

/**
 * Sends the confirmation code for an in-app password change (the account
 * holder is already logged in and already passed their current password --
 * this is the second factor before the new password takes effect). Distinct
 * copy from sendPasswordResetOtpEmail() so it reads as "you did this",
 * not "someone is resetting your account". Returns true if a real email was
 * sent, false if it fell back to the log-file simulation.
 */
function sendPasswordChangeOtpEmail(string $email, string $code): bool
{
    $body = "We received a request to change your Leo Mejillano Salon & Aesthetics account password. Your confirmation code is: {$code}\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this email and your password will stay the same.";
    $credentialsPath = __DIR__ . '/mail_credentials.php';

    if (!file_exists($credentialsPath)) {
        logSimulatedEmail('Password Change Confirmation', $email, "{$code} (expires in 10 minutes)");
        return false;
    }

    $credentials = require $credentialsPath;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $credentials['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['smtp_username'];
        $mail->Password = $credentials['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $credentials['smtp_port'];

        $mail->setFrom($credentials['from_email'], $credentials['from_name']);
        $mail->addAddress($email);

        $mail->Subject = 'Confirm your password change';
        $mail->isHTML(true);
        $mail->Body = renderOtpEmailHtml(
            'Confirm the password change on your Leo Mejillano Salon & Aesthetics account:',
            $code,
            "This code expires in 10 minutes. If you didn't request this, you can ignore this email and your password will stay the same."
        );
        $mail->AltBody = $body;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Password change OTP email send failed: ' . $mail->ErrorInfo);
        logSimulatedEmail('Password Change Confirmation (send failed, see error_log)', $email, "{$code} (expires in 10 minutes)");
        return false;
    }
}

/**
 * Sends a "your appointment is coming up" reminder. Returns true if a real
 * email was sent, false if it fell back to the log-file simulation.
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
    $credentialsPath = __DIR__ . '/mail_credentials.php';

    if (!file_exists($credentialsPath)) {
        logSimulatedEmail('Appointment Reminder', $email, $body);
        return false;
    }

    $credentials = require $credentialsPath;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $credentials['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['smtp_username'];
        $mail->Password = $credentials['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $credentials['smtp_port'];

        $mail->setFrom($credentials['from_email'], $credentials['from_name']);
        $mail->addAddress($email);

        $mail->Subject = "Appointment Reminder - {$when}";
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Appointment reminder email send failed: ' . $mail->ErrorInfo);
        logSimulatedEmail('Appointment Reminder (send failed, see error_log)', $email, $body);
        return false;
    }
}

/**
 * Sends the account-setup link to a Staff/Cashier account an Admin just
 * created. Returns true if a real email was sent, false if it fell back to
 * the log-file simulation.
 */
function sendAccountSetupEmail(string $email, string $name, string $role, string $setupUrl): bool
{
    $body = "Hi {$name},\n\n"
        . "An Admin created a {$role} account for you at Leo Mejillano Salon & Aesthetics. "
        . "Follow this link to set your password and activate it:\n\n{$setupUrl}\n\n"
        . "This link expires in 24 hours. If you weren't expecting this, you can ignore this email.";
    $credentialsPath = __DIR__ . '/mail_credentials.php';

    if (!file_exists($credentialsPath)) {
        logSimulatedEmail('Account Setup', $email, "{$setupUrl} (expires in 24 hours)");
        return false;
    }

    $credentials = require $credentialsPath;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $credentials['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['smtp_username'];
        $mail->Password = $credentials['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $credentials['smtp_port'];

        $mail->setFrom($credentials['from_email'], $credentials['from_name']);
        $mail->addAddress($email);

        $mail->Subject = 'Set up your Leo Mejillano Salon & Aesthetics account';
        $mail->isHTML(true);
        $mail->Body = '<div style="font-family:Arial, Helvetica, sans-serif;font-size:15px;color:#1a1a1a;line-height:1.5;">'
            . '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>An Admin created a ' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8')
            . ' account for you at Leo Mejillano Salon &amp; Aesthetics. Follow the link below to set your password and activate it.</p>'
            . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($setupUrl, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#7a1f3d;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;">Set your password</a></p>'
            . '<p>This link expires in 24 hours. If you weren\'t expecting this, you can ignore this email.</p>'
            . '</div>';
        $mail->AltBody = $body;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Account setup email send failed: ' . $mail->ErrorInfo);
        logSimulatedEmail('Account Setup (send failed, see error_log)', $email, "{$setupUrl} (expires in 24 hours)");
        return false;
    }
}

/**
 * Sends the guest-booking verification OTP to $email. Returns true if a
 * real email was sent, false if it fell back to the log-file simulation.
 */
function sendBookingOtpEmail(string $email, string $code): bool
{
    $body = "Your Leo Mejillano Salon & Aesthetics booking verification code is: {$code}\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore this email.";
    $credentialsPath = __DIR__ . '/mail_credentials.php';

    if (!file_exists($credentialsPath)) {
        logSimulatedEmail('Booking Verification OTP', $email, "{$code} (expires in 10 minutes)");
        return false;
    }

    $credentials = require $credentialsPath;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $credentials['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['smtp_username'];
        $mail->Password = $credentials['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $credentials['smtp_port'];

        $mail->setFrom($credentials['from_email'], $credentials['from_name']);
        $mail->addAddress($email);

        $mail->Subject = 'Your booking verification code';
        $mail->isHTML(true);
        $mail->Body = renderOtpEmailHtml(
            'Your Leo Mejillano Salon & Aesthetics booking verification code is:',
            $code,
            "This code expires in 10 minutes. If you didn't request this, you can ignore this email."
        );
        $mail->AltBody = $body;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Booking OTP email send failed: ' . $mail->ErrorInfo);
        logSimulatedEmail('Booking Verification OTP (send failed, see error_log)', $email, "{$code} (expires in 10 minutes)");
        return false;
    }
}

/**
 * Sends a plain booking/status notification (no code to highlight) to
 * $email. Used by EmailOutbox for every queued customer email. Returns true
 * once the message is handled -- either really sent, or written to
 * logs/email.log when mail_credentials.php hasn't been set up -- and false
 * only when a real send was attempted and failed (so the outbox retries it).
 */
function sendNotificationEmail(string $email, string $subject, string $message): bool
{
    $credentialsPath = __DIR__ . '/mail_credentials.php';

    if (!file_exists($credentialsPath)) {
        logSimulatedEmail($subject, $email, str_replace("\n", ' | ', $message));
        return true;
    }

    $credentials = require $credentialsPath;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $credentials['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $credentials['smtp_username'];
        $mail->Password = $credentials['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $credentials['smtp_port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($credentials['from_email'], $credentials['from_name']);
        $mail->addAddress($email);

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $paragraphs = array_map(
            fn($line) => '<p>' . nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</p>',
            array_filter(explode("\n\n", $message), fn($p) => trim($p) !== '')
        );
        $mail->Body = '<div style="font-family:Arial, Helvetica, sans-serif;font-size:15px;color:#1a1a1a;line-height:1.5;">'
            . implode('', $paragraphs)
            . '<p style="color:#777;font-size:12px;">Leo Mejillano Salon &amp; Beauty &middot; This is an automated message.</p>'
            . '</div>';
        $mail->AltBody = $message;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Notification email send failed: ' . $mail->ErrorInfo);
        return false;
    }
}
