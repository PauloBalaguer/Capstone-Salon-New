<?php

require_once __DIR__ . '/database.php';

/**
 * Activity / audit log (audit_log table, Admin -> Reports -> Activity Log).
 *
 * Write endpoints call AuditLog::captureRequest() once, right after
 * sendCorsHeaders(). It watches the request and, when it ends, records one
 * row if the POST succeeded ({"success": true} with a 2xx status): who
 * (logged-in user, or "Guest"), what (a readable label for the endpoint),
 * which record (booking reference / id from the request), the endpoint's own
 * response message, and the request fields with secrets removed. Sign-in
 * attempts are also logged when they fail, so repeated bad passwords show up.
 * Logging never breaks the request it observes.
 *
 * record() is for events that don't come from a web request (e.g. cron).
 */
final class AuditLog
{
    private const LABELS = [
        'auth/login' => 'Signed in',
        'auth/logout' => 'Signed out',
        'auth/register' => 'Registered an account',
        'auth/verifyOtp' => 'Verified account email',
        'auth/resetPassword' => 'Reset password',
        'auth/completeAccountSetup' => 'Completed account setup',
        'admin/updateBookingStatus' => 'Updated booking status',
        'admin/refundDeposit' => 'Refunded a deposit',
        'admin/assignStaff' => 'Assigned staff to a booking',
        'admin/completeCheckout' => 'Completed a checkout',
        'admin/saveService' => 'Saved a service',
        'admin/deleteService' => 'Deleted a service',
        'admin/toggleService' => 'Turned a service on/off',
        'admin/saveStaff' => 'Saved an employee',
        'admin/removeStaff' => 'Removed an employee',
        'admin/saveCashier' => 'Saved a cashier account',
        'admin/removeCashier' => 'Removed a cashier account',
        'admin/savePromotion' => 'Saved a promotion',
        'admin/deletePromotion' => 'Deleted a promotion',
        'admin/togglePromotion' => 'Turned a promotion on/off',
        'admin/saveWeddingPackage' => 'Saved a wedding package',
        'admin/deleteWeddingPackage' => 'Deleted a wedding package',
        'admin/toggleWeddingPackage' => 'Turned a wedding package on/off',
        'admin/saveInventoryItem' => 'Saved an inventory item',
        'admin/toggleInventoryItemActive' => 'Turned an inventory item on/off',
        'admin/adjustStock' => 'Adjusted stock',
        'admin/saveBranch' => 'Saved branch settings',
        'admin/saveAdminProfile' => 'Updated admin profile/settings',
        'admin/setFeedbackStatus' => 'Moderated a review',
        'admin/setHomeServiceQuote' => 'Quoted a home service request',
        'admin/reopenBusinessDay' => 'Reopened a business day',
        'admin/reconcilePaymongo' => 'Synced a PayMongo payment',
        'cashier/updateStatus' => 'Updated booking status',
        'cashier/assignStaff' => 'Assigned staff to a booking',
        'cashier/payment' => 'Processed a payment',
        'cashier/createWalkIn' => 'Created a walk-in booking',
        'cashier/adjustStock' => 'Adjusted stock',
        'cashier/saveProduct' => 'Saved a product',
        'cashier/closeEod' => 'Closed the business day',
        'cashier/updateStaffStatus' => 'Changed staff shift status',
        'customer/submitBooking' => 'Booked an appointment',
        'customer/cancelAppointment' => 'Cancelled an appointment',
        'customer/submitHomeServiceRequest' => 'Requested a home service',
        'customer/submitReview' => 'Posted a review',
        'customer/saveProfile' => 'Updated profile',
        'customer/confirmPasswordChange' => 'Changed password',
        'employee/saveProfile' => 'Updated profile',
        'employee/confirmPasswordChange' => 'Changed password',
        'public/submitGuestBooking' => 'Booked an appointment (guest)',
        'public/submitGuestHomeService' => 'Requested a home service (guest)',
        'public/submitGuestFeedback' => 'Posted a review (guest)',
        'public/joinWaitlist' => 'Joined a stylist waitlist',
    ];

    /** Request fields never stored, matched by name. */
    private const SECRET_FIELD = '/pass|otp|token|secret|cvc|card/i';

    /** Request fields tried, in order, as the "which record" column. */
    private const ENTITY_FIELDS = ['reference', 'id', 'appointment_id', 'appointmentId', 'staffId', 'serviceId', 'productId', 'itemId', 'email'];

    private static bool $capturing = false;

