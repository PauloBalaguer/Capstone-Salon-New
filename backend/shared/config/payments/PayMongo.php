<?php

require_once __DIR__ . '/../notifications/CustomerNotifier.php';
require_once __DIR__ . '/PaymentLog.php';
require_once __DIR__ . '/../booking/Waitlist.php';
require_once __DIR__ . '/../setup/url.php';
require_once __DIR__ . '/../booking/Scheduling.php';
require_once __DIR__ . '/../setup/env.php';

/**
 * PayMongo online payments: GCash, Maya and Credit/Debit Card as separate methods.
 *
 * Keys come from PAYMONGO_SECRET_KEY / PAYMONGO_WEBHOOK_SECRET in the private
 * .env (see setup/env.php and .env.example). Every call uses the secret key.
 *
 *   'PayMongo-GCash' / 'PayMongo-Maya' -- Payment Intent + Payment Method:
 *       the customer goes straight to the GCash / Maya authorization page.
 *   'PayMongo-Card' -- Checkout Session limited to cards, so card details are
 *       typed on PayMongo's hosted page and never touch this server.
 *   'PayMongo' -- the old combined checkout; historical bookings and home
 *       service balance links only.
 *
 * A booking is inserted unpaid and startPayment() creates the attempt. sync()
 * asks PayMongo whether it was paid and records the deposit -- called by the
 * webhook (backend/public/paymongoWebhook.php), the result page
 * (backend/public/paymongoStatus.php), reconcile and releaseExpiredHolds(), so
 * payments still register when PayMongo can't reach the webhook on localhost.
 * Never trust the redirect: only an API response marks a booking paid.
 */
final class PayMongo
{
    public const LEGACY_METHOD = 'PayMongo';
    public const METHOD_GCASH = 'PayMongo-GCash';
    public const METHOD_MAYA = 'PayMongo-Maya';
    public const METHOD_CARD = 'PayMongo-Card';
    public const METHODS = [self::METHOD_GCASH, self::METHOD_MAYA, self::METHOD_CARD]; // Offered for new payments.
    public const MIN_AMOUNT = 20.0; // PayMongo's minimum charge, in pesos.
    public const HOLD_MINUTES = 30; // Unpaid PayMongo bookings release their slot after this.

    private const API = 'https://api.paymongo.com/v1';
    private const PAYMENT_TYPES = [self::METHOD_GCASH => 'gcash', self::METHOD_MAYA => 'paymaya', self::METHOD_CARD => 'card'];
    private const LABELS = [self::METHOD_GCASH => 'GCash (PayMongo)', self::METHOD_MAYA => 'Maya (PayMongo)', self::METHOD_CARD => 'Card (PayMongo)'];

    private static ?array $credentials = null; // tests/paymongo_methods_test.php injects mock keys here.

