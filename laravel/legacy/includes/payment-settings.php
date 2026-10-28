<?php
/* =====================================================================
   PAYMENT SETTINGS — the ONE source of the GCash account per venue,
   read from the `gcash_accounts` table.

   Included by:
     admin/payment-settings.php         (the screen that edits these)
     customer/room-reservation.php      (Bahay Alumni / USeP Venues)
     customer/hostel-reservation.php    (USeP Hostel)

   WHY ONE SOURCE: the receipt checker verifies that money landed in the
   RIGHT account, and the payment screen tells the customer where to send
   it. If those two ever read different values, the customer pays the
   number they were shown and the checker rejects it as "receiver
   mismatch" — a bug that looks like OCR failing and is nearly impossible
   to spot. Both read from here, so they cannot disagree.

   ONE ACCOUNT PER VENUE (DB-DECISIONS #5). The table enforces it: a
   generated `active_venue_id` plus a UNIQUE means at most one active row
   per venue, while superseded accounts stay as history (is_active = 0 or
   valid_until set). Changing an account is therefore a new row, not an
   overwrite — a receipt paid to last year's number can still be explained.

   Bahay Alumni and USeP Venues are SEPARATE accounts, so a receipt paid
   to Bahay Alumni is correctly rejected on a USeP Venues booking. That is
   the intended behaviour, not a regression. The hostel's account is a
   designated STAFF account, not a business one: the guest pays staff, who
   cash out and hand it to the University Cashier, who issues the OR.

   FAILS LOUD. There is no safe fallback for a payment destination — an
   invented or stale number means a customer sends real money to the wrong
   place. Better to refuse to draw the page.
   ===================================================================== */

require_once __DIR__ . '/db.php';

/* Keyed by venue NAME, because that is what a room carries. Shape is
   ['name','number','note'] — the booking pages json_encode this straight
   into the browser and the checker reads .name / .number off it. */
$gcAccounts = [];
/* Row + venue ids, kept OUT of $gcAccounts so the payload sent to the
   browser stays exactly what it was. The admin screen uses these to edit. */
$gcAccountIds = [];

$gcStmt = venusep_db_or_fail()->query(
  "SELECT g.id, g.venue_id, v.name AS venue_name, g.account_name, g.mobile_number, g.note
     FROM gcash_accounts g
     JOIN venues v ON v.id = g.venue_id
    WHERE g.is_active = 1 AND g.valid_until IS NULL
    ORDER BY g.venue_id"
);
foreach ($gcStmt as $gcRow) {
  $gcAccounts[$gcRow['venue_name']] = [
    'name'   => $gcRow['account_name'],
    'number' => $gcRow['mobile_number'],
    'note'   => (string) $gcRow['note'],
  ];
  $gcAccountIds[$gcRow['venue_name']] = [
    'account_id' => (int) $gcRow['id'],
    'venue_id'   => (int) $gcRow['venue_id'],
  ];
}

/* The account a booking's money should land in, by venue. Falls back to null
   rather than to some other venue's account: verifying against the wrong
   account is worse than refusing to verify, because it would silently accept
   money paid to the wrong place. A venue with no active account returns null
   and the payment screen must refuse GCash for it. */
function gcAccountFor(array $accounts, $venue) {
  return isset($accounts[$venue]) ? $accounts[$venue] : null;
}

/* Pretty 0995 194 1234 — display only; the checker always compares digits. */
function gcFormatNumber($n) {
  return preg_replace('/^(\d{4})(\d{3})(\d{4})$/', '$1 $2 $3', $n);
}