    public static function captureRequest(): void
    {
        if (self::$capturing || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        // Dry runs (e.g. cancelAppointment's deposit preview) change nothing.
        if (filter_var($_POST['preview'] ?? false, FILTER_VALIDATE_BOOLEAN)) return;
        self::$capturing = true;

        // Snapshot the actor now: logout.php destroys the session before the request ends.
        $actorAtStart = self::currentActor();
        $action = self::actionFromScript();
        ob_start();
        register_shutdown_function(function () use ($action, $actorAtStart) {
            try {
                $body = json_decode((string) ob_get_contents(), true);
                $status = http_response_code() ?: 200;
                $ok = is_array($body) && ($body['success'] ?? false) === true && $status < 400;
                // Failed sign-ins are security-relevant; other failures are just validation noise.
                if (!$ok && $action !== 'auth/login') return;

                $actor = self::currentActor() ?? $actorAtStart;
                if (!$ok && $action === 'auth/login') {
                    $actor = ['id' => null, 'role' => null, 'name' => trim((string) ($_POST['email'] ?? ''))];
                }
                self::write(
                    Database::getInstance(),
                    $action,
                    $ok ? (self::LABELS[$action] ?? self::humanize($action)) : 'Failed sign-in',
                    $actor,
                    self::entityRef($body),
                    $ok ? 'success' : 'failed',
                    is_array($body) ? ($body['message'] ?? null) : null,
                    self::sanitizedInput()
                );
            } catch (Throwable $e) {
                error_log('AuditLog capture error: ' . $e->getMessage());
            }
        });
    }

    /** Direct entry for events outside a web request. */
    public static function record(PDO $pdo, string $action, string $label, ?string $entityRef = null, ?string $message = null, array $details = []): void
    {
        try {
            self::write($pdo, $action, $label, self::currentActor() ?? ['id' => null, 'role' => 'System', 'name' => 'System'],
                $entityRef, 'success', $message, $details);
        } catch (Throwable $e) {
            error_log('AuditLog record error: ' . $e->getMessage());
        }
    }

    private static function write(PDO $pdo, string $action, string $label, ?array $actor, ?string $entityRef,
                                  string $outcome, ?string $message, array $details): void
    {
        $actor ??= ['id' => null, 'role' => 'Guest', 'name' => trim((string) ($_POST['fullname'] ?? $_POST['name'] ?? 'Guest')) ?: 'Guest'];
        $pdo->prepare('
            INSERT INTO audit_log (user_id, user_role, actor, action, label, entity_ref, outcome, message, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $actor['id'], $actor['role'], mb_substr((string) $actor['name'], 0, 255),
            $action, $label, $entityRef !== null ? mb_substr($entityRef, 0, 100) : null, $outcome,
            $message !== null ? mb_substr((string) $message, 0, 255) : null,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    private static function currentActor(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) return null;
        return [
            'id' => (int) $_SESSION['user_id'],
            'role' => $_SESSION['user_role'] ?? null,
            'name' => ($_SESSION['user_name'] ?? '') ?: ($_SESSION['user_email'] ?? ''),
        ];
    }

    /** backend/admin/saveService.php -> "admin/saveService" */
    private static function actionFromScript(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        return preg_match('#/backend/([^/]+)/([^/]+)\.php$#', $script, $m) ? $m[1] . '/' . $m[2] : basename($script, '.php');
    }

    private static function humanize(string $action): string
    {
        $name = substr($action, strpos($action, '/') + 1);
        return ucfirst(strtolower(trim(preg_replace('/([A-Z])/', ' $1', $name))));
    }

    private static function entityRef(?array $body): ?string
    {
        // Prefer a reference the endpoint itself returned (e.g. a new booking's code).
        foreach (['reference', 'referenceCode'] as $key) {
            if (is_array($body) && !empty($body[$key]) && is_scalar($body[$key])) return (string) $body[$key];
        }
        foreach (self::ENTITY_FIELDS as $field) {
            if (isset($_POST[$field]) && is_scalar($_POST[$field]) && trim((string) $_POST[$field]) !== '') {
                return trim((string) $_POST[$field]);
            }
        }
        return null;
    }

    private static function sanitizedInput(): array
    {
        $clean = [];
        foreach ($_POST as $key => $value) {
            if (preg_match(self::SECRET_FIELD, (string) $key)) continue;
            if (is_array($value)) {
                $value = implode(', ', array_map(fn($v) => is_scalar($v) ? (string) $v : '[…]', $value));
            }
            $value = (string) $value;
            $clean[$key] = mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
        }
        return $clean;
    }
}
