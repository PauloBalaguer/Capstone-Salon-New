<?php

require_once __DIR__ . '/../security/AuditLog.php';

/**
 * A stylist asking the front desk (branch cashier or admin) to act on a late or absent customer.
 * A request changes nothing about the booking or its payment: the cashier
 * decides, and only their action marks a no-show or moves the booking.
 *
 * Requests are kept in audit_log (who, when, which booking), and only today's
 * count as open, so a stale request can't reappear on a later day.
 */
final class ArrivalRequest
{
    public const NO_SHOW = 'no_show';
    public const RESCHEDULE = 'reschedule';
    public const KINDS = [self::NO_SHOW, self::RESCHEDULE];

    private const AUDIT_ACTION = 'booking/arrival_request';

    /** Kinds requested today, by booking reference: ['LM-...' => ['no_show'], ...]. */
    public static function openToday(PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT entity_ref, details FROM audit_log WHERE action = ? AND created_at >= CURDATE() ORDER BY id');
        $stmt->execute([self::AUDIT_ACTION]);
        $open = [];
        foreach ($stmt->fetchAll() as $row) {
            $kind = json_decode((string) $row['details'], true)['kind'] ?? null;
            if ($kind !== null && !in_array($kind, $open[$row['entity_ref']] ?? [], true)) {
                $open[$row['entity_ref']][] = $kind;
            }
        }
        return $open;
    }

    /** Logs the request and tells the booking's branch cashiers and the admins. */
    public static function send(PDO $pdo, array $appointment, string $staffName, string $kind, int $minutesLate): string
    {
        $reference = $appointment['reference_code'];
        $late = "{$minutesLate} minute" . ($minutesLate === 1 ? '' : 's') . ' after the reserved time';
        $message = $kind === self::NO_SHOW
            ? "{$staffName} requests a No-Show for {$reference}: the customer has not arrived, {$late}. Please review it in Appointments."
            : "{$staffName} asks you to reassign or reschedule {$reference}: the service cannot go ahead as booked, {$late}. Please review it in Appointments.";

        $stmt = $pdo->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.is_active = 1 AND ((r.role_name = 'Cashier' AND u.branch_id = ?) OR r.role_name = 'Admin')");
        $stmt->execute([$appointment['branch_id']]);
        $notify = $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (?, "ARRIVAL_REQUEST", ?)');
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [null];
        foreach ($recipients as $recipientUserId) {
            $notify->execute([$recipientUserId, $message]);
        }

        AuditLog::record($pdo, self::AUDIT_ACTION, 'Asked the cashier to review an arrival', $reference, $message, ['kind' => $kind]);
        return $message;
    }
}
