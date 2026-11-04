<?php

/**
 * Once a Cashier closes a business day (daily_closures, see
 * backend/cashier/closeEod.php), that day's transactions are locked from
 * normal Cashier editing -- only an Admin/Owner reopen (is_reopened = 1)
 * lifts it. Shared by every Cashier write endpoint that touches a
 * date-scoped record (checkout, status changes, stock adjustments).
 */
final class EodLock
{
    public static function isDateLocked(PDO $pdo, int $branchId, string $dateYmd): bool
    {
        $stmt = $pdo->prepare('
            SELECT 1 FROM daily_closures
            WHERE branch_id = ? AND business_date = ? AND is_reopened = 0
            LIMIT 1
        ');
        $stmt->execute([$branchId, $dateYmd]);
        return (bool) $stmt->fetchColumn();
    }
}
