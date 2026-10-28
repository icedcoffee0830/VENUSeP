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
/* THE LIVE RATE — system_settings.discount_percent, edited on admin Venue
   Management and written by admin/discount-save.php (the only writer).

   This used to be a per-browser cookie, because there was no database to keep
   it in: the value had to reach BOTH the PHP-rendered listing and the JS
   booking pages, and localStorage cannot reach PHP. That is over. One row, one
   value, the same for every visitor on every machine.

   Validated on the way OUT as well as in: a row that somehow holds nonsense
   falls back to 20 rather than pricing a booking at 0% or 900%. */
require_once __DIR__ . '/db.php';

function venusep_discount_percent() {
    static $pct = null;
    if ($pct !== null) {
        return $pct;
    }
    $pct = 20;                         // the default, if the row is missing or unreadable
    $pdo = venusep_db();
    if ($pdo === null) {
        return $pct;                   // priced pages call venusep_db_or_fail() themselves
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'discount_percent' LIMIT 1");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        if ($v !== false && preg_match('/^\d{1,3}$/', (string) $v) && (int) $v <= 100) {
            $pct = (int) $v;
        }
    } catch (PDOException $e) {
        // keep the default
    }
    return $pct;
}

$DISCOUNT_PERCENT = venusep_discount_percent();
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
/* "~~₱5,000~~ ₱4,000 per day · 20% off" for a USeP account, plain "₱5,000"
   otherwise. A preview — staff still confirm the discount from the ID at
   approval — but the listing keeps it to the short "20% off" tag. */
function usep_price_html($price, $isUsep, $unit = '') {
    global $DISCOUNT_PERCENT;
    $full = '₱' . number_format($price);
    if (!$isUsep) return $full . $unit;
    return '<s style="color:#a5a19a;font-weight:400">' . $full . '</s> <strong style="color:#1c7a4f">₱' . number_format(usep_discounted($price)) . '</strong>' . $unit
         . ' <span style="font-size:11px;color:#1c7a4f;white-space:nowrap">· ' . (int) $DISCOUNT_PERCENT . '% off</span>';
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
