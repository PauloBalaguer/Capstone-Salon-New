<?php

/**
 * Writes staff-facing notifications (notifications.user_id set to the
 * staff member's own account) for the header bell -- mirrors
 * CustomerNotifier.php but resolves through employees.id instead of
 * customers.id. In-portal only (no SMS/email for staff).
 *
 * A notification never decides whether a booking is saved: a failed insert
 * is logged and swallowed, so it cannot roll back or cancel a confirmed
 * booking. (A failed statement in InnoDB only undoes that statement, not
 * the caller's transaction.)
 */
final class StaffNotifier
{
    public static function notify(PDO $pdo, int $employeeId, string $type, string $message): void
    {
        try {
            $userId = self::userId($pdo, $employeeId);
            if (!$userId) return;
            $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)')
                ->execute([$userId, $type, $message]);
        } catch (PDOException $e) {
            error_log("StaffNotifier {$type} for employee {$employeeId} failed: " . $e->getMessage());
        }
    }

    /**
     * Like notify(), but skips an EXACT repeat: when this staff member's most
     * recent notification already has the same type and message (a double
     * click, a network retry, re-saving the same status). Anything in between
     * -- e.g. "removed from" then "assigned to" the same booking again, or a
     * reschedule (its text carries the new time) -- is a new event and is sent.
     */
    public static function notifyOnce(PDO $pdo, int $employeeId, string $type, string $message): void
    {
        try {
            $userId = self::userId($pdo, $employeeId);
            if (!$userId) return;
            $stmt = $pdo->prepare('SELECT type, message FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$userId]);
            $last = $stmt->fetch();
            if ($last && $last['type'] === $type && $last['message'] === $message) return;
            $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)')
                ->execute([$userId, $type, $message]);
        } catch (PDOException $e) {
            error_log("StaffNotifier {$type} for employee {$employeeId} failed: " . $e->getMessage());
        }
    }

    private static function userId(PDO $pdo, int $employeeId)
    {
        $stmt = $pdo->prepare('SELECT user_id FROM employees WHERE id = ? LIMIT 1');
        $stmt->execute([$employeeId]);
        return $stmt->fetchColumn();
    }
}
