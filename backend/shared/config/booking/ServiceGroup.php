<?php

/**
 * Guest-facing service groups (Hair Services, Nails & Spa, ...), shared by
 * public guest booking (backend/public/getBranchServices.php) and the
 * cashier walk-in picker (backend/cashier/getDashboardData.php) so both show
 * the same category filter.
 */

if (!function_exists('serviceDisplayGroup')) {
    /**
     * Maps a branch's own "Menu / Section" category (plus the service name as a
     * tiebreaker) onto one of the guest-facing groups. First matching rule wins.
     */
    function serviceDisplayGroup(string $category, string $name): string
    {
        $parts = explode(' / ', $category, 2);
        $section = strtolower($parts[1] ?? $parts[0]);
        $menu = strtolower($parts[0]);
        $name = strtolower($name);
        // Promo/package sections stay together regardless of what the service is.
        if (preg_match('/promo|package|best sell|sale!|\(\d+\+\d+\)/', $section)) return 'Promos & Packages';
        $rules = [
            'Make-Up Services'     => '/make-?up|eyeliner|pigmentation/',
            'Aesthetic Treatments' => '/drip|push|gluta|meso|lipo|booster|botox|filler|wart|bb glow|bb blush|tattoo|\brf\b|add-on|aesthetic/',
            'Waxing & Laser'       => '/wax|laser|ipl|hair removal|underarm|bleach/',
            'Lashes & Brows'       => '/lash|brow|threading|microblad/',
            'Massage & Body Care'  => '/massage|body|scrub/',
            'Nails & Spa'          => '/nail|mani|pedi|foot|hand spa|polish|gel/',
            'Hair Services'        => '/hair|scalp|haircut|color|rebond|keratin|blow|curl|ironing|cellophane|hot oil|amazon|treatment/',
            'Facial & Skin Care'   => '/facial|skin|peel|acne|derma|face|whiten|mask/',
        ];
        // The service name is the most specific signal; fall back to the section, then the menu.
        foreach ([$name, $section, $menu] as $text) {
            foreach ($rules as $group => $re) {
                if (preg_match($re, $text)) return $group;
            }
        }
        return 'Facial & Skin Care';
    }
}
