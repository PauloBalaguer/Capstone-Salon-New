<?php

require_once __DIR__ . '/CustomerNotifier.php';
require_once __DIR__ . '/PaymentLog.php';
require_once __DIR__ . '/Waitlist.php';
require_once __DIR__ . '/url.php';

/**
 * PayMongo online reservation payments (Checkout Sessions API).
 *
 * Keys live in paymongo_credentials.php (git-ignored; copy
 * paymongo_credentials.example.php). A booking that picks the 'PayMongo'
 * method is inserted unpaid, then createCheckout() opens a hosted checkout
 * page for its reservation amount. syncAppointment() asks PayMongo whether
 * that session was paid and records the deposit -- it is called both by the
 * webhook (backend/public/paymongoWebhook.php) and by the payment result page
 * (backend/public/paymongoStatus.php), so payments still register locally
 * even when PayMongo can't reach the webhook on localhost.
 */
final class PayMongo
{
    public const METHOD = 'PayMongo';
    public const MIN_AMOUNT = 20.0; // PayMongo's minimum charge, in pesos.
    public const HOLD_MINUTES = 30; // Unpaid PayMongo bookings release their slot after this.

    private const API = 'https://api.paymongo.com/v1';

    private static ?array $credentials = null;

    private static function credentials(): array
    {
        if (self::$credentials === null) {
            $path = __DIR__ . '/paymongo_credentials.php';
            self::$credentials = is_file($path) ? (array) require $path : [];
        }
        return self::$credentials;
    }

    public static function isConfigured(): bool
    {
        return (self::credentials()['secret_key'] ?? '') !== '';
    }

