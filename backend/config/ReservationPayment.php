<?php

/**
 * Authoritative reservation pricing. Inputs must be service rows from the DB.
 *
 * The customer picks a payment plan:
 *   'deposit' -- each service's own rule (50% down, or full for services set
 *                to Full Payment), but never less than MIN_DEPOSIT (capped
 *                at the booking total, so a ₱80 booking is paid in full).
 *   'full'    -- the whole booking total upfront.
 * A 'No Online Reservation' service still skips payment entirely.
 */
final class ReservationPayment
{
    public const MIN_DEPOSIT = 100.0;

    public static function quote(array $services, int $availablePoints = 0, bool $redeem = false, string $plan = 'deposit'): array
    {
        $plan = $plan === 'full' ? 'full' : 'deposit';
        if (!$services) throw new InvalidArgumentException('Please select at least one service.');
        usort($services, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        $prices = array_map(fn($s) => (int) round((float) $s['price'] * 100), $services);
        if (min($prices) < 0) throw new InvalidArgumentException('A selected service has an invalid price.');
        $subtotal = array_sum($prices);
        $points = $redeem ? min(max(0, $availablePoints), intdiv($subtotal, 10)) : 0;
        $discount = $points * 10; // Existing loyalty rule: one point = ten centavos.
        $total = $subtotal - $discount;

        // Allocate discounts proportionally in integer centavos. Distribute
        // rounding remainders deterministically so line totals always add up.
        $net = []; $remainders = [];
        foreach ($prices as $i => $price) {
            $net[$i] = $subtotal ? intdiv($price * $total, $subtotal) : 0;
            $remainders[$i] = $subtotal ? ($price * $total) % $subtotal : 0;
        }
        arsort($remainders);
        $left = $total - array_sum($net);
        foreach ($remainders as $i => $_) { if ($left-- <= 0) break; $net[$i]++; }

        // A service configured 'No Online Reservation' is confirmed manually
        // after admin review, never through an upfront online deposit -- if
        // any selected service carries it, the whole booking skips online
        // payment entirely (amountDue = 0) rather than trying to blend it
        // with a partial online deposit for the other services.
        $hasNoOnlineReservation = false;
        foreach ($services as $service) {
            if ($service['payment_requirement'] === 'No Online Reservation') {
                $hasNoOnlineReservation = true;
                break;
            }
        }

        $twiceDue = 0; $requirements = []; $items = []; $allServicesFull = true;
        foreach ($services as $i => $service) {
            $requirementRaw = $service['payment_requirement'];
            if (!in_array($requirementRaw, ['Full Payment', '50% Down Payment', 'Half Payment', 'No Online Reservation'], true)) {
                throw new InvalidArgumentException('A selected service has an invalid reservation requirement.');
            }
            $allServicesFull = $allServicesFull && $requirementRaw === 'Full Payment';
            $full = $requirementRaw === 'Full Payment' || $plan === 'full';
            $requirement = $hasNoOnlineReservation ? 'No Online Reservation' : ($full ? 'Full Payment' : '50% Down Payment');
            $requirements[$requirement] = true;
            $twiceDue += $hasNoOnlineReservation ? 0 : ($net[$i] * ($full ? 2 : 1));
            $items[] = ['id' => (string) $service['id'], 'name' => $service['service_name'],
                'serviceTotal' => $net[$i] / 100, 'reservationRequirement' => $requirement];
        }
        $due = $hasNoOnlineReservation ? 0 : intdiv($twiceDue + 1, 2); // Round half a cent up, once for the booking.
        $requirementLabel = $hasNoOnlineReservation ? 'No Online Reservation'
            : (count($requirements) === 1 ? array_key_first($requirements) : '50% Down Payment + Full Payment');

        // Deposit floor: never ask for less than MIN_DEPOSIT, but never more than the booking itself.
        $minDeposit = (int) round(self::MIN_DEPOSIT * 100);
        if (!$hasNoOnlineReservation && $due < min($minDeposit, $total)) {
            $due = min($minDeposit, $total);
            $requirementLabel = $due >= $total ? 'Full Payment' : 'Minimum ₱' . number_format(self::MIN_DEPOSIT, 0) . ' Deposit';
        }
        // Choosing only makes a difference when a deposit would be less than the total.
        $canChoosePlan = !$hasNoOnlineReservation && !$allServicesFull && $total > $minDeposit;

        $quote = ['items' => $items, 'catalogSubtotal' => $subtotal / 100, 'discount' => $discount / 100,
            'pointsUsed' => $points, 'serviceTotal' => $total / 100,
            'reservationRequirement' => $requirementLabel,
            'amountDue' => $due / 100, 'remainingBalance' => ($total - $due) / 100,
            'paymentPlan' => !$hasNoOnlineReservation && $total > 0 && $due >= $total ? 'full' : 'deposit',
            'canChoosePlan' => $canChoosePlan,
            'minimumDeposit' => self::MIN_DEPOSIT,
            // Informational only: the customer pays this amountDue either in
            // Cash at the branch or online through PayMongo -- the booking
            // endpoints pick the path from the chosen payment method (see
            // backend/config/PayMongo.php). This quote stays gateway-agnostic.
            'provider' => 'manual'];
        // Detect a changed quote on submission; never use this token as a price input.
        $quote['quoteToken'] = hash('sha256', json_encode($quote));
        return $quote;
    }
}
