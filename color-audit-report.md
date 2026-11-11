# Color audit report: shared green theme

Branch `admin-ui-redesign`, 10 Nov 2026. Nothing is committed. All colors come from `frontend/shared/theme-tokens.css`.

## Safety

**Changed files.** `git diff --stat` shows 78 tracked files changed. There are also six untracked files: `theme-tokens.css` (new), `cashier-panel.css`, `public-unify.css`, `portal-unify.css`, `cashier-shell.js` (all from the earlier redesign) and `type-scale.css`.

I checked for non-color changes by blanking out every color value (hex, rgb, `var(--token)`, Tailwind color classes) in both the pre-change backup and the current files, then diffing them. Only three files still differ:

| File | Difference | Intended? |
|---|---|---|
| `frontend/customer/appointments.html:265` | Gold "Review" button: `text-white` became `text-[color:var(--text)] hover:text-white` | Yes. This is a color fix (white on gold was 1.89:1) |
| `frontend/public/public-colors.css:21-22` | Two dark-mode selectors now target the renamed class (`text-[color:var(--success)]` / `--danger`) | Yes. Without this the dark-mode recolor stops matching |
| `frontend/shared/type-scale.css` | See below | **Not mine** |

Each HTML page also has one new `<link>` to `theme-tokens.css`.

**type-scale.css.** This file is untracked, and it was edited by something else (another editor or session) while the color pass was running. My scripts never write to it. The changes are typography, not color:
- every `:root:root:root body :is(...)` became `:root:root:root:root body :where(...)` (higher specificity on `:root`, zero specificity inside)
- new selectors were added to the lists (`.bk-branch-meta`, `.svc-card-cat`, `.lm-topbar-title h1`, `#btnProcessPayment`, and others)
- a new badge font-size rule was added (`.admin-badge, .cp-badge, ...`)

Recommendation: **keep it out of the color commit.** Review it on its own, since it's someone's in-progress type work. If it wasn't intentional, revert it.

**Backup location.** The backup is in the session scratchpad (`%LOCALAPPDATA%\Temp\claude\...\scratchpad\backup`), outside the repo, so git can't pick it up.

**Token redefinitions.** No other CSS redefines `--brand`, `--bg`, `--card`, `--border`, `--text`, `--muted`, `--accent` or the other tokens. `brand-palette.css` only aliases (`--brand-teal: var(--brand)` and so on).

One collision was found and fixed. `responsive-base.css` defines `--text-body: 1rem` (a font size) on `:root`, and it loads after the tokens file. That turned every `color: var(--text-body)` into an invalid value. **The color token is renamed to `--text-body-color`** across all files. `responsive-base.css` is unchanged.

**Stylesheet order.** `theme-tokens.css` is the first stylesheet in all 36 HTML heads.

**Tailwind build.** Node and npm aren't installed, so `npm run build` couldn't run. `output.css` was edited by hand: values plus the matching arbitrary-class selectors. The `@theme` block in `input.css` now points to the tokens, so a future build should reproduce the same colors. Run `npm run build` once Node is available and diff `output.css`.

## Leftovers

Scope: `frontend/` and `function/`, with white/black, semi-transparent overlays and shadows, and landing `.dark` rules excluded.

| Role | Raw hex/rgb/hsl outside tokens | Built-in Tailwind color classes without a token override |
|---|---|---|
| Admin | 0 | 0 |
| Cashier | 0 | 0 (was 26 class names, now token classes) |
| Staff | 0 | 0 |
| Customer | 0 | 0 (was 12) |
| Auth | 0 | 0 |
| Landing/Guest | 0 outside dark mode | 0 (was 4) |
| Shared | 0 outside dark mode | 0 (was 4) |

Remaining hard-coded colors, all deliberate:
- **Dark mode:** `frontend/shared/input.css:98-106` (the `.dark` Tailwind theme), `frontend/public/home-theme.css:70` and `:625`, `public-colors.css`, `booking-modal.css` `.dark` rules. Moving these to light tokens would break dark mode.
- **`output.css`:** 9 literal hex values, all inside the compiled `.dark` theme block (same values as above).
- **About 520 built-in Tailwind classes on Cashier and about 60 on Customer** (`text-slate-500`, `bg-amber-50`, and so on). Every one is overridden to tokens by `portal-colors.css`, `clean-theme.css` or `cashier-theme.css`, all with `!important`, on `body.salon-portal`.
- **SVG `fill`/`stroke` attributes, chart and canvas colors, data URIs:** these use the token's hex value written out, because those contexts can't read CSS variables.

Spot-checks:
- **Status colors:** success/danger/warn/info badges use the status tokens. Each pair passes AA (5.7 to 6.0:1).
- **Rating stars:** `text-gold-400`/`500`, which map to `--accent` in both role configs.
- **Customer hero:** `customer-portal.css:339` runs from `--brand-deep` to `--brand-dark` (as rgba) over the photo, which is correct.

## Questionable mappings

The first pass picked the nearest token by appearance. These choices were wrong and are now corrected:

