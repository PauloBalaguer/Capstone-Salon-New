<?php

require_once __DIR__ . '/Scheduling.php';
require_once __DIR__ . '/CustomerNotifier.php';
require_once __DIR__ . '/StaffNotifier.php';

/** Shared validation, package details and rescheduling for guest and customer home service requests. */
class HomeServiceRequest
{
    const PACKAGES = [
        'A' => ['price' => 5000, 'fee' => null],
        'B' => ['price' => 8000, 'fee' => null],
        'C' => ['price' => 10000, 'fee' => null],
        'D' => ['price' => 12000, 'fee' => 2000],
    ];
    const SERVICES = ['Hair Styling', 'Event Makeup', 'Brow Services', 'Lash Services', 'Hair & Makeup Package'];

    public static function validate(string $date, string $time, string $event, string $package, string $clients, array $services): ?string
    {
        $zone = new DateTimeZone('Asia/Manila');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$day || $day->format('Y-m-d') !== $date) return 'Please select a valid preferred date.';
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) return 'Please select a valid preferred time.';
        if (new DateTimeImmutable("$date $time", $zone) <= new DateTimeImmutable('now', $zone)) return 'Please choose a future date and time.';
        if (filter_var($clients, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) return 'Please enter the number of clients (at least 1).';
        if (trim($event) === '') return 'Please specify the event type.';
        if ($event === 'Wedding') {
            if (!isset(self::PACKAGES[$package])) return 'Please choose a wedding package.';
        } elseif (!$services || array_diff($services, self::SERVICES)) {
            return 'Please select the services you need.';
        }
        return null;
    }

    public static function details(string $event, string $package, string $clients, array $services, string $venueDetails, string $notes): string
    {
        $lines = ["Number of clients: $clients"];
        if ($event === 'Wedding') {
            $pkg = self::PACKAGES[$package];
            $lines[] = "Wedding Package $package";
            $lines[] = 'Package Price: ₱' . number_format($pkg['price']);
            $lines[] = 'Reservation Fee: ' . ($pkg['fee'] === null ? 'To be confirmed after review.' : '₱' . number_format($pkg['fee']));
            $lines[] = 'Remaining Balance: ' . ($pkg['fee'] === null ? 'To be confirmed after review.' : '₱' . number_format($pkg['price'] - $pkg['fee']));
            $lines[] = 'Remaining balance shown is after the required reservation fee is paid; no payment has been collected with this request.';
        } else {
            $lines[] = 'Requested services: ' . implode(', ', $services);
        }
        if ($venueDetails !== '') $lines[] = "Venue details: $venueDetails";
        if ($notes !== '') $lines[] = "Additional requirements: $notes";
        return implode("\n", $lines);
    }

    /* ---------------- Customer rescheduling ---------------- */

    /** Online changes must be made at least this long before the event (team + travel planning). */
    const RESCHEDULE_CUTOFF_HOURS = 72;
    /** After this many online changes, the customer has to contact the branch. */
    const MAX_ONLINE_RESCHEDULES = 2;
    const RESCHEDULABLE_STATUSES = ['Pending Review', 'Quote Ready', 'Payment Required', 'Payment Being Verified', 'Confirmed', 'Staff Assigned'];

    /** Columns reschedule()/canReschedule() need, from home_service_requests h. */
    const RESCHEDULE_COLUMNS = 'h.id, h.reference_code, h.customer_id, h.status, h.preferred_date, h.preferred_time, h.reschedule_count';

    /** Why the customer can't move this request online right now, or null if they can. */
    public static function rescheduleBlockedReason(array $request): ?string
    {
        if (!in_array($request['status'], self::RESCHEDULABLE_STATUSES, true)) {
            return 'This request can no longer be rescheduled online (' . $request['status'] . '). Please contact the branch.';
        }
        if ((int) $request['reschedule_count'] >= self::MAX_ONLINE_RESCHEDULES) {
            return 'You have already rescheduled this request ' . self::MAX_ONLINE_RESCHEDULES . ' times online. Please contact the branch to change it again.';
        }
        $zone = new DateTimeZone('Asia/Manila');
        $event = new DateTimeImmutable($request['preferred_date'] . ' ' . ($request['preferred_time'] ?: '09:00:00'), $zone);
        $hoursLeft = ($event->getTimestamp() - time()) / 3600;
        if ($hoursLeft < self::RESCHEDULE_CUTOFF_HOURS) {
            return 'Home services can only be rescheduled online at least ' . (self::RESCHEDULE_CUTOFF_HOURS / 24) . ' days before the event. Please contact the branch.';
        }
        return null;
    }

    /**
     * Moves the request to a new date/time. The assigned team (if any) must
     * be free then; the quote, reservation fee and team carry over. Returns a
     * customer-facing success message; throws RuntimeException (message safe
     * to show) when it can't be done.
     */
    public static function reschedule(PDO $pdo, array $request, string $date, string $time): string
    {
        if ($blocked = self::rescheduleBlockedReason($request)) throw new RuntimeException($blocked);

        $zone = new DateTimeZone('Asia/Manila');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$day || $day->format('Y-m-d') !== $date) throw new RuntimeException('Please choose a valid new date.');
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) throw new RuntimeException('Please choose a valid new time.');
        $newStart = new DateTimeImmutable("$date $time", $zone);
        if (($newStart->getTimestamp() - time()) / 3600 < self::RESCHEDULE_CUTOFF_HOURS) {
            throw new RuntimeException('The new date must be at least ' . (self::RESCHEDULE_CUTOFF_HOURS / 24) . ' days from now so the team can prepare.');
        }
        if ($date === $request['preferred_date'] && substr((string) $request['preferred_time'], 0, 5) === $time) {
            throw new RuntimeException('That is already your scheduled date and time.');
        }

        // The whole assigned team has to be free at the new time.
        $stmt = $pdo->prepare("
            SELECT DISTINCT e.id, CONCAT(e.first_name, ' ', e.last_name) AS name
            FROM employees e
            WHERE e.id IN (SELECT employee_id FROM home_service_staff WHERE home_service_request_id = ?)
               OR e.id = (SELECT employee_id FROM home_service_requests WHERE id = ?)
        ");
        $stmt->execute([$request['id'], $request['id']]);
        $team = $stmt->fetchAll();
        $busy = [];
        foreach ($team as $member) {
            if (Scheduling::homeServiceStaffHasConflict($pdo, (int) $member['id'], $date, $time . ':00', (int) $request['id'])) {
                $busy[] = $member['name'];
            }
        }
        if ($busy) {
            throw new RuntimeException('Your assigned team (' . implode(', ', $busy) . ') is not free at that time. Please choose another date or time, or contact the branch.');
        }

        $pdo->prepare('UPDATE home_service_requests SET preferred_date = ?, preferred_time = ?, reschedule_count = reschedule_count + 1 WHERE id = ?')
            ->execute([$date, $time . ':00', $request['id']]);

        $when = $newStart->format('l, F j, Y \a\t g:i A');
        $old = (new DateTimeImmutable($request['preferred_date'] . ' ' . ($request['preferred_time'] ?: '09:00:00'), $zone))->format('M j, Y g:i A');
        $reference = $request['reference_code'];
        CustomerNotifier::notify($pdo, (int) $request['customer_id'], 'RESCHEDULE',
            "Your home service request {$reference} has been moved to {$when}. Your quote, reservation fee and assigned team carry over.");
        foreach ($team as $member) {
            StaffNotifier::notify($pdo, (int) $member['id'], 'HOME_SERVICE_RESCHEDULED',
                "Home service {$reference} was moved by the customer from {$old} to {$when}.");
        }
        $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (NULL, "BOOKING", ?)')
            ->execute(["Home service {$reference} rescheduled by the customer: {$old} → {$when}."]);

        return "Your home service is now on {$when}.";
    }
}