    private static function request(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init(self::API . $path);
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
    public static function createCheckout(string $reference, float $amount, string $description, string $successUrl, string $cancelUrl): array
    {
        $res = self::request('POST', '/checkout_sessions', ['data' => ['attributes' => [
            'line_items' => [[
                'name' => $description,
                'quantity' => 1,
                'currency' => 'PHP',
                'amount' => (int) round($amount * 100), // centavos
            ]],
            'payment_method_types' => ['gcash', 'paymaya', 'card'],
            'description' => $description,
            'reference_number' => $reference,
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
            'metadata' => ['reference' => $reference],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]]]);
        return ['id' => $res['data']['id'], 'url' => $res['data']['attributes']['checkout_url']];
    }

    /**
     * Creates the checkout for a just-inserted unpaid booking (inside the
     * booking's transaction), stores it on the appointment, and logs it.
     * Returns the hosted checkout URL. Throws RuntimeException on API failure.
     */
    public static function startCheckout(PDO $pdo, int $appointmentId, string $reference, float $amount, string $from): string
    {
        $token = bin2hex(random_bytes(16));
        $baseUrl = getProjectFullBaseUrl();
        $checkout = self::createCheckout($reference, $amount, "Reservation payment {$reference}",
            self::resultUrl($baseUrl, $reference, $token, 'success', $from),
            self::resultUrl($baseUrl, $reference, $token, 'cancel', $from));
        $pdo->prepare('UPDATE appointments SET paymongo_checkout_id = ?, paymongo_checkout_url = ?, online_payment_token = ? WHERE id = ?')
            ->execute([$checkout['id'], $checkout['url'], $token, $appointmentId]);
        PaymentLog::record($pdo, 'checkout_created', $appointmentId, $reference, $checkout['id'], $amount, 'unpaid');
        return $checkout['url'];
    }

    /** Result-page URL (success or cancel) PayMongo sends the customer back to. */
    public static function resultUrl(string $baseUrl, string $reference, string $token, string $outcome, string $from): string
    {
        return $baseUrl . '/pages/payment/result.html?' . http_build_query([
            'ref' => $reference, 'token' => $token, 'outcome' => $outcome, 'from' => $from,
        ]);
    }

    /**
     * Checks the appointment's checkout session with PayMongo and, if paid,
     * records the deposit. Returns true when the deposit is (now) paid.
     * $appointment needs id, customer_id, reference_code, deposit_paid,
     * paymongo_checkout_id.
     */
    public static function syncAppointment(PDO $pdo, array $appointment): bool
    {
        if ((int) $appointment['deposit_paid'] === 1) return true;
        if (empty($appointment['paymongo_checkout_id']) || !self::isConfigured()) return false;

        $session = self::request('GET', '/checkout_sessions/' . rawurlencode($appointment['paymongo_checkout_id']));
        $paid = null;
        foreach ($session['data']['attributes']['payments'] ?? [] as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid') {
                $paid = $payment;
                break;
            }
        }
        if (!$paid) return false;

        $stmt = $pdo->prepare("
            UPDATE appointments
            SET deposit_paid = 1, deposit_amount = ?, deposit_method = ?, deposit_reference = ?,
                deposit_recorded_at = NOW(),
                -- A payment that lands after the hold was released is owed back.
                payment_status = IF(status = 'Cancelled', 'Refund Due', 'Awaiting Verification')
            WHERE id = ? AND deposit_paid = 0
        ");
        $stmt->execute([$paid['attributes']['amount'] / 100, self::METHOD, $paid['id'], $appointment['id']]);

        if ($stmt->rowCount() === 1) {
            $reference = $appointment['reference_code'];
            PaymentLog::record($pdo, 'payment_paid', (int) $appointment['id'], $reference, $paid['id'],
                $paid['attributes']['amount'] / 100, 'paid', [
                    'checkout_id' => $appointment['paymongo_checkout_id'],
                    'source' => $paid['attributes']['source']['type'] ?? null,
                    'fee' => isset($paid['attributes']['fee']) ? $paid['attributes']['fee'] / 100 : null,
                    'paid_after_cancellation' => ($appointment['status'] ?? '') === 'Cancelled',
                ]);
            CustomerNotifier::notify($pdo, (int) $appointment['customer_id'], 'PAYMENT_SUBMITTED',
                "Your online reservation payment for {$reference} was received and is now Payment Being Verified.");
            if (($appointment['status'] ?? '') === 'Cancelled') {
                // Paid after the hold expired -- flagged Refund Due above; staff refund it.
                error_log("PayMongo: payment {$paid['id']} arrived for already-cancelled booking {$reference}.");
            }
        }
        return true;
    }

    /** The paid payment object of a checkout session, or null if it isn't paid yet. */
    public static function paidPaymentForCheckout(string $checkoutId): ?array
    {
        $session = self::request('GET', '/checkout_sessions/' . rawurlencode($checkoutId));
        foreach ($session['data']['attributes']['payments'] ?? [] as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid') return $payment;
        }
        return null;
    }

    /**
     * Home service: creates the PayMongo checkout for the reservation fee
     * (down payment) the admin set with the quote, stores it on the request,
     * and logs it. Returns the hosted checkout URL. Throws RuntimeException
     * on API failure. $from is 'customer' (has an account) or 'guest', which
     * decides where the result page's "back" link goes.
     */
    public static function startHomeServiceCheckout(PDO $pdo, int $requestId, string $reference, float $amount, string $from): string
    {
        $token = bin2hex(random_bytes(16));
        $baseUrl = getProjectFullBaseUrl();
        $checkout = self::createCheckout($reference, $amount, "Home service reservation fee {$reference}",
            self::resultUrl($baseUrl, $reference, $token, 'success', $from),
            self::resultUrl($baseUrl, $reference, $token, 'cancel', $from));
        $pdo->prepare('UPDATE home_service_requests SET paymongo_checkout_id = ?, paymongo_checkout_url = ?, online_payment_token = ? WHERE id = ?')
            ->execute([$checkout['id'], $checkout['url'], $token, $requestId]);
        PaymentLog::record($pdo, 'checkout_created', null, $reference, $checkout['id'], $amount, 'unpaid', ['type' => 'home_service']);
        return $checkout['url'];
    }

    /**
     * Home service counterpart of syncAppointment(): asks PayMongo whether the
     * request's checkout was paid and, if so, records the down payment and
     * confirms the request. No manual verification step -- PayMongo itself
     * is the proof of payment. Returns true when the fee is (now) paid.
     * $request needs id, customer_id, reference_code, status, deposit_paid,
     * paymongo_checkout_id.
     */
    public static function syncHomeService(PDO $pdo, array $request): bool
    {
        if ((int) $request['deposit_paid'] === 1) return true;
        if (empty($request['paymongo_checkout_id']) || !self::isConfigured()) return false;

        $session = self::request('GET', '/checkout_sessions/' . rawurlencode($request['paymongo_checkout_id']));
        $paid = null;
        foreach ($session['data']['attributes']['payments'] ?? [] as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid') {
                $paid = $payment;
                break;
            }
        }
        if (!$paid) return false;

        $cancelled = ($request['status'] ?? '') === 'Cancelled';
        $stmt = $pdo->prepare("
            UPDATE home_service_requests
            SET deposit_paid = 1, deposit_amount = ?, deposit_method = ?, deposit_reference = ?, deposit_recorded_at = NOW(),
                -- Paid after the request was cancelled: money is owed back.
                payment_status = IF(status = 'Cancelled', 'Refund Due', 'Down Payment Verified'),
                status = IF(status IN ('Payment Required', 'Payment Being Verified', 'Quote Ready'), 'Confirmed', status)
            WHERE id = ? AND deposit_paid = 0
        ");
        $amount = $paid['attributes']['amount'] / 100;
        $stmt->execute([$amount, self::METHOD, $paid['id'], $request['id']]);

        if ($stmt->rowCount() === 1) {
            $reference = $request['reference_code'];
            // Every peso on a home service is a home_service_payments row (see HomeServicePayment).
            $pdo->prepare("INSERT INTO home_service_payments (home_service_request_id, kind, amount, method, reference) VALUES (?, 'deposit', ?, ?, ?)")
                ->execute([$request['id'], $amount, self::METHOD, $paid['id']]);
            PaymentLog::record($pdo, 'payment_paid', null, $reference, $paid['id'], $amount, 'paid', [
                'type' => 'home_service',
                'checkout_id' => $request['paymongo_checkout_id'],
                'source' => $paid['attributes']['source']['type'] ?? null,
                'fee' => isset($paid['attributes']['fee']) ? $paid['attributes']['fee'] / 100 : null,
                'paid_after_cancellation' => $cancelled,
            ]);
            $peso = '₱' . number_format($amount, 2);
            if ($cancelled) {
                error_log("PayMongo: payment {$paid['id']} arrived for already-cancelled home service {$reference}.");
            } else {
                CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'PAYMENT_VERIFIED',
                    "Your {$peso} reservation fee for home service {$reference} was received online. Status: Reservation Payment Received.");
                CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'CONFIRMED',
                    "Your home service request {$reference} is now Confirmed. We'll let you know once a stylist is assigned.");
            }
            $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
                ->execute(["Home service {$reference}: {$peso} reservation fee paid online via PayMongo" . ($cancelled ? ' AFTER it was cancelled — refund due.' : ' — request confirmed. Assign a stylist.')]);
        }
        return true;
    }

    /**
     * Cancels PayMongo bookings left unpaid past HOLD_MINUTES so they stop
     * holding their time slot, expires their checkout page, and returns any
     * loyalty points they used. Never throws -- slot lookups and bookings
     * must keep working even if PayMongo is unreachable.
     */
    public static function releaseExpiredHolds(PDO $pdo): void
    {
        if (!self::isConfigured()) return;
        try {
            $stmt = $pdo->prepare("
                SELECT id, customer_id, employee_id, appointment_datetime, reference_code, status, deposit_paid,
                       paymongo_checkout_id, loyalty_points_used, reservation_amount_due
                FROM appointments
                WHERE preferred_payment_method = ? AND deposit_paid = 0 AND status = 'Pending'
                  AND created_at < NOW() - INTERVAL " . self::HOLD_MINUTES . " MINUTE
            ");
            $stmt->execute([self::METHOD]);
            foreach ($stmt->fetchAll() as $appointment) {
                try {
                    if (self::syncAppointment($pdo, $appointment)) continue; // Paid after all -- webhook was missed.
                    if ($appointment['paymongo_checkout_id']) {
                        self::request('POST', '/checkout_sessions/' . rawurlencode($appointment['paymongo_checkout_id']) . '/expire');
                    }
                } catch (RuntimeException $e) {
                    error_log('PayMongo expire ' . $appointment['reference_code'] . ': ' . $e->getMessage());
                    PaymentLog::record($pdo, 'api_error', (int) $appointment['id'], $appointment['reference_code'],
                        $appointment['paymongo_checkout_id'], null, null, $e->getMessage());
                }

                $pdo->beginTransaction();
                $cancel = $pdo->prepare("UPDATE appointments SET status = 'Cancelled', cancelled_at = NOW() WHERE id = ? AND deposit_paid = 0 AND status = 'Pending'");
                $cancel->execute([$appointment['id']]);
                if ($cancel->rowCount() === 1) {
                    PaymentLog::record($pdo, 'checkout_expired', (int) $appointment['id'], $appointment['reference_code'],
                        $appointment['paymongo_checkout_id'], (float) $appointment['reservation_amount_due'], 'expired',
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
        $given = ($parts['li'] ?? '') !== '' ? $parts['li'] : ($parts['te'] ?? ''); // li = live mode, te = test mode
        return $given !== '' && hash_equals($expected, $given);
    }
}
