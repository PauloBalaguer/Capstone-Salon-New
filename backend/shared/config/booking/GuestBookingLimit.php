<?php

require_once __DIR__ . '/../helpers/PhoneNumber.php';

final class GuestBookingLimit
{
    public const LIMIT = 2;

    public static function count(PDO $pdo, string $phone, string $email): int
    {
        $phone = PhoneNumber::normalize($phone) ?? '';
        $international = $phone === '' ? '' : '+63' . substr($phone, 1);
        $email = strtolower(trim($email));
        // Keep the original salon email usable even after a later booking updates customers.email.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM (
            SELECT a.id FROM appointments a JOIN customers c ON c.id = a.customer_id
            LEFT JOIN users u ON u.id = c.user_id
            WHERE (? <> '' AND c.phone_number IN (?, ?))
               OR (? <> '' AND (LOWER(c.email) = ? OR LOWER(u.email) = ?
                   OR (a.notes LIKE 'Guest booking for %' AND LOCATE(', Email: ', a.notes) > 0
                       AND LOWER(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.notes, ', Email: ', -1), CHAR(10), 1))) = ?)))
            UNION ALL
            SELECT h.id FROM home_service_requests h JOIN customers c ON c.id = h.customer_id
            LEFT JOIN users u ON u.id = c.user_id
            WHERE (? <> '' AND c.phone_number IN (?, ?))
               OR (? <> '' AND (LOWER(h.contact_email) = ? OR LOWER(c.email) = ? OR LOWER(u.email) = ?))
        ) bookings");
        $stmt->execute([$phone, $phone, $international, $email, $email, $email, $email,
            $phone, $phone, $international, $email, $email, $email, $email]);
        return (int) $stmt->fetchColumn();
    }

    public static function enforce(PDO $pdo, string $phone, string $email): void
    {
        if (self::count($pdo, $phone, $email) < self::LIMIT) return;
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'registrationRequired' => true,
            'message' => 'Thank you for booking with us! You have reached the ' . self::LIMIT
                . '-booking guest limit. Please register or log in to book again. After verifying your account and mobile number, your earlier bookings will appear in your account.']);
        exit();
    }

    public static function lock(PDO $pdo): void
    {
        // Serialize guest submissions across both forms, including different phones sharing an email.
        $stmt = $pdo->prepare("SELECT GET_LOCK(CONCAT('guest_bookings:', DATABASE()), 10)");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() !== 1) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Bookings are busy right now. Please try again shortly.']);
            exit();
        }
        register_shutdown_function(static function () use ($pdo): void {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->prepare("SELECT RELEASE_LOCK(CONCAT('guest_bookings:', DATABASE()))")->execute();
        });
    }
}
