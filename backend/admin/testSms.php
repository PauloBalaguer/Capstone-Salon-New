<?php

/**
 * Admin: SMS test page (temporary -- delete after testing textbee).
 *
 * Open http://localhost/Capstone-Salon/backend/admin/testSms.php while logged
 * in as Admin, enter a mobile number and send one test text. It goes through
 * SmsOutbox::sendNow(), so it is logged in sms_outbox and counts toward the
 * daily cap. Failures are explained in the PHP error log
 * (C:\xampp\php\logs\php_error_log or C:\xampp\apache\logs\error.log).
 */

require_once '../../backend/shared/config/setup/session.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/notifications/SmsOutbox.php';

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(401);
    exit('Please log in as an administrator first.');
}

$phone = is_string($_POST['phone'] ?? null) ? trim($_POST['phone']) : '';
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (normalizePhPhone($phone) === null) {
        $result = ['error', 'That is not a valid Philippine mobile number (e.g. 0917 123 4567).'];
    } elseif (SmsOutbox::sendNow(Database::getInstance(), $phone, 'TEST', renderSms('TEST'))) {
        $result = ['ok', 'textbee accepted the message for ' . normalizePhPhone($phone) . '. It should arrive within a minute.'];
    } else {
        $result = ['error', 'Not sent. Check sms_credentials.php, that the textbee app is running on the phone, and the PHP error log.'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SMS Test</title>
  <style>
    body { font-family: Arial, Helvetica, sans-serif; max-width: 420px; margin: 40px auto; padding: 0 16px; color: #1a1a1a; }
    input, button { font-size: 16px; padding: 10px; width: 100%; box-sizing: border-box; margin-top: 8px; }
    button { background: #7a1f3d; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
    .ok { color: #166534; } .error { color: #b91c1c; }
  </style>
</head>
<body>
  <h1>Send a test SMS</h1>
  <p>Message: <em><?= htmlspecialchars(renderSms('TEST'), ENT_QUOTES, 'UTF-8') ?></em></p>
  <form method="post">
    <label for="phone">Mobile number</label>
    <input id="phone" name="phone" type="tel" placeholder="0917 123 4567" value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>" required>
    <button type="submit">Send test SMS</button>
  </form>
  <?php if ($result): ?>
    <p class="<?= $result[0] ?>"><?= htmlspecialchars($result[1], ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>
</body>
</html>
