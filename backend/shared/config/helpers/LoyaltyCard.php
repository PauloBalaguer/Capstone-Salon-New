<?php

require_once __DIR__ . '/../payments/ReservationPayment.php';

/** One customer card, independent of branch. All writes use the checkout transaction. */
final class LoyaltyCard
{
    public const MIN_SPEND = 500;

    public static function lockCustomer(PDO $pdo, int $customerId): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Loyalty writes require a checkout transaction.');
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE');
        $stmt->execute([$customerId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Customer not found.');
    }

    public static function summary(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM loyalty_stamps WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        $total = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT id, reward_type AS type, earned_at AS earnedAt FROM loyalty_rewards WHERE customer_id = ? AND redeemed_at IS NULL ORDER BY id');
        $stmt->execute([$customerId]);
        return ['totalStamps' => $total, 'stamps' => $total % 10, 'completedCards' => intdiv($total, 10), 'minimumSpend' => self::MIN_SPEND, 'rewards' => $stmt->fetchAll()];
    }

    public static function earn(PDO $pdo, array $appointment, float $netServiceTotal): void
    {
        if ((int) round($netServiceTotal * 100) < self::MIN_SPEND * 100) return;
        self::lockCustomer($pdo, (int) $appointment['customer_id']);
        $check = $pdo->prepare('SELECT id FROM loyalty_stamps WHERE appointment_id = ? FOR UPDATE');
        $check->execute([$appointment['id']]);
        if ($check->fetchColumn()) return;
        $pdo->prepare('INSERT INTO loyalty_stamps (customer_id, appointment_id, branch_id, service_amount) VALUES (?, ?, ?, ?)')
            ->execute([$appointment['customer_id'], $appointment['id'], $appointment['branch_id'], $netServiceTotal]);
        $stampId = $pdo->lastInsertId();
        // A current locking read is required: another branch may have committed
        // a stamp after this checkout's repeatable-read snapshot was opened.
        $count = $pdo->prepare('SELECT id FROM loyalty_stamps WHERE customer_id = ? FOR UPDATE');
        $count->execute([$appointment['customer_id']]);
        $milestone = count($count->fetchAll(PDO::FETCH_COLUMN)) % 10;
        if ($milestone === 5 || $milestone === 0) {
            $pdo->prepare('INSERT INTO loyalty_rewards (customer_id, earned_stamp_id, reward_type) VALUES (?, ?, ?)')
                ->execute([$appointment['customer_id'], $stampId, $milestone === 5 ? 'discount20' : 'treatment']);
        }
    }

    /** Returns the exact net service discount in centavos; no products or tips. */
    public static function discount(string $type, float $total, array $services, int $serviceId): float
    {
        if ($type === 'discount20') return round($total * 0.2, 2);
        if ($type !== 'treatment') throw new RuntimeException('Invalid loyalty reward.');
        $prices = ReservationPayment::receiptServicePrices($services, $total);
        foreach ($services as $index => $service) {
            if ((int) $service['id'] === $serviceId && !empty($service['loyalty_treatment_eligible'])) return $prices[$index];
        }
        throw new RuntimeException('Select a booked treatment marked eligible by the administrator.');
    }

    public static function redeem(PDO $pdo, array $appointment, array $services, int $rewardId, int $serviceId): float
    {
        if ($rewardId === 0) return 0;
        self::lockCustomer($pdo, (int) $appointment['customer_id']);
        $stmt = $pdo->prepare('SELECT * FROM loyalty_rewards WHERE id = ? AND customer_id = ? AND redeemed_at IS NULL FOR UPDATE');
        $stmt->execute([$rewardId, $appointment['customer_id']]);
        $reward = $stmt->fetch();
        if (!$reward) throw new RuntimeException('This reward is no longer available. Refresh checkout.');
        if ((int) ($appointment['loyalty_points_used'] ?? 0) > 0) throw new RuntimeException('A loyalty reward cannot be combined with redeemed points. Save this reward for another visit.');
        $discount = self::discount($reward['reward_type'], (float) $appointment['total_price'], $services, $serviceId);
        $deposit = $appointment['deposit_paid'] ? (float) $appointment['deposit_amount'] : 0;
        if ($discount <= 0 || $deposit > round((float) $appointment['total_price'] - $discount, 2)) {
            throw new RuntimeException('This reward cannot be applied because the reservation payment already exceeds the discounted total. Save it for a later visit.');
        }
        $pdo->prepare('UPDATE loyalty_rewards SET redeemed_appointment_id = ?, redeemed_service_id = ?, discount_amount = ?, redeemed_at = NOW() WHERE id = ?')
            ->execute([$appointment['id'], $reward['reward_type'] === 'treatment' ? $serviceId : null, $discount, $rewardId]);
        return $discount;
    }
}
