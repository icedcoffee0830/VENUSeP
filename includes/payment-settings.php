<?php
/* =====================================================================
   PAYMENT SETTINGS — the ONE source of the GCash account per venue.

   Included by:
     admin/payment-settings.php         (the screen that edits these)
     customer/room-reservation.php      (Bahay Alumni / USeP Venues)
     customer/hostel-reservation.php    (USeP Hostel)

   WHY ONE FILE: the receipt checker verifies that money landed in the
   RIGHT account, and the payment screen tells the customer where to send
   it. If those two ever read different values, the customer pays the
   number they were shown and the checker rejects it as "receiver
   mismatch" — a bug that looks like OCR failing and is nearly impossible
   to spot. Both read from here, so they cannot disagree.

   THREE ACCOUNTS, ONE PER VENUE (Aron, 2026-07-15). Note Bahay Alumni and
   USeP Venues used to SHARE one business account; they are now separate,
   so a receipt paid to Bahay Alumni is correctly rejected on a USeP
   Venues booking. That is the intended behaviour, not a regression.

   [SIM] nothing persists — admin/payment-settings.php edits these in-page
   only. Replace this array with a `payment_settings` table keyed by venue.
   ===================================================================== */

$gcAccounts = [
  'Bahay Alumni' => [
    'name'   => 'Mika J Juarez',
    'number' => '09951941234',
    'note'   => 'Bahay Alumni bookings. Verified by the receipt checker on every GCash payment.',
  ],
  'USeP Venues' => [
    'name'   => 'Ramon T Villaflor',
    'number' => '09183345566',
    'note'   => 'USeP Venues bookings (gym, AVR, halls).',
  ],
  'USeP Hostel' => [
    // The hostel's money does NOT go to a business account. The guest pays a
    // designated STAFF account; the staff cash it out and hand it to the
    // University Cashier, who issues the OR. Hence a person's account, and
    // hence it must never be confused with the venue ones.
    'name'   => 'Rina S Delos Reyes',
    'number' => '09171234567',
    'note'   => 'Hostel bookings. Designated staff account — cashed out and brought to the University Cashier for the OR. PLACEHOLDER number until CEDU provides the real one.',
  ],
];

/* The account a booking's money should land in, by venue. Falls back to null
   rather than to some other venue's account: verifying against the wrong
   account is worse than refusing to verify, because it would silently accept
   money paid to the wrong place. */
function gcAccountFor(array $accounts, $venue) {
  return isset($accounts[$venue]) ? $accounts[$venue] : null;
}

/* Pretty 0995 194 1234 — display only; the checker always compares digits. */
function gcFormatNumber($n) {
  return preg_replace('/^(\d{4})(\d{3})(\d{4})$/', '$1 $2 $3', $n);
}