| Original | First mapped to | Now | Where |
|---|---|---|---|
| `#9F9586`, `#BEB09A` (warm grays) | `--accent` (gold) | `--muted`, `--border-strong` | Admin `slate-600`/`700` in `theme-config.js`. Every `text-slate-600` in Admin had turned gold |
| Sage grays `#8DA99B` to `#9DA99E`, taupes `#B7A995`, `#B9A58A` | `--accent` | neutral tokens | Customer, Staff |
| `#F6F0E6`, `#F1EEE4`, `#F8EEE5` (creams) | `--danger-soft` / `--success-soft` | `--bg` | Auth input background (`--color-field-auth`), Customer |
| `#4ADE80`, `#8BC34A`, `#00BB7F` (greens) | `--accent` | `--success` | Customer, output.css |
| `#2A7754`, `#407C52` (Customer link/icon green) | `--success` | `--brand` | `.cp-text-button`, `.cp-text-link`, `.cp-details summary`, `.cp-modal-icon` |
| `#40735A` (profile cover gradient end) | `--muted` | `--brand` | `.cp-profile-cover` |

The fix: grays, creams and taupes can now only map to neutral tokens.

Still worth a look:
- **Pink error borders** (`#F2A3A3`, `#E3A6A6`, `#F1B8B2`, `#F5C2BC`) map to `--border-strong`, so the red tint on error borders is gone.
- **Mid sage greens on Customer** (`#3F9265`, `#4D7C61`, `#6D9C78` and similar) map to `--muted`. They were likely decorative icons that are now gray-green.
- **`text-purple-800` and `text-indigo-800`** in `function/cashier/cashier-common.js:41-42` both became `--info`, so two status chips now share a color.

## Fixed

| Fix | File |
|---|---|
| `--text-body` color token renamed to `--text-body-color` (collision with a font-size variable) | 12 files |
| 44 CSS attribute selectors such as `[class~="bg-[#FFFDF9]"]` had been rewritten to `bg-[var(--x)]`, which no longer matched the HTML classes (`bg-[color:var(--x)]`) | `clean-theme.css`, `portal-colors.css`, `customer-portal.css`, `input.css` (`@apply`), customer `appointments.html`, `services-promos.html` |
| Wrong nearest-token matches (table above), regenerated from the backup | 19 files |
| 46 built-in Tailwind color classes with no override swapped for token classes (`text-emerald-700` → `text-[color:var(--success)]`, and so on) | 24 Cashier, Customer, Landing and shared files |
| White on gold → `var(--text)` (1.89:1 → 8.33:1) | `customer-portal.css` `.cp-badge`, customer `appointments.html:265` |
| Photo-frame initials: gold-ink on `--border-strong` (4.13:1) → background `--accent-soft` (5.50:1) | `customer-portal.css` `.cp-photo-frame` |

Contrast checked on every element that sets both text and background (CSS rules, `<style>` blocks, inline styles, JS `cssText`, Tailwind class lists). The only cases under 4.5:1 left are three **disabled** controls (`--muted` on `--border`, 4.497:1), which WCAG exempts:
- `cashier-panel.css:270`
- `portal-colors.css:174`
- `portal-colors.css:191`

Key token pairs: muted on bg 5.28 · muted on card 5.63 · text-body-color on bg 7.71 · brand on bg 6.72 · white on brand 7.17 · text on accent 8.33 · accent-ink on card 6.14. **Gold `--accent` as text on white is 1.89**, so it must never be used for text on a light background.

## Needs my decision

1. **Gold overuse** (more than one gold highlight on a page; not fixed):
   - `customer/services-promos.html`: 12 (promo badges `bg-gold-500` at lines 69, 224, 231; selected-card borders at 223, 306, 318, 373, 419, 422, 431)
   - `customer/home-service.html`: 11 (selected-option borders at 112, 116, 125; `bg-gold-500` at 235; borders at 230 to 256)
   - `customer/appointments.html`: 5 (review button 225; borders 157, 207, 280, 287)
   - `customer/profile.html`: 2 (line 212: `bg-gold-500` with `hover:bg-gold-600`, one element)
   - `cashier/inventory.html`: 2 (`bg-amber-500` at 53 and 261)

   Suggestion: use `--brand` for selected and active borders, and keep gold for the single main highlight.
2. **Status colors used as decoration:**
   - `staff/staff-portal.css:302` `.sp-stat-icon.is-teal/.is-green` uses success green for a stat icon. Change to `--brand`/`--brand-soft`?
   - `shared/payment-methods.css:25` `.pm-logo--cash` uses success green. It reads as a brand color for cash; keep?
   - `admin/admin-theme.css` `.admin-tier--bronze` uses warn. It's a loyalty tier, not a warning; change to `--accent-soft`/`--accent-ink`?
3. **`type-scale.css`:** keep it or revert it (see Safety).
4. **Purple/indigo chips** in `cashier-common.js:41-42` now share `--info`. Do they need to be told apart?
5. **Notification badge** (`.cp-badge`) is gold with dark text. Your spec allowed `--danger` when the badge means "needs attention". Which do you want?

## Check in the browser

1. **Admin pages:** labels and secondary text (`text-slate-600`/`700`) should be gray-green, not gold.
2. **Customer services-promos and home-service:** check selected cards and promo badges (gold overuse), and that the link color is brand green.
3. **Cashier dashboard and appointments:** status chips (paid, pending, cancelled, low stock), borders and the scrollbar thumb, where built-in Tailwind classes were swapped.
4. **Auth login and register:** input background (now `--bg`, not pink), the copper-to-gold notices, and error borders.
5. **Landing page in light and dark mode:** dark mode should look exactly as before, and the payment-result page's success/error text should use the new status colors.
