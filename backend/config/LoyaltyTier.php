<?php

/**
 * Loyalty status based on booking frequency.
 *
 * A customer's tier comes from how many visits they completed (appointments
 * Completed or Reviewed) in the last WINDOW_MONTHS months, so it reflects
 * how regularly they come in right now -- separate from loyalty points,
 * which are a spendable balance. Shown to customers on their dashboard and
 * to admins in the Customers directory. Tier thresholds live only here.
 */
final class LoyaltyTier
{
    public const WINDOW_MONTHS = 12;

    /** Minimum visits in the window for each tier, highest first. */
    public const TIERS = [
        'VIP' => 10,
        'Gold' => 6,
        'Silver' => 3,
        'Member' => 0,
    ];

    /** SQL condition for a visit that counts toward the tier (alias `a`). */
    public const VISIT_CONDITION = "a.status IN ('Completed', 'Reviewed') AND a.appointment_datetime >= NOW() - INTERVAL 12 MONTH";

    public static function fromVisits(int $visits): array
    {
        $tier = 'Member';
        foreach (self::TIERS as $name => $minimum) {
            if ($visits >= $minimum) { $tier = $name; break; }
        }
        // Next tier up, if any.
        $names = array_keys(self::TIERS);
        $index = array_search($tier, $names, true);
        $next = $index > 0 ? $names[$index - 1] : null;

        return [
            'tier' => $tier,
            'visits' => $visits,
            'windowMonths' => self::WINDOW_MONTHS,
            'nextTier' => $next,
            'visitsToNext' => $next ? self::TIERS[$next] - $visits : 0,
        ];
    }

    public static function forCustomer(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments a WHERE a.customer_id = ? AND ' . self::VISIT_CONDITION);
        $stmt->execute([$customerId]);
        return self::fromVisits((int) $stmt->fetchColumn());
    }

    /** Tier thresholds for display, lowest first: [['tier' => 'Member', 'minVisits' => 0], ...]. */
    public static function ladder(): array
    {
        $ladder = [];
        foreach (array_reverse(self::TIERS, true) as $name => $minimum) {
            $ladder[] = ['tier' => $name, 'minVisits' => $minimum];
        }
        return $ladder;
    }
}
