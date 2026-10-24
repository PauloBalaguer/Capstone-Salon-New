# Customer and home-service addresses

Apply `migrations/051_structured_addresses.sql` before deploying the updated endpoints.
The migration adds nullable JSON snapshots to `customers` and `home_service_requests`.
It never rewrites or parses old free-text addresses. Existing profile addresses remain
visible and are preserved when saving other profile fields. Customers can replace
them explicitly with structured fields. Old service requests retain their original text.

`shared/backend/data/albay-locations.json` is the only runtime location dataset. It contains
Albay, its 18 cities/municipalities, and 720 barangays with PSGC codes. No other
provinces or region groups are included. Forms and backend validation use this same
local file, so location selection does not depend on an external API or cached
national lists. Missing data fails closed with a field-specific server error.

To refresh deliberately, run `C:\xampp\php\php.exe database/scripts/refresh_albay_locations.php`.
This CLI-only importer downloads only Albay city/barangay lists from the PSGC
community API at https://psgc.gitlab.io/api/, validates hierarchy codes, and replaces
the local file only after all lists succeed. Review changes before deployment.
The dataset records its source and retrieval time; it is not an official live PSA release.

## Business rule

`shared/backend/config/booking/ServiceArea.php` documents the pre-existing **Albay-only home-service**
rule found in `index.html` (notice and terms) and `customer/frontend/home-service.html`.
The user subsequently confirmed **Albay only** for both profile and service address
forms and supplied a reference list covering its 18 cities/municipalities.
`address_provinces` now limits both forms and server validation to Albay;
the location endpoint also rejects city/barangay requests outside this scope.
The local Albay PSGC dataset remains separate from this policy. Existing stored
out-of-area profile addresses and historical service snapshots are preserved;
an explicitly edited structured address must meet the new scope.
Change this configuration and the existing customer-facing terms together if the
business changes its coverage.

Home services require province/group, city/municipality, barangay, plus at least one
house/unit, street, or subdivision/purok/sitio detail. Landmark is optional. In-branch
bookings do not require or collect a service address. Profile address is optional;
when supplied its hierarchy must be complete. Browser labels are ignored server-side.

Every new home-service request stores its own formatted address and JSON snapshot
with validated codes, canonical names, and detailed fields. Neither submitting an
alternate address nor editing the profile updates past requests. Saved-address use
copies fields into the request form and validates them again on submission.

Checks: `C:\xampp\php\php.exe tests/addresses.php` validates the complete
local dataset without network access. Existing free-text-only clients must deploy with the updated forms;
new home-service requests require structured data.

`C:\xampp\php\php.exe tests/address_persistence.php --migrate` applies
migration 050 twice to verify repeatability, then checks snapshot independence using
temporary tables only. Omit `--migrate` to check an already migrated database.
