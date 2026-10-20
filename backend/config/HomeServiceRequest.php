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

    const UNAVAILABLE = 'Schedule unavailable. Please choose another date or time. Your request has not been moved.';

    /** Qualifications come from existing staff_services and active catalog services. */
    public static function serviceSkills(string $name): array
    {
        $skills = [];
        if (preg_match('/hair.*make.?up/i', $name)) $skills = ['Hair Styling', 'Event Makeup', 'Hair & Makeup Package'];
        elseif (preg_match('/event make.?up|bridal make.?up/i', $name)) $skills[] = 'Event Makeup';
        if (preg_match('/hair (?:styling|setting|ironing)|curl setting|blow\\s?dry/i', $name)) $skills[] = 'Hair Styling';
        if (preg_match('/brow|microblading/i', $name)) $skills[] = 'Brow Services';
        if (preg_match('/lash/i', $name)) $skills[] = 'Lash Services';
        return array_unique($skills);
    }

    public static function requestedServices(array $request): array
    {
        if (($request['event_type'] ?? '') === 'Wedding') $services = ['Hair & Makeup Package'];
        elseif (isset($request['services']) && is_array($request['services'])) $services = $request['services'];
        elseif (preg_match('/^Requested services: (.+)$/m', $request['requests'] ?? '', $m)) $services = array_values(array_intersect(self::SERVICES, array_map('trim', explode(',', trim($m[1])))));
        else return []; // Legacy requests need their services reviewed, not guessed.
        if (in_array('Hair & Makeup Package',$services,true)) $services = array_merge(array_diff($services,['Hair & Makeup Package']),['Hair Styling','Event Makeup']);
        return array_values(array_unique($services));
    }

    public static function lock(PDO $pdo, int $id): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Event update requires a transaction.');
        $stmt = $pdo->prepare('SELECT * FROM home_service_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Home service request not found.');
        return $row;
    }

    public static function team(PDO $pdo, array $request): array
    {
        $stmt = $pdo->prepare('SELECT employee_id FROM home_service_staff WHERE home_service_request_id = ?');
        $stmt->execute([$request['id']]);
        $ids = array_merge([(int) ($request['employee_id'] ?? 0)], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Advisory for new requests, authoritative inside assignment/confirmation transactions.
     * Confirmed requests without teams consume provisional staff capacity. Pending requests
     * without assignments do not reserve a whole day. No customer/staff record is changed.
     */
    public static function availability(PDO $pdo, array $request, ?array $fixedTeam = null): array
    {
        $services = self::requestedServices($request);
        if (!$services || array_diff($services, self::SERVICES)) return ['available'=>false, 'message'=>'Please review the requested event services before checking availability.'];
        $employees = $pdo->query('SELECT id FROM employees WHERE is_active = 1 ORDER BY id' . ($pdo->inTransaction() ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_COLUMN);
        $rows = $pdo->query('SELECT ss.employee_id, s.service_name FROM staff_services ss JOIN services s ON s.id = ss.service_id WHERE s.is_active = 1')->fetchAll();
        $skills = [];
        foreach ($rows as $row) $skills[(int) $row['employee_id']] = array_unique(array_merge($skills[(int) $row['employee_id']] ?? [], self::serviceSkills($row['service_name'])));
        $date = $request['preferred_date'];
        $stmt = $pdo->prepare("SELECT h.* FROM home_service_requests h WHERE h.status IN ('Confirmed','Staff Assigned','Service in Progress')
            AND h.employee_id IS NULL AND NOT EXISTS (SELECT 1 FROM home_service_staff t WHERE t.home_service_request_id = h.id)
            AND h.preferred_date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY) AND h.id != ?" . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $stmt->execute([$date,$date,$request['id'] ?? 0]);
        $demands = array_merge([$request], $stmt->fetchAll());
        $requestedStart = new DateTime($request['preferred_date'].' '.($request['preferred_time'] ?: '09:00:00'));
        $requestedEnd = (clone $requestedStart)->modify('+'.(Scheduling::HOME_SERVICE_DURATION_MINUTES + Scheduling::cleaningBufferMinutes()).' minutes');
        $jobs = [];
        foreach ($demands as $index => $demand) {
            $demandStart = new DateTime($demand['preferred_date'].' '.($demand['preferred_time'] ?: '09:00:00'));
            $demandEnd = (clone $demandStart)->modify('+'.(Scheduling::HOME_SERVICE_DURATION_MINUTES + Scheduling::cleaningBufferMinutes()).' minutes');
            if ($index > 0 && ($demandStart >= $requestedEnd || $requestedStart >= $demandEnd)) continue;
            $needs = self::requestedServices($demand);
            if (!$needs) return ['available'=>false, 'message'=>'An overlapping confirmed request needs its services reviewed by the administrator.'];
            $start = new DateTime($demand['preferred_date'].' '.($demand['preferred_time'] ?: '09:00:00'));
            $end = (clone $start)->modify('+'.(Scheduling::HOME_SERVICE_DURATION_MINUTES + Scheduling::cleaningBufferMinutes()).' minutes');
            $candidates = [];
            foreach ($employees as $employeeId) {
                $id = (int) $employeeId;
                if ($index === 0 && $fixedTeam !== null && !in_array($id, $fixedTeam, true)) continue;
                $covers = array_values(array_intersect($needs, $skills[$id] ?? []));
                if ($covers && !Scheduling::homeServiceStaffHasConflict($pdo,$id,$demand['preferred_date'],$demand['preferred_time'] ?: '09:00:00',(int) ($request['id'] ?? 0))) $candidates[$id] = $covers;
            }
            if ($index === 0 && $fixedTeam !== null && count($candidates) !== count($fixedTeam)) return ['available'=>false,'message'=>'Every selected team member must be active, qualified for a requested service, and available.'];
            $jobs[] = ['needs'=>$needs,'candidates'=>$candidates,'start'=>$start,'end'=>$end,'fixed'=>$index===0 ? $fixedTeam : null];
        }
        // Backtrack service coverage and concurrent provisional teams; this does not
        // assume a clients-per-stylist rule or automatically assign a customer's team.
        $allocate = function($index, $reserved) use (&$allocate,$jobs) {
            if ($index === count($jobs)) return true;
            $job = $jobs[$index];
            $options = $job['candidates'];
            foreach ($options as $id => $_) foreach ($reserved[$id] ?? [] as $window) {
                if ($window[0] < $job['end'] && $job['start'] < $window[1]) { unset($options[$id]); break; }
            }
            $cover = function($remaining, $picked) use (&$cover,$allocate,$index,$reserved,$job,$options) {
                if (!$remaining) {
                    $next = $reserved;
                    foreach ($job['fixed'] ?? $picked as $id) {
                        if (!isset($options[$id])) return false;
                        $next[$id][] = [$job['start'],$job['end']];
                    }
                    return $allocate($index+1,$next);
                }
                $skill = reset($remaining);
                foreach ($options as $id=>$skills) if (!in_array($id,$picked,true) && in_array($skill,$skills,true)) {
                    if ($cover(array_values(array_diff($remaining,$skills)),array_merge($picked,[$id]))) return true;
                }
                return false;
            };
            return $cover($job['needs'],[]);
        };
        $available = $allocate(0,[]);
        return ['available'=>$available,'message'=>$available ? 'Staff capacity is available for review. Your schedule is confirmed only after approval and any required payment.' : self::UNAVAILABLE];
    }

    public static function requireAvailable(PDO $pdo, array $request, ?array $team = null): void
    {
        $result = self::availability($pdo,$request,$team);
        if (!$result['available']) throw new RuntimeException($result['message']);
    }

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
        $request = self::lock($pdo, (int) $request['id']);
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

        $proposed = array_replace($request, ['preferred_date'=>$date,'preferred_time'=>$time]);
        $teamIds = self::team($pdo,$request);
        self::requireAvailable($pdo,$proposed,$teamIds ?: null);

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