    private static function credentials(): array
    {
        return self::$credentials ??= [
            'secret_key' => env('PAYMONGO_SECRET_KEY'),
            'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        ];
    }

    public static function isConfigured(): bool
    {
        return (self::credentials()['secret_key'] ?? '') !== '';
    }

    /** Paid through PayMongo (as opposed to manual Cash / GCash / Maya proof). Includes the legacy combined method. */
    public static function isOnline(?string $method): bool
    {
        return $method === self::LEGACY_METHOD || in_array($method, self::METHODS, true);
    }

    /** GCash / Maya: Payment Intent with a direct redirect. */
    public static function isEwallet(?string $method): bool
    {
        return $method === self::METHOD_GCASH || $method === self::METHOD_MAYA;
    }

    public static function label(?string $method): string
    {
        return self::LABELS[$method] ?? (string) $method;
    }

    /** Why $amount can't be paid online, or null if it can. */
    public static function amountError(float $amount): ?string
    {
        return $amount < self::MIN_AMOUNT
            ? 'Online payment needs at least ₱' . number_format(self::MIN_AMOUNT, 2) . '. Please choose another payment method.'
            : null;
    }

    private static function request(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init((self::credentials()['api_base'] ?? self::API) . $path); // api_base: tests/paymongo_methods_test.php's mock only
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode(self::credentials()['secret_key'] . ':'),
            ],
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('PayMongo request failed: ' . $error);
        }
        $decoded = json_decode($body, true) ?? [];
        if ($status >= 300) {
            throw new RuntimeException('PayMongo error ' . $status . ': ' . ($decoded['errors'][0]['detail'] ?? $body));
        }
        return $decoded;
    }

    /** Creates a hosted checkout page. Returns ['id' => cs_..., 'url' => https://checkout.paymongo.com/...]. */
    public static function createCheckout(string $reference, float $amount, string $description, string $successUrl, string $cancelUrl,
        array $types = ['gcash', 'paymaya', 'card'], array $billing = []): array
    {
        $res = self::request('POST', '/checkout_sessions', ['data' => ['attributes' => [
            'line_items' => [[
                'name' => $description,
                'quantity' => 1,
                'currency' => 'PHP',
                'amount' => (int) round($amount * 100), // centavos
            ]],
            'payment_method_types' => $types,
            'description' => $description,
            'reference_number' => $reference,
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
            'metadata' => ['reference' => $reference],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ] + ($billing ? ['billing' => $billing] : [])]]);
        return ['id' => $res['data']['id'], 'url' => $res['data']['attributes']['checkout_url']];
    }

    /** Result-page URL PayMongo sends the customer back to (outcome: success / cancel / return), or a pay / choose link. */
    public static function resultUrl(string $baseUrl, string $reference, string $token, string $outcome, string $from, ?string $method = null): string
    {
        return $baseUrl . '/frontend/public/payment-result.html?' . http_build_query([
            'ref' => $reference, 'token' => $token, 'outcome' => $outcome, 'from' => $from,
        ] + ($method ? ['method' => $method] : []));
    }

    /**
     * Starts a payment for an unpaid appointment (or, with $homeService, a
     * home service reservation fee) with one of METHODS: a new Payment
     * Intent for GCash / Maya, a card-only Checkout Session for Card. Every
     * try creates a new attempt -- a failed one is never reused. Billing is
     * filled from the customer record, so the customer types nothing. Keeps
     * the row's online_payment_token so earlier result-page links stay valid.
     * Returns ['url' => page to send the customer to, 'resultUrl' => this
     * booking's result page]. Throws RuntimeException on API failure.
     */
    public static function startPayment(PDO $pdo, string $method, int $id, string $reference, float $amount, string $from, bool $homeService = false): array
    {
        if (!in_array($method, self::METHODS, true)) throw new RuntimeException('Unknown online payment method.');
        if ($error = self::amountError($amount)) throw new RuntimeException($error);
        $table = $homeService ? 'home_service_requests' : 'appointments';
        $stmt = $pdo->prepare("
            SELECT t.online_payment_token, t.paymongo_checkout_id, CONCAT_WS(' ', c.first_name, c.last_name) AS name, c.phone_number AS phone,
                   " . ($homeService ? 'COALESCE(t.contact_email, c.email, u.email)' : 'COALESCE(c.email, u.email)') . " AS email
            FROM {$table} t JOIN customers c ON c.id = t.customer_id LEFT JOIN users u ON u.id = c.user_id
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: [];
        $billing = array_filter(['name' => trim($row['name'] ?? ''), 'email' => $row['email'] ?? null, 'phone' => $row['phone'] ?? null],
            fn($v) => $v !== null && $v !== '');
        $token = ($row['online_payment_token'] ?? '') ?: bin2hex(random_bytes(16));
        $baseUrl = getProjectFullBaseUrl();
        $returnUrl = self::resultUrl($baseUrl, $reference, $token, 'return', $from);
        $description = ($homeService ? 'Home service reservation fee ' : 'Reservation payment ') . $reference;

        if (!empty($row['paymongo_checkout_id'])) {
            // Switching away from an open card page: close it so it can't be paid as well.
            try { self::request('POST', '/checkout_sessions/' . rawurlencode($row['paymongo_checkout_id']) . '/expire'); } catch (RuntimeException $e) {}
        }

        if (self::isEwallet($method)) {
            $type = self::PAYMENT_TYPES[$method];
            $intent = self::request('POST', '/payment_intents', ['data' => ['attributes' => [
                'amount' => (int) round($amount * 100), // centavos
                'currency' => 'PHP',
                'payment_method_allowed' => [$type],
                'description' => $description,
                'metadata' => ['reference' => $reference, ($homeService ? 'home_service_id' : 'appointment_id') => (string) $id],
            ]]])['data'];
            // PayMongo requires an email whenever billing is sent, so send all three or none.
            $paymentMethod = self::request('POST', '/payment_methods', ['data' => ['attributes' => ['type' => $type]
                + (count($billing) === 3 ? ['billing' => $billing] : [])]])['data'];
            $attached = self::request('POST', '/payment_intents/' . rawurlencode($intent['id']) . '/attach', ['data' => ['attributes' => [
                'payment_method' => $paymentMethod['id'],
                'return_url' => $returnUrl,
            ]]])['data']['attributes'] ?? [];
            $url = $attached['next_action']['redirect']['url'] ?? null;
            if (!$url) throw new RuntimeException('PayMongo did not return the ' . self::label($method) . ' authorization page.');
            $pdo->prepare("UPDATE {$table} SET paymongo_payment_intent_id = ?, paymongo_checkout_id = NULL, paymongo_checkout_url = NULL, online_payment_token = ? WHERE id = ?")
                ->execute([$intent['id'], $token, $id]);
            [$event, $attemptId] = ['intent_created', $intent['id']];
        } else {
            $checkout = self::createCheckout($reference, $amount, $description,
                self::resultUrl($baseUrl, $reference, $token, 'success', $from),
                self::resultUrl($baseUrl, $reference, $token, 'cancel', $from), ['card'], $billing);
            $url = $checkout['url'];
            $pdo->prepare("UPDATE {$table} SET paymongo_checkout_id = ?, paymongo_checkout_url = ?, paymongo_payment_intent_id = NULL, online_payment_token = ? WHERE id = ?")
                ->execute([$checkout['id'], $url, $token, $id]);
            [$event, $attemptId] = ['checkout_created', $checkout['id']];
        }
        if (!$homeService) {
            // "Choose another method" on the result page switches the booking's method too.
            $pdo->prepare('UPDATE appointments SET preferred_payment_method = ? WHERE id = ?')->execute([$method, $id]);
        }
        PaymentLog::record($pdo, $event, $homeService ? null : $id, $reference, $attemptId, $amount, 'unpaid',
            ['method' => $method] + ($homeService ? ['type' => 'home_service'] : []));
        return ['url' => $url, 'resultUrl' => self::resultUrl($baseUrl, $reference, $token, 'choose', $from)];
    }

    /**
     * Pay links for a home service reservation fee: 'choose' (the result page
     * offers all three methods) plus one per method. The payment is created
     * only when a link is opened (payment-result.js -> paymongoPay.php), so a
     * link sitting in an email never expires. Null when it can't be paid online.
     */
    public static function payUrls(?string $token, string $reference, float $amount, string $from): ?array
    {
        if (!$token || !self::isConfigured() || self::amountError($amount)) return null;
        $baseUrl = getProjectFullBaseUrl();
        $urls = ['choose' => self::resultUrl($baseUrl, $reference, $token, 'choose', $from)];
        foreach (self::METHODS as $method) $urls[$method] = self::resultUrl($baseUrl, $reference, $token, 'pay', $from, $method);
        return $urls;
    }

    /**
     * Asks PayMongo about the booking's current attempt (Payment Intent for
     * GCash / Maya, Checkout Session for Card / legacy) and records the
     * deposit once it's paid. Returns ['state' => 'paid' | 'pending' |
     * 'failed', 'reason' => PayMongo's failure message or null]:
     *   pending -- not finished yet (awaiting authorization / processing,
     *              or a card page still open)
     *   failed  -- cancelled or declined (intent back to
     *              awaiting_payment_method) or the card page expired
     * Idempotent: webhook, result page and cron may all call it; recording
     * is guarded by the row lock and deposit_paid = 0.
     */
    public static function sync(PDO $pdo, array $row, bool $homeService = false): array
    {
        $table = $homeService ? 'home_service_requests' : 'appointments';
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$row['id']]);
        $row = $stmt->fetch();
        if (!$row) return ['state' => 'failed', 'reason' => null];
        if ((int) $row['deposit_paid'] === 1) return ['state' => 'paid', 'reason' => null];
        if (!self::isConfigured()) return ['state' => 'pending', 'reason' => null];
        $expected = (float) ($homeService ? $row['deposit_amount'] : ($row['reservation_amount_due'] ?? $row['deposit_amount']));

        // Only a server-to-server API response can supply payment evidence.
        if ($row['paymongo_payment_intent_id']) {
            $intent = self::request('GET', '/payment_intents/' . rawurlencode($row['paymongo_payment_intent_id']))['data'] ?? [];
            $attributes = $intent['attributes'] ?? [];
            if (($intent['id'] ?? '') !== $row['paymongo_payment_intent_id'] || ($attributes['currency'] ?? '') !== 'PHP'
                || (int) ($attributes['amount'] ?? -1) !== (int) round($expected * 100)) {
                throw new RuntimeException('PayMongo payment intent does not match this booking.');
            }
            $paid = ($attributes['status'] ?? '') === 'succeeded' ? self::firstPaid($attributes['payments'] ?? []) : null;
            $failed = ($attributes['status'] ?? '') === 'awaiting_payment_method';
            $error = $attributes['last_payment_error'] ?? self::lastFailure($attributes['payments'] ?? []);
            $details = ['payment_intent_id' => $intent['id']];
        } elseif ($row['paymongo_checkout_id']) {
            $session = self::request('GET', '/checkout_sessions/' . rawurlencode($row['paymongo_checkout_id']))['data'] ?? [];
            if (($session['id'] ?? '') !== $row['paymongo_checkout_id']) throw new RuntimeException('PayMongo checkout does not match this booking.');
            $paid = self::firstPaid($session['attributes']['payments'] ?? []);
            $failed = ($session['attributes']['status'] ?? '') === 'expired';
            $error = $session['attributes']['payment_intent']['attributes']['last_payment_error'] ?? self::lastFailure($session['attributes']['payments'] ?? []);
            $details = ['checkout_id' => $session['id']];
        } else {
            return ['state' => 'failed', 'reason' => null];
        }

        if (!$paid) {
            $reason = is_array($error) ? ($error['failed_message'] ?? $error['failed_code'] ?? null) : null;
            if ($failed || $reason) self::logFailureOnce($pdo, $row, $homeService, current($details), $expected, $reason);
            return ['state' => $failed ? 'failed' : 'pending', 'reason' => $reason];
        }
        if (($paid['attributes']['currency'] ?? '') !== 'PHP' || (int) ($paid['attributes']['amount'] ?? -1) !== (int) round($expected * 100)) {
            throw new RuntimeException('PayMongo payment does not match the booking amount or currency.');
        }
        self::settle($pdo, $row, $homeService, $paid, $details);
        return ['state' => 'paid', 'reason' => null];
    }

    /**
     * Webhook fallback for a payment whose intent is no longer the booking's
     * current one (the customer pressed "Try again", then finished the old
     * GCash / Maya page too). Every intent was logged with its booking
     * reference when created. Recorded if the booking is still unpaid,
     * otherwise flagged so staff refund the extra payment.
     */
    public static function syncPayment(PDO $pdo, string $paymentId): bool
    {
        $payment = self::request('GET', '/payments/' . rawurlencode($paymentId))['data'] ?? [];
        $attributes = $payment['attributes'] ?? [];
        if (($payment['id'] ?? '') !== $paymentId || ($attributes['status'] ?? '') !== 'paid' || empty($attributes['payment_intent_id'])) return false;
        $stmt = $pdo->prepare("SELECT reference_code FROM payment_transactions WHERE event = 'intent_created' AND external_id = ? LIMIT 1");
        $stmt->execute([$attributes['payment_intent_id']]);
        $reference = $stmt->fetchColumn();
        if (!$reference) return false;
        foreach (['appointments' => false, 'home_service_requests' => true] as $table => $homeService) {
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE reference_code = ? LIMIT 1");
            $stmt->execute([$reference]);
            if (!$row = $stmt->fetch()) continue;
            $expected = (float) ($homeService ? $row['deposit_amount'] : ($row['reservation_amount_due'] ?? $row['deposit_amount']));
            if (($attributes['currency'] ?? '') !== 'PHP' || (int) ($attributes['amount'] ?? -1) !== (int) round($expected * 100)) {
                throw new RuntimeException('PayMongo payment does not match the booking amount or currency.');
            }
            self::settle($pdo, $row, $homeService, $payment, ['payment_intent_id' => $attributes['payment_intent_id'], 'superseded_attempt' => true]);
            return true;
        }
        return false;
    }

    private static function firstPaid(array $payments): ?array
    {
        foreach ($payments as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid' && !empty($payment['id'])) return $payment;
        }
        return null;
    }

    /**
     * Why the latest attempt failed, in last_payment_error's shape. PayMongo
     * often leaves last_payment_error empty and puts the message on the failed
     * payment instead (e.g. "Payment checkout has expired." for GCash).
     */
    private static function lastFailure(array $payments): ?array
    {
        foreach (array_reverse($payments) as $payment) {
            $attributes = $payment['attributes'] ?? [];
            if (($attributes['status'] ?? '') === 'failed') {
                return ['failed_code' => $attributes['failed_code'] ?? null, 'failed_message' => $attributes['failed_message'] ?? null];
            }
        }
        return null;
    }

    /** The method a payment was made with, from PayMongo's own record of it. */
    private static function methodForPayment(array $payment): string
    {
        return array_search($payment['attributes']['source']['type'] ?? '', self::PAYMENT_TYPES, true) ?: self::LEGACY_METHOD;
    }

    /** One 'payment_failed' log row per attempt, however often the result page is refreshed. */
    private static function logFailureOnce(PDO $pdo, array $row, bool $homeService, string $attemptId, float $amount, ?string $reason): void
    {
        $stmt = $pdo->prepare("SELECT 1 FROM payment_transactions WHERE event = 'payment_failed' AND external_id = ? LIMIT 1");
        $stmt->execute([$attemptId]);
        if ($stmt->fetchColumn()) return;
        PaymentLog::record($pdo, 'payment_failed', $homeService ? null : (int) $row['id'], $row['reference_code'], $attemptId, $amount, 'failed',
            ['reason' => $reason] + ($homeService ? ['type' => 'home_service'] : ['method' => $row['preferred_payment_method']]));
    }

    /** Records a verified payment under the row lock -- once. A second payment on a paid booking is flagged for refund. */
    private static function settle(PDO $pdo, array $row, bool $homeService, array $paid, array $details): void
    {
        // Legacy combined-checkout bookings keep 'PayMongo'; otherwise trust what PayMongo says was used.
        $method = !$homeService && ($row['preferred_payment_method'] ?? '') === self::LEGACY_METHOD ? self::LEGACY_METHOD : self::methodForPayment($paid);
        if ($homeService) require_once __DIR__ . '/../booking/HomeServiceRequest.php';
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $current = $homeService ? HomeServiceRequest::lock($pdo, (int) $row['id']) : Scheduling::lockAppointment($pdo, (int) $row['id']);
            if ((int) $current['deposit_paid'] === 1) {
                if ($current['deposit_reference'] !== $paid['id']) self::flagDuplicate($pdo, $current, $homeService, $paid);
            } elseif ($homeService) {
                self::recordHomeServicePayment($pdo, $current, $paid, $method, $details);
            } else {
                self::recordAppointmentPayment($pdo, $current, $paid, $method, $details);
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function flagDuplicate(PDO $pdo, array $row, bool $homeService, array $paid): void
    {
        $stmt = $pdo->prepare("SELECT 1 FROM payment_transactions WHERE event = 'duplicate_payment' AND external_id = ? LIMIT 1");
        $stmt->execute([$paid['id']]);
        if ($stmt->fetchColumn()) return;
        $amount = $paid['attributes']['amount'] / 100;
        PaymentLog::record($pdo, 'duplicate_payment', $homeService ? null : (int) $row['id'], $row['reference_code'], $paid['id'], $amount, 'refund_due',
            ['already_recorded' => $row['deposit_reference'], 'method' => self::methodForPayment($paid)]);
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
            ->execute(["Booking {$row['reference_code']} received a second online payment ({$paid['id']}, ₱" . number_format($amount, 2) . '). Refund it from the PayMongo dashboard.']);
    }

    /** Records a verified PayMongo payment as the appointment's deposit. Caller holds the appointment lock. */
    private static function recordAppointmentPayment(PDO $pdo, array $appointment, array $paid, string $method, array $details): void
    {
        $amount = $paid['attributes']['amount'] / 100;
        $stmt = $pdo->prepare("
            UPDATE appointments
            SET deposit_paid = 1, deposit_amount = ?, deposit_method = ?, deposit_reference = ?, paymongo_payment_id = ?,
                deposit_recorded_at = NOW(),
                -- A payment that lands after the hold was released is owed back.
                payment_status = IF(status = 'Cancelled', 'Refund Due', 'Awaiting Verification')
            WHERE id = ? AND deposit_paid = 0
        ");
        $stmt->execute([$amount, $method, $paid['id'], $paid['id'], $appointment['id']]);
        if ($stmt->rowCount() !== 1) return;

        $reference = $appointment['reference_code'];
        PaymentLog::record($pdo, 'payment_paid', (int) $appointment['id'], $reference, $paid['id'], $amount, 'paid', ['method' => $method] + $details + [
            'source' => $paid['attributes']['source']['type'] ?? null,
            'fee' => isset($paid['attributes']['fee']) ? $paid['attributes']['fee'] / 100 : null,
            'paid_after_cancellation' => ($appointment['status'] ?? '') === 'Cancelled',
        ]);
        CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'PAYMENT_SUBMITTED',
            "Your ₱" . number_format($amount, 2) . " reservation payment for {$reference} via " . self::label($method)
            . " was received (PayMongo payment ID {$paid['id']}) and is now Payment Being Verified.");
        if (($appointment['status'] ?? '') === 'Cancelled') {
            // Paid after the hold expired -- flagged Refund Due above; staff refund it.
            error_log("PayMongo: payment {$paid['id']} arrived for already-cancelled booking {$reference}.");
        }
    }

    /** Records a verified PayMongo payment as the home service reservation fee and confirms the request. Caller holds the request lock. */
    private static function recordHomeServicePayment(PDO $pdo, array $request, array $paid, string $method, array $details): void
    {
        $canConfirm = false;
        if (!in_array($request['status'], ['Cancelled','Completed'], true)) {
            $team = HomeServiceRequest::team($pdo, $request);
            $canConfirm = HomeServiceRequest::availability($pdo, $request, $team ?: null)['available'];
        }

        $cancelled = ($request['status'] ?? '') === 'Cancelled';
        $stmt = $pdo->prepare("
            UPDATE home_service_requests
            SET deposit_paid = 1, deposit_amount = ?, deposit_method = ?, deposit_reference = ?, paymongo_payment_id = ?, deposit_recorded_at = NOW(),
                -- Record the trusted payment even when a cancelled request needs staff review.
                payment_status = 'Down Payment Verified',
                status = IF(status IN ('Payment Required', 'Payment Being Verified', 'Quote Ready'), ?, status)
            WHERE id = ? AND deposit_paid = 0
        ");
        $amount = $paid['attributes']['amount'] / 100;
        $stmt->execute([$amount, $method, $paid['id'], $paid['id'], $canConfirm ? 'Confirmed' : 'Payment Being Verified', $request['id']]);
        if ($stmt->rowCount() !== 1) return;

        $reference = $request['reference_code'];
        // Every peso on a home service is a home_service_payments row (see HomeServicePayment).
        $pdo->prepare("INSERT INTO home_service_payments (home_service_request_id, kind, amount, method, reference) VALUES (?, 'deposit', ?, ?, ?)")
            ->execute([$request['id'], $amount, $method, $paid['id']]);
        PaymentLog::record($pdo, 'payment_paid', null, $reference, $paid['id'], $amount, 'paid', ['type' => 'home_service', 'method' => $method] + $details + [
            'source' => $paid['attributes']['source']['type'] ?? null,
            'fee' => isset($paid['attributes']['fee']) ? $paid['attributes']['fee'] / 100 : null,
            'paid_after_cancellation' => $cancelled,
        ]);
        $peso = '₱' . number_format($amount, 2);
        $via = ' via ' . self::label($method) . " (PayMongo payment ID {$paid['id']})";
        if ($cancelled) {
            error_log("PayMongo: payment {$paid['id']} arrived for already-cancelled home service {$reference}.");
        } elseif ($canConfirm) {
            CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'PAYMENT_VERIFIED',
                "Your {$peso} reservation fee for home service {$reference} was received{$via}. Status: Reservation Payment Received.");
            CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'CONFIRMED',
                "Your home service request {$reference} is now Confirmed. We'll let you know once a stylist is assigned.");
        }
        if (!$canConfirm && !$cancelled) CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'PAYMENT_VERIFIED', "Your {$peso} payment for {$reference} was received{$via}. Your schedule needs staff review; your payment is preserved and your request has not been moved.");
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
            ->execute(["Home service {$reference}: {$peso} reservation fee paid online via " . self::label($method) . ($cancelled ? ' after cancellation; payment preserved for review.' : ($canConfirm ? ' — request confirmed. Assign a stylist.' : ' — schedule requires review; payment preserved.'))]);
    }

    /** The paid payment object of a checkout session (home service balance links), or null if it isn't paid yet. */
    public static function paidPaymentForCheckout(string $checkoutId): ?array
    {
        $session = self::request('GET', '/checkout_sessions/' . rawurlencode($checkoutId));
        return self::firstPaid($session['data']['attributes']['payments'] ?? []);
    }

    /**
     * Cancels online-payment bookings left unpaid past HOLD_MINUTES so they
     * stop holding their time slot, closes an open card page, and returns
     * any loyalty points they used. A GCash / Maya intent has no cancel call;
     * if it's paid later anyway, sync() records it as Refund Due. Never
     * throws -- slot lookups and bookings must keep working even if PayMongo
     * is unreachable.
     */
    public static function releaseExpiredHolds(PDO $pdo): void
    {
        if (!self::isConfigured()) return;
        try {
            $stmt = $pdo->prepare("
                SELECT id, customer_id, employee_id, appointment_datetime, reference_code, status, deposit_paid,
                       paymongo_checkout_id, paymongo_payment_intent_id, loyalty_points_used, reservation_amount_due
                FROM appointments
                WHERE preferred_payment_method IN (?, ?, ?, ?) AND deposit_paid = 0 AND status = 'Pending'
                  AND created_at < NOW() - INTERVAL " . self::HOLD_MINUTES . " MINUTE
            ");
            $stmt->execute([self::LEGACY_METHOD, ...self::METHODS]);
            foreach ($stmt->fetchAll() as $appointment) {
                $attemptId = $appointment['paymongo_payment_intent_id'] ?: $appointment['paymongo_checkout_id'];
                try {
                    if (self::sync($pdo, $appointment)['state'] === 'paid') continue; // Paid after all -- webhook was missed.
                    if ($appointment['paymongo_checkout_id']) {
                        self::request('POST', '/checkout_sessions/' . rawurlencode($appointment['paymongo_checkout_id']) . '/expire');
                    }
                } catch (RuntimeException $e) {
                    error_log('PayMongo expire ' . $appointment['reference_code'] . ': ' . $e->getMessage());
                    PaymentLog::record($pdo, 'api_error', (int) $appointment['id'], $appointment['reference_code'], $attemptId, null, null, $e->getMessage());
                }

                $pdo->beginTransaction();
                $cancel = $pdo->prepare("UPDATE appointments SET status = 'Cancelled', cancelled_at = NOW() WHERE id = ? AND deposit_paid = 0 AND status = 'Pending'");
                $cancel->execute([$appointment['id']]);
                if ($cancel->rowCount() === 1) {
                    PaymentLog::record($pdo, 'checkout_expired', (int) $appointment['id'], $appointment['reference_code'],
                        $attemptId, (float) $appointment['reservation_amount_due'], 'expired',
                        'Unpaid after ' . self::HOLD_MINUTES . ' minutes; booking cancelled and slot released.');
                    if ($appointment['employee_id']) {
                        Waitlist::notifyOpening($pdo, (int) $appointment['employee_id'], $appointment['appointment_datetime']);
                    }
                    if ((int) $appointment['loyalty_points_used'] > 0) {
                        $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?')
                            ->execute([(int) $appointment['loyalty_points_used'], $appointment['customer_id']]);
                    }
                    CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'CANCELLED',
                        "Your booking {$appointment['reference_code']} was cancelled because the online payment wasn't completed within " . self::HOLD_MINUTES . ' minutes.');
                }
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('PayMongo releaseExpiredHolds error: ' . $e->getMessage());
        }
    }

    /**
     * Refunds (part of) a PayMongo payment. Returns the refund's id and
     * status: 'pending' (PayMongo is still processing it), 'succeeded', or
     * 'failed'. Throws RuntimeException with PayMongo's reason on rejection
     * (e.g. already fully refunded, or outside the refund window).
     */
    public static function refundPayment(string $paymentId, float $amount, string $notes): array
    {
        $res = self::request('POST', '/refunds', ['data' => ['attributes' => [
            'amount' => (int) round($amount * 100),
            'payment_id' => $paymentId,
            'reason' => 'requested_by_customer',
            'notes' => mb_substr($notes, 0, 255),
        ]]]);
        return ['id' => $res['data']['id'], 'status' => $res['data']['attributes']['status'] ?? 'pending'];
    }

    /** Current status of a refund created by refundPayment(). */
    public static function refundStatus(string $refundId): string
    {
        $res = self::request('GET', '/refunds/' . rawurlencode($refundId));
        return $res['data']['attributes']['status'] ?? 'pending';
    }

    /**
     * PayMongo payments created on/after $sinceTimestamp, newest first,
     * following PayMongo's cursor pagination (at most $maxPages x 100).
     * Returns [payments, truncated] -- truncated is true if older matching
     * payments were left unread.
     */
    public static function listPayments(int $sinceTimestamp, int $maxPages = 10): array
    {
        $payments = [];
        $after = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $query = ['limit' => 100] + ($after ? ['after' => $after] : []);
            $res = self::request('GET', '/payments?' . http_build_query($query));
            $data = $res['data'] ?? [];
            foreach ($data as $payment) {
                if (($payment['attributes']['created_at'] ?? PHP_INT_MAX) < $sinceTimestamp) {
                    return [$payments, false];
                }
                $payments[] = $payment;
            }
            if (empty($res['has_more']) || !$data) {
                return [$payments, false];
            }
            $after = end($data)['id'];
        }
        return [$payments, true];
    }

    /** Verifies the Paymongo-Signature header (t=...,te=...,li=...) against the raw request body. */
    public static function verifyWebhookSignature(string $rawBody, string $header): bool
    {
        $secret = self::credentials()['webhook_secret'] ?? '';
        if ($secret === '' || $header === '') return false;

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }
        $expected = hash_hmac('sha256', ($parts['t'] ?? '') . '.' . $rawBody, $secret);
        $payload = json_decode($rawBody, true);
        $live = $payload['data']['attributes']['livemode'] ?? null;
        if (!is_bool($live) || !ctype_digit($parts['t'] ?? '')) return false;
        $given = $parts[$live ? 'li' : 'te'] ?? ''; // Use the event's mode.
        return $given !== '' && hash_equals($expected, $given);
    }
}
