<?php

/**
 * The texted-code step of signing up with a number that has guest bookings
 * (see GuestAccountClaim). POST email, claimToken and action:
 *   verify           + code: links the guest record and activates the account
 *   resend           texts a new code
 *   different_number + phone: finishes signup with another, unused number
 * The token comes from verifyOtp.php or login.php, once the email is proven.
 */

require_once '../../backend/shared/config/setup/cors.php';
require_once '../../backend/shared/config/helpers/PhoneNumber.php';
require_once '../../backend/shared/config/setup/database.php';
require_once '../../backend/shared/config/security/AuditLog.php';
require_once '../../backend/shared/config/security/RateLimiter.php';
require_once '../../backend/shared/config/security/GuestAccountClaim.php';

sendCorsHeaders();
header('Content-Type: application/json');

function claimReply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    claimReply(405, ['success' => false, 'message' => 'Invalid request method.']);
}

$limiter = new RateLimiter(RateLimiter::getIpAddress(), 'guest_phone_fail', 10, 900);
if ($limiter->isExceeded()) {
    claimReply(429, ['success' => false, 'message' => 'Too many attempts. Please try again in 15 minutes.']);
}

$email = trim((string) ($_POST['email'] ?? ''));
$token = (string) ($_POST['claimToken'] ?? '');
$action = (string) ($_POST['action'] ?? '');

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT c.* FROM users u JOIN guest_account_claims c ON c.user_id = u.id
        WHERE u.email = ? AND u.is_active = 0 AND c.email_verified = 1 AND c.token_hash IS NOT NULL');
    $stmt->execute([$email]);
    $claim = $stmt->fetch();

    if (!$claim || !hash_equals($claim['token_hash'], hash('sha256', $token))) {
        $limiter->record();
        claimReply(403, ['success' => false, 'sessionExpired' => true, 'message' => 'This step has expired. Please sign in to continue.']);
    }

    switch ($action) {
        case 'verify':
            $problem = GuestAccountClaim::codeProblem($pdo, $claim, trim((string) ($_POST['code'] ?? '')));
            if ($problem !== null) {
                $limiter->record();
                claimReply(400, ['success' => false, 'message' => $problem]);
            }
            if (!GuestAccountClaim::linkGuestRecord($pdo, $claim)) {
                claimReply(409, ['success' => false, 'numberTaken' => true, 'message' => 'This number now has its own account. Please use a different number.']);
            }
            $limiter->clear();
            AuditLog::record($pdo, 'auth/guest_linked', 'Linked guest bookings to a new account', 'user#' . $claim['user_id'], 'Mobile number proven with a texted code.');
            claimReply(200, ['success' => true, 'message' => 'Your number is confirmed and your bookings are linked. Please sign in.']);

        case 'resend':
            if (!GuestAccountClaim::sendCode($pdo, $claim)) {
                claimReply(503, ['success' => false, 'message' => 'We could not text a code right now. Please try again later, or use a different number.']);
            }
            claimReply(200, ['success' => true, 'message' => 'A code is on its way.']);

        case 'different_number':
            $phone = PhoneNumber::normalize((string) ($_POST['phone'] ?? '')) ?? '';
            if ($phone === '') {
                claimReply(400, ['success' => false, 'message' => 'Enter the 10 digits after +63, starting with 9.']);
            }
            $stmt = $pdo->prepare('SELECT 1 FROM customers WHERE phone_number = ?');
            $stmt->execute([$phone]);
            if ($stmt->fetchColumn()) {
                claimReply(409, ['success' => false, 'message' => 'That number is already in use. Please try another one.']);
            }
            GuestAccountClaim::useOtherNumber($pdo, $claim, $phone);
            claimReply(200, ['success' => true, 'message' => 'Your account is ready. Please sign in.']);
    }

    claimReply(400, ['success' => false, 'message' => 'Unknown action.']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Guest phone step error: ' . $e->getMessage());
    claimReply(500, ['success' => false, 'message' => 'A server error occurred. Please try again.']);
}
