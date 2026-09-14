<?php
/* =====================================================================
   PRICING & THE USeP DISCOUNT — [SIM] the one source for the rate.
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
$DISCOUNT_PERCENT = 20;            // system_settings.discount_percent — the DEFAULT

/* [SIM] Admin override. Payment Settings lets an admin change the rate; with no
   database the value has to live in the browser, and it must reach BOTH the
   PHP-rendered listing and the JS booking pages — localStorage cannot reach PHP,
   a cookie can. Validated hard: only 0–100 as plain digits is honoured, anything
   else falls back to the default. Per-browser, like every [SIM] mechanism, and
   deleted at DB time when this becomes a real settings row. */
if (isset($_COOKIE['venusep_discount_percent']) && is_string($_COOKIE['venusep_discount_percent'])) {
    $ck = $_COOKIE['venusep_discount_percent'];
    if (preg_match('/^\d{1,3}$/', $ck) && (int) $ck <= 100) $DISCOUNT_PERCENT = (int) $ck;
}
$USEP_MAIL_DOMAIN = 'usep.edu.ph';

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
function usep_discounted($price) {
    global $DISCOUNT_PERCENT;
    return $price - round($price * $DISCOUNT_PERCENT) / 100;
}
/* "~~₱5,000~~ ₱4,000" for a USeP account, plain "₱5,000" otherwise. Always
   carries the condition, because this is a PREVIEW — the ID decides it. */
function usep_price_html($price, $isUsep, $unit = '') {
    $full = '₱' . number_format($price);
    if (!$isUsep) return $full . $unit;
    return '<s style="color:#a5a19a;font-weight:400">' . $full . '</s> <strong style="color:#1c7a4f">₱' . number_format(usep_discounted($price)) . '</strong>' . $unit
         . ' <span style="font-size:11px;color:#1c7a4f">· USeP price, with a verified ID</span>';
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
     until then. Returns whole centavo-safe pesos. */
  function priceWithDiscount(roomPrice, affiliated) {
    const pct = affiliated === true ? DISCOUNT_PERCENT : 0;
    const discount = Math.round(roomPrice * pct) / 100;
    return { roomPrice: roomPrice, discountPercent: pct, discountAmount: discount, total: roomPrice - discount };
  }
</script>
