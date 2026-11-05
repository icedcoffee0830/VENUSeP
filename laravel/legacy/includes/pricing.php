<?php
/* =====================================================================
   PRICING & THE USeP DISCOUNT — the one source for the rate.
   Mirrors system_settings.discount_percent, which is where this lives
   once the database exists (DB-DECISIONS #2: percentage-based, default
   20%, dynamic, staff-editable).

   Included by:
     customer/room-reservation.php    (venue total)
     customer/hostel-reservation.php  (beds x nights total)
     admin/booking-request.php        (staff decide, price is locked)

   THE RULE (agreed 2026-09-09, refining DB-DECISIONS #3):
     A booking is discounted only when the customer uploads a USeP ID AND
     STAFF VERIFY IT. A usep.edu.ph account is shown to staff as supporting
     evidence — it NEVER grants the discount on its own, because nothing
     verifies the address at registration. Anyone could sign up as
     someone@usep.edu.ph and take 20% off every booking forever.
     ⚠️ If email verification is ever added, a VERIFIED address may become a
     fast path. Until then, evidence only. See DB-TRANSITION.md.

   THE RATE IS SNAPSHOTTED AT BOOKING TIME. Changing it later must never
   rewrite a booking that was already quoted (DB-DECISIONS #2), so the
   booking stores the % it got, not a pointer to the live value.

   An ID is required for EVERY booking regardless (PROJECT-HANDOFF 4.7), so
   asking a USeP customer for their USeP ID instead of a driver's licence
   costs them nothing — which is what makes proof affordable here.
   ===================================================================== */
/* THE LIVE RATE — each office's own (venues.discount_percent, Office Settings). A page
   about one room gets that office's; a page about none, the highest.

   This used to be a per-browser cookie, because there was no database to keep
   it in: the value had to reach BOTH the PHP-rendered listing and the JS
   booking pages, and localStorage cannot reach PHP. That is over. One row, one
   value, the same for every visitor on every machine.

   Validated on the way OUT as well as in: a row that somehow holds nonsense
   falls back to 20 rather than pricing a booking at 0% or 900%. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/offices.php';

function venusep_discount_percent() {
    $rates = array_column(office_all(), 'discount');
    return office_page()['discount'] ?? ($rates ? max($rates) : 20);
}

$DISCOUNT_PERCENT = venusep_discount_percent();
$USEP_MAIL_DOMAIN = 'usep.edu.ph';
/* Laravel: legacy() loads this file inside a function, so publish the two
   variables the helpers below read with `global`. */
$GLOBALS['DISCOUNT_PERCENT'] = $DISCOUNT_PERCENT;
$GLOBALS['USEP_MAIL_DOMAIN'] = $USEP_MAIL_DOMAIN;

/* PHP twins of the JS helpers below. The listing page renders its prices in
   PHP, so it needs the same rule server-side — and it must be THE SAME rule,
   or the listing and the booking page would disagree about who is USeP. */
function usep_is_account($email) {
    global $USEP_MAIL_DOMAIN;
    $parts = explode('@', strtolower(trim((string) $email)));
    if (count($parts) !== 2) return false;
    $d = $parts[1];
    return $d === $USEP_MAIL_DOMAIN || substr($d, -strlen('.' . $USEP_MAIL_DOMAIN)) === '.' . $USEP_MAIL_DOMAIN;
}
/* The discounted price rounds UP to the whole peso, as approval stores it
   (sp_approve_booking, migration 18); cents first, so 800.0000001 stays 800. */
function usep_discounted($price, $pct = null) {
    global $DISCOUNT_PERCENT;
    $pct = $pct ?? $DISCOUNT_PERCENT;
    return $pct > 0 ? ceil(round($price - round($price * $pct) / 100, 2)) : $price;
}
/* "~~₱5,000~~ ₱4,000 per day · 20% off" for a USeP account, plain "₱5,000"
   otherwise. A preview — staff still confirm the discount from the ID at
   approval — but the listing keeps it to the short "20% off" tag. */
/* "1,500" for a whole-peso amount, "1,500.50" when it has centavos — never
   rounded to a peso the booking will not charge. */
function usep_peso($n) {
    return number_format($n, fmod((float) $n, 1.0) == 0.0 ? 0 : 2);
}
function usep_price_html($price, $isUsep, $unit = '', $pct = null) {
    global $DISCOUNT_PERCENT;
    $pct = $pct ?? $DISCOUNT_PERCENT;
    $full = '₱' . usep_peso($price);
    if (!$isUsep) return $full . $unit;
    return '<s style="color:#a5a19a;font-weight:400">' . $full . '</s> <strong style="color:#1c7a4f">₱' . usep_peso(usep_discounted($price, $pct)) . '</strong>' . $unit
         . ' <span style="font-size:11px;color:#1c7a4f;white-space:nowrap">· ' . (int) $pct . '% off</span>';
}
?>
<script>
  /* The live rate, snapshotted onto a booking the moment it is made. */
  const DISCOUNT_PERCENT = <?php echo (int) $DISCOUNT_PERCENT; ?>;

  /* Is this address a USeP account? The domain must be EXACTLY usep.edu.ph
     or a subdomain of it. A plain "endsWith('.usep.edu.ph')" is wrong twice
     over: it rejects jmdelacruz@usep.edu.ph (no leading dot) and it would
     accept nothing useful, while "endsWith('usep.edu.ph')" would wave through
     notusep.edu.ph. Evidence for staff only — never a grant. */
  function isUsepAccount(email) {
    const at = String(email || '').toLowerCase().split('@');
    if (at.length !== 2) return false;
    const d = at[1];
    return d === '<?php echo $USEP_MAIL_DOMAIN; ?>' || d.endsWith('.<?php echo $USEP_MAIL_DOMAIN; ?>');
  }

  /* Pricing for one booking. `affiliated` is the customer's CLAIM; staff
     confirm it later, which is why the UI calls the discount provisional
     until then. The discounted total rounds UP to the whole peso and the discount
     is the rest, as approval stores it (sp_approve_booking, migration 18). */
  function priceWithDiscount(roomPrice, affiliated) {
    const pct = affiliated === true ? DISCOUNT_PERCENT : 0;
    const total = pct > 0 ? Math.ceil(Math.round((roomPrice - Math.round(roomPrice * pct) / 100) * 100) / 100) : roomPrice;
    return { roomPrice: roomPrice, discountPercent: pct, discountAmount: roomPrice - total, total: total };
  }
</script>
