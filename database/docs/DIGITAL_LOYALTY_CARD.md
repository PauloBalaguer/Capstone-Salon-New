# Digital loyalty card

Apply `database/migrations/055_digital_loyalty_card.sql` before deploying.
The migration is repeatable and does not convert historic points or payments.

- The card belongs to the customer, across all branches. Each new salon
  appointment checked out through the cashier or admin earns one stamp if
  net service charges are at least PHP500. Retail products and tips do not count.
- Every fifth qualifying stamp earns a 20% service discount; every tenth earns
  one free eligible booked treatment. After ten stamps, a new card begins.
- Rewards are available on a later checkout, not the visit that earns them.
- The cashier selects a reward in Payments before collecting the final balance.
  It is applied to booked services only. One reward per checkout; it cannot
  combine with legacy loyalty points. Existing points remain redeemable at booking;
  new checkouts earn stamps instead of points. Frequency tiers remain separate.
- In Admin > Services & Promotions, edit an approved hair treatment and enable
  its free-treatment eligibility. Configure each branch's catalog entries.
  No treatments are marked eligible automatically.
- Rewards are saved for a later visit if the existing deposit exceeds the
  discounted service total. The system does not silently reduce the benefit
  or create a refund. The cashier sees eligible options before collection.
- Stamp and reward records store the originating appointment and branch.
  Customer row locks serialize earnings and redemption across branches; unique
  appointment constraints prevent duplicate stamps and reward use.
- No expiry date is imposed: the photographed card's historical printed date
  was not copied into the new program. Home service packages do not earn salon
  visit stamps. A visit means one completed appointment, even with many services.

Run `C:\xampp\php\php.exe tests/loyalty_card_test.php` after migration.
The integration test uses an existing customer and rolls back every fixture.
