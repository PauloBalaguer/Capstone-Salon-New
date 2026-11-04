<?php

/**
 * Writes staff-facing notifications (notifications.user_id set to the
 * staff member's own account) for the header bell -- mirrors
 * CustomerNotifier.php but resolves through employees.id instead of
 * customers.id.
 */
final class StaffNotifier
{
    public static function notify(PDO $pdo, int $employeeId, string $type, string $message): void
    {
        $stmt = $pdo->prepare('SELECT user_id FROM employees WHERE id = ? LIMIT 1');
        $stmt->execute([$employeeId]);
        $userId = $stmt->fetchColumn();
        if (!$userId) {
            return;
        }
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)')
            ->execute([$userId, $type, $message]);
    }
}
