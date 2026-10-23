<?php

require_once __DIR__ . '/PayMongo.php';
require_once __DIR__ . '/PaymentLog.php';
require_once __DIR__ . '/CustomerNotifier.php';
require_once __DIR__ . '/url.php';

/**
 * Money received for a home service: the reservation fee (DP) and the
 * remaining balance, each a row in home_service_payments (migration 049).
 * Once the payments cover the quote, payment_status becomes 'Fully Paid'.
 *
 * The balance can be paid online through its own PayMongo checkout (the
 * "Send Pay Link" action; the customer pays from the email, their account,
 * or Track Your Home Service) or recorded by the admin (cash / GCash / Maya).
 */
final class HomeServicePayment
{
    /** Sum of every payment received, and what's still owed against the quote. */
    public static function totals(PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare('
            SELECT h.quote_price,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM home_service_payments p WHERE p.home_service_request_id = h.id) AS paid
            FROM home_service_requests h WHERE h.id = ?
        ');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        $quote = $row && $row['quote_price'] !== null ? (float) $row['quote_price'] : 0.0;
        $paid = $row ? (float) $row['paid'] : 0.0;
        return ['quote' => $quote, 'paid' => $paid, 'due' => max(0.0, round($quote - $paid, 2))];
    }

    /**
     * Records a payment and settles payment_status. Returns the new totals.
     * $request needs id, reference_code, customer_id.
     */
    public static function record(PDO $pdo, array $request, string $kind, float $amount, string $method, ?string $reference, ?int $recordedBy): array
    {
        $pdo->prepare('INSERT INTO home_service_payments (home_service_request_id, kind, amount, method, reference, recorded_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$request['id'], $kind, $amount, $method, $reference !== '' ? $reference : null, $recordedBy]);

        $totals = self::totals($pdo, (int) $request['id']);
        if ($kind === 'balance' || ($totals['quote'] > 0 && $totals['due'] <= 0)) {
            $pdo->prepare('UPDATE home_service_requests SET payment_status = ? WHERE id = ?')
                ->execute([$totals['due'] <= 0 ? 'Fully Paid' : 'Down Payment Verified', $request['id']]);
        }
        if ($totals['due'] <= 0) {
            // Fully settled: an unused balance link can't be paid twice.
            $pdo->prepare('UPDATE home_service_requests SET balance_checkout_url = NULL WHERE id = ?')->execute([$request['id']]);
        }

        $reference = $request['reference_code'];
        PaymentLog::record($pdo, $kind === 'balance' ? 'balance_paid' : 'deposit_recorded', null, $reference, $reference, $amount, 'paid',
            ['type' => 'home_service', 'method' => $method, 'by_user_id' => $recordedBy, 'due_after' => $totals['due']]);

        if ($kind === 'balance') {
            $peso = '₱' . number_format($amount, 2);
            CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'PAYMENT_VERIFIED',
                "We received your {$peso} payment for home service {$reference} via {$method}. "
                . ($totals['due'] <= 0 ? 'Status: Paid in Full. Thank you!' : 'Remaining balance: ₱' . number_format($totals['due'], 2) . '.'));
            $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "PAYMENT", ?)')
                ->execute(["Home service {$reference}: {$peso} balance received via {$method}" . ($totals['due'] <= 0 ? ' — fully paid.' : ' — ₱' . number_format($totals['due'], 2) . ' still due.')]);
        }
        return $totals;
    }

    /**
     * Creates a PayMongo checkout for everything still owed, stores it on the
     * request, and emails/notifies the customer. Returns the URL. Throws
     * RuntimeException (message safe to show) when it can't.
     * $request needs id, reference_code, customer_id, user_id (nullable).
     */
    public static function sendBalanceLink(PDO $pdo, array $request): string
    {
        if (!PayMongo::isConfigured()) throw new RuntimeException('Online payment (PayMongo) is not set up.');
        $totals = self::totals($pdo, (int) $request['id']);
        if ($totals['due'] <= 0) throw new RuntimeException('Nothing is left to pay on this request.');
        if ($totals['due'] < PayMongo::MIN_AMOUNT) {
            throw new RuntimeException('The balance is below PayMongo\'s ₱' . number_format(PayMongo::MIN_AMOUNT, 2) . ' minimum — collect it in person.');
        }

        $reference = $request['reference_code'];
        $token = bin2hex(random_bytes(16));
        $from = $request['user_id'] !== null ? 'customer' : 'guest';
        $baseUrl = getProjectFullBaseUrl();
        $checkout = PayMongo::createCheckout($reference, $totals['due'], "Home service remaining balance {$reference}",
            PayMongo::resultUrl($baseUrl, $reference, $token, 'success', $from),
            PayMongo::resultUrl($baseUrl, $reference, $token, 'cancel', $from));
        $pdo->prepare('UPDATE home_service_requests SET balance_checkout_id = ?, balance_checkout_url = ?, balance_payment_token = ? WHERE id = ?')
            ->execute([$checkout['id'], $checkout['url'], $token, $request['id']]);
        PaymentLog::record($pdo, 'checkout_created', null, $reference, $checkout['id'], $totals['due'], 'unpaid', ['type' => 'home_service_balance']);

        CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'QUOTE_READY',
            "Your remaining balance for home service {$reference} is ₱" . number_format($totals['due'], 2) . '. '
            . "Pay it online (GCash, Maya or card): {$checkout['url']} — or pay in cash on the event day.");
        return $checkout['url'];
    }

    /**
     * Asks PayMongo whether the balance checkout was paid and records it once.
     * Returns true when paid. $request needs id, reference_code, customer_id,
     * balance_checkout_id.
     */
    public static function syncBalance(PDO $pdo, array $request): bool
    {
        if (empty($request['balance_checkout_id']) || !PayMongo::isConfigured()) return false;
        $paid = PayMongo::paidPaymentForCheckout($request['balance_checkout_id']);
        if (!$paid) return false;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM home_service_payments WHERE home_service_request_id = ? AND reference = ?');
        $stmt->execute([$request['id'], $paid['id']]);
        if ((int) $stmt->fetchColumn() === 0) {
            self::record($pdo, $request, 'balance', $paid['attributes']['amount'] / 100, PayMongo::METHOD, $paid['id'], null);
        }
        return true;
    }
}
