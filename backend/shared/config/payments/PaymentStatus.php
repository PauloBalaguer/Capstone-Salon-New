<?php

/**
 * Customer- and staff-facing wording for payment_status, used in emails and
 * in-app messages. The browser uses the same table
 * (function/shared/portal-presentation.js, PortalPresentation.paymentStatus)
 * -- keep the two in step.
 *
 * Three clearly different states customers care about:
 *   Unpaid                            (Payment Required)
 *   Deposit paid · ₱X balance due     (Down Payment Verified)
 *   Fully paid                        (Fully Paid)
 */
final class PaymentStatus
{
    public const LABELS = [
        'Payment Required' => 'Unpaid',
        'Awaiting Verification' => 'Payment being checked',
        'Down Payment Verified' => 'Deposit paid',
        'Fully Paid' => 'Fully paid',
        'Rejected' => 'Payment needs attention',
        'Refund Due' => 'Refund due',
        'Refund Processing' => 'Refund in progress',
        'Refunded' => 'Refunded',
        'Forfeited' => 'Deposit kept (non-refundable)',
    ];

    /** "Deposit paid · ₱1,250.00 balance due" when a balance is known and above zero. */
    public static function label(?string $status, ?float $balance = null): string
    {
        $label = self::LABELS[$status] ?? ($status ?: 'Unpaid');
        if ($status === 'Down Payment Verified' && $balance !== null && $balance > 0) {
            $label .= ' · ₱' . number_format($balance, 2) . ' balance due';
        }
        return $label;
    }
}
