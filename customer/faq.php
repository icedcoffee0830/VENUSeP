<?php
/* FAQ — customer help page. Reads the live rates and the discount so the
   answers can never drift from the system: the hostel rates come from
   includes/hostel-rooms.php and the discount from includes/pricing.php.
   Nothing here writes anything. The accordions are native <details>, so the
   page works with no JavaScript at all; GSAP only adds the reveal motion. */
include __DIR__ . '/../includes/hostel-rooms.php';                                      /* $HOSTEL_RATES, $HOSTEL_CR_LABEL */
ob_start(); include __DIR__ . '/../includes/pricing.php'; ob_end_clean();               /* $DISCOUNT_PERCENT (its JS block is not needed here) */
require_once __DIR__ . '/../includes/customer-bookings.php';                            /* $customerContact for the nav chip */

$fqName     = $customerContact['name'];
$fqFirst    = explode(' ', trim($fqName))[0];
$fqParts    = preg_split('/\s+/', trim($fqName));
$fqInitials = strtoupper(substr($fqParts[0], 0, 1) . substr(end($fqParts), 0, 1));
$fqPeso     = function ($n) { return '&#8369;' . number_format((int) $n); };

/* Two frames, one page. Reached from the landing page or its own top nav
   (faq.php) the FAQ is a standalone page with its own nav and footer.
   Reached from the sidebar or the app header (faq.php?in=app) it sits inside
   the account shell — shared header + sidebar, FAQ highlighted, no footer.
   Questions, answers, motion: identical either way. */
$fqApp = isset($_GET['in']) && $_GET['in'] === 'app';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="dark">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP | FAQ</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<?php if ($fqApp): ?>
<!-- the shared header + sidebar use Bootstrap Icons; the app-shell pages size themselves from these two variables -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<style>
  :root { --venusep-sidebar-width: 235px; --venusep-header-height: 58px; }
  .app-main { margin-left: var(--venusep-sidebar-width); padding-top: var(--venusep-header-height); min-height: 100vh; position: relative; z-index: 1; }
  .app-main > main { padding: 64px 0 90px; }
  .app-main .fq-toc { top: 88px; }
  .app-main .fq-section { scroll-margin-top: 90px; }
  @media (max-width: 767.98px) { .app-main { margin-left: 0; } .app-main > main { padding: 44px 0 64px; } }
</style>
<?php endif; ?>
<style>
  /* Same tokens as the landing page, plus the yellow the questions use. */
  :root {
    --ink: #120809; --crimson: #a11626; --crimson-lo: #7d0f1e; --gold: #d9930d;
    --yellow: #ffd166;            /* the questions */
    --paper: #f5e9e7;             /* the answers — soft white, easy on dark red */
    --muted: #d5b8b5; --muted-2: #ac8f8d;
    --line: rgba(255,255,255,.14);
    --ease-soft: cubic-bezier(.16,1,.3,1);
    --font-display: Archivo, Inter, system-ui, sans-serif;
    --font-body: Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
  }
  * { box-sizing: border-box; }
  body { margin: 0; color: var(--paper); font-family: var(--font-body); -webkit-font-smoothing: antialiased; overflow-x: hidden;
         background: linear-gradient(162deg, #7d1120 0%, #3c0c14 40%, #120809 100%) fixed; }
  a { color: var(--yellow); text-decoration: none; }
  a:hover { color: #fff; }
  h1, h2, h3 { font-family: var(--font-display); margin: 0; }
  p { margin: 0; }
  .fq-wrap { max-width: 1240px; margin: 0 auto; padding: 0 28px; }

  /* film grain over the whole page — drawn, not an image file */
  .fq-grain { position: fixed; inset: 0; z-index: 0; pointer-events: none; opacity: .05; mix-blend-mode: overlay; background-size: 180px 180px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='180' height='180' filter='url(%23n)' opacity='0.6'/%3E%3C/svg%3E"); }
  .fq-glow { position: fixed; inset: 0; z-index: 0; pointer-events: none; background:
    radial-gradient(1000px 640px at 92% -4%, rgba(232,62,74,.28), transparent 62%),
    radial-gradient(800px 600px at 4% 100%, rgba(8,3,4,.55), transparent 66%); }
  .fq-icon { fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; flex: none; }

  /* ---------- page ---------- */
  main { position: relative; z-index: 1; padding: 70px 0 90px; }
  .fq-head { max-width: 720px; }
  .fq-eyebrow { display: inline-flex; align-items: center; gap: 9px; padding: 8px 15px; border-radius: 999px; background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.2); font-size: 12.5px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #f7e7e3; }
  .fq-head h1 { margin-top: 22px; font-size: 54px; line-height: 1; letter-spacing: -.03em; font-weight: 800; color: #fff; }
  .fq-head p { margin-top: 18px; font-size: 16px; line-height: 1.72; color: var(--muted); }
  .fq-back { margin-top: 22px; display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 700; }

  .fq-body { margin-top: 56px; display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 56px; }   /* no align-items:start — the side list needs the full row height to stick */
  .fq-toc { position: sticky; top: 108px; align-self: start; display: grid; gap: 4px; align-content: start; }
  .fq-toc a { display: block; padding: 9px 13px; border-radius: 11px; font-size: 13.5px; font-weight: 600; color: var(--muted); }
  .fq-toc a:hover { color: #fff; background: rgba(255,255,255,.07); }
  .fq-toc a.is-here { color: var(--yellow); background: rgba(255,209,102,.1); }
  .fq-toc small { display: block; margin: 0 13px 8px; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--muted-2); }

  .fq-section { scroll-margin-top: 110px; }
  .fq-section + .fq-section { margin-top: 52px; }
  .fq-section h2 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; color: #fff; padding-bottom: 14px; border-bottom: 1px solid var(--line); }
  .fq-section h2 span { display: inline-block; margin-right: 12px; font-size: 12px; font-weight: 700; letter-spacing: .12em; color: var(--gold); vertical-align: middle; }

  /* ---------- accordion: native <details>. Question = bold yellow, answer = soft white ---------- */
  .fq-section details { border-bottom: 1px solid var(--line); }
  .fq-section summary { cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 16px;
    padding: 18px 0; font-family: var(--font-display); font-size: 17px; font-weight: 700; letter-spacing: -.01em; color: var(--yellow);
    transition: color 200ms ease; }
  .fq-section summary::-webkit-details-marker { display: none; }
  .fq-section summary:hover { color: #fff; }
  .fq-section summary .fq-plus { flex: none; width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center;
    background: rgba(255,209,102,.12); border: 1px solid rgba(255,209,102,.28); color: var(--yellow); transition: transform 320ms var(--ease-soft), background 200ms ease; }
  .fq-section details[open] summary .fq-plus { transform: rotate(45deg); background: rgba(255,209,102,.22); }
  .fq-section .fq-a { margin: 0 0 20px; font-size: 15px; line-height: 1.72; color: var(--paper); max-width: 72ch; }
  .fq-section .fq-a strong { color: #fff; font-weight: 700; }
  .fq-section .fq-a + .fq-a { margin-top: -8px; }
  .fq-section .fq-a ul { margin: 8px 0 0; padding-left: 20px; }
  .fq-section .fq-a li { margin-top: 5px; }

  /* ---------- still need help ---------- */
  .fq-help { margin-top: 64px; display: flex; align-items: center; justify-content: space-between; gap: 30px; padding: 30px 34px; border-radius: 22px;
    background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.14); }
  .fq-help h3 { font-size: 19px; font-weight: 700; color: #fff; }
  .fq-help p { margin-top: 7px; max-width: 600px; font-size: 14px; line-height: 1.66; color: var(--muted); }
  .fq-btn { flex: none; display: inline-flex; align-items: center; justify-content: center; gap: 9px; min-height: 50px; padding: 0 24px; border-radius: 14px;
    background: var(--yellow); color: #1d1214; font-family: var(--font-display); font-size: 14.5px; font-weight: 700; transition: transform 100ms ease, background 200ms ease; }
  .fq-btn:hover { background: #fff; color: #1d1214; }
  .fq-btn:active { transform: scale(.97); }

  /* ---------- footer: the landing page's, compact ---------- */
  .fq-footer { position: relative; z-index: 1; border-top: 1px solid var(--line); background: rgba(8,3,4,.35); }
  .fq-footer .fq-wrap { padding: 40px 28px 26px; }
  .fq-footer-grid { display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 36px; padding-bottom: 26px; }
  .fq-footer-logo { height: 20px; width: auto; display: block; filter: invert(1); }
  .fq-footer-about { margin-top: 14px; max-width: 300px; font-size: 12.5px; line-height: 1.66; color: var(--muted); }
  .fq-footer h4 { margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--muted-2); }
  .fq-footer-links { display: grid; gap: 8px; }
  .fq-footer-links a { font-size: 12.5px; color: #e6d7d5; }
  .fq-footer-links a:hover { color: #fff; }
  .fq-footer-bottom { border-top: 1px solid var(--line); padding-top: 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    font-size: 12.5px; color: var(--muted-2); }
  .fq-footer-bottom a { color: var(--muted-2); margin-left: 20px; }
  .fq-footer-bottom a:hover { color: #fff; }

  @media (max-width: 960px) {
    .fq-body { grid-template-columns: 1fr; gap: 28px; }
    .fq-toc { position: static; display: flex; flex-wrap: wrap; gap: 6px; }
    .fq-toc small { display: none; }
    .fq-head h1 { font-size: 40px; }
    .fq-footer-grid { grid-template-columns: 1fr 1fr; }
  }
  @media (max-width: 720px) {
    .fq-wrap { padding: 0 18px; }
    main { padding: 40px 0 64px; }
    .fq-head h1 { font-size: 34px; }
    .fq-section h2 { font-size: 22px; }
    .fq-section summary { font-size: 15.5px; padding: 15px 0; }
    .fq-help { flex-direction: column; align-items: flex-start; padding: 24px 20px; }
    .fq-btn { width: 100%; }
    .fq-footer-grid { grid-template-columns: 1fr 1fr; gap: 24px 16px; }
    .fq-footer-brand { grid-column: 1 / -1; }
    .fq-footer-bottom { display: grid; gap: 10px; }
    .fq-footer-bottom a { margin: 0 16px 0 0; }
  }
</style>
</head>
<body>
<div class="fq-glow" aria-hidden="true"></div>
<div class="fq-grain" aria-hidden="true"></div>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="fq-i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="fq-i-back" viewBox="0 0 24 24"><path d="M19 12H5M11 18l-6-6 6-6"/></symbol>
  <symbol id="fq-i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/></symbol>
  <symbol id="fq-i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
</svg>

<?php if ($fqApp): ?>
<!-- ACCOUNT SHELL: the shared header + sidebar, like every other sidebar page -->
<?php $portal = 'customer'; include __DIR__ . '/../includes/header.php'; ?>
<?php $active = 'FAQ'; include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="app-main">
<?php else: ?>
<!-- STANDALONE: the shared customer nav -->
<?php $navMode = 'solid'; $navHere = 'faq'; include __DIR__ . '/../includes/customer-nav.php'; ?>
<?php endif; ?>

<main>
  <div class="fq-wrap">
    <div class="fq-head" data-reveal>
      <span class="fq-eyebrow"><svg class="fq-icon" width="13" height="13" style="color:var(--gold)"><use href="#fq-i-help"/></svg>Help</span>
      <h1>Frequently asked questions</h1>
      <p>Everything about booking a venue or a hostel bed, the USeP discount, paying, and what happens after you submit.</p>
      <?php if (!$fqApp): ?><a class="fq-back" href="venusep_venue_booking.php"><svg class="fq-icon" width="15" height="15"><use href="#fq-i-back"/></svg>Back to venue booking</a><?php endif; ?>
    </div>

    <div class="fq-body">
      <nav class="fq-toc" data-reveal aria-label="Sections">
        <small>Jump to</small>
        <a href="#booking">Booking a venue</a>
        <a href="#discount">The USeP discount</a>
        <a href="#hostel">Hostel beds</a>
        <a href="#gcash">Paying with GCash</a>
        <a href="#cash">Paying in cash</a>
        <a href="#after">After you book &middot; <?php echo $REFUNDS_ENABLED ? 'refunds' : 'refund policy'; ?></a>
      </nav>

      <div>

        <!-- ==================== BOOKING ==================== -->
        <section class="fq-section" id="booking" data-reveal>
          <h2><span>01</span>Booking a venue</h2>

          <details>
            <summary>How early do I need to book?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Reservations must start <strong>at least 12 hours from the moment you book</strong>, so venue staff have time to prepare. If you pick a date or time that is too soon, the booking page tells you the earliest start you can choose.</p>
          </details>
          <details>
            <summary>Why does it say a date is "not available"?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">That date already has a reservation for the room, and a booked date cannot be reserved again. Pick a different date &mdash; or, in a multi-day booking, keep your range: the booked date is left out automatically.</p>
          </details>
          <details>
            <summary>Can I reserve several days at once?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Yes. Pick a start and end date, then set the hours for each day (or use "Apply Day 1 to all"). If a date in the middle of your range is already booked, it is excluded automatically &mdash; you keep the rest of the days and are <strong>not charged</strong> for the unavailable one.</p>
          </details>
          <details>
            <summary>How is the fee computed?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Each room has a fee per day. Your total is that fee times the number of days you actually book &mdash; excluded days are never counted. If you are USeP-affiliated, the <?php echo (int) $DISCOUNT_PERCENT; ?>% rate is applied once staff have verified your USeP ID. The exact total is always shown before you pay.</p>
          </details>
          <details>
            <summary>What if my group is bigger than the room's capacity?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">You can still submit the request, but the page warns you, and venue staff may reject an over-capacity booking. Consider a larger room &mdash; every card on the venue page shows its capacity.</p>
          </details>
          <details>
            <summary>Why do I need to upload a valid ID?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Staff verify who you are before approving any reservation. Attach a photo of your <strong>USeP ID or any government-issued ID</strong> when you submit. Your booking stays <strong>pending</strong> &mdash; and the payment step stays locked &mdash; until a coordinator approves both your ID and the reservation.</p>
          </details>
          <details>
            <summary>When do I have to pay?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
<?php if ($REFUNDS_ENABLED): /* pre-pay — the refund switch also sets payment timing (DB-DECISIONS #18) */ ?>
            <p class="fq-a">Payment opens only <strong>after your request is approved</strong>. You must pay at least <strong>1 day before your event</strong> &mdash; bookings made closer to the event than that are paid immediately upon approval. A reservation left unpaid past its deadline may be released back to availability.</p>
<?php else: ?>
            <p class="fq-a"><strong>After your event, not before.</strong> Staff approve your ID and reservation first; that holds your slot. Payment opens the day after your last booked day and is due within <strong><?php echo (int) $POSTPAY_GRACE_DAYS; ?> days</strong> &mdash; GCash or cash at the venue office. You cannot pay earlier. A booking not paid within the window is marked <strong>overdue</strong>; it can still be paid, but overdue accounts may not be able to book again until it is settled.</p>
          </details>
          <details>
            <summary>Why do I pay after the event instead of before?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Because bookings are non-refundable. USeP does not hold your money for a venue it might still have to close &mdash; you pay once the event has actually taken place. If USeP has to close or cancel your venue before then, there is nothing to refund: you are simply offered a replacement room or a new date.</p>
<?php endif; ?>
          </details>
        </section>

        <!-- ==================== DISCOUNT ==================== -->
        <section class="fq-section" id="discount" data-reveal>
          <h2><span>02</span>The USeP discount</h2>

          <details>
            <summary>Who gets the <?php echo (int) $DISCOUNT_PERCENT; ?>% off?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">USeP students, faculty and staff. It applies to venue bookings <strong>and</strong> hostel beds. When you book, choose <strong>"USeP-affiliated"</strong> and upload your <strong>USeP ID</strong> &mdash; staff confirm the discount from that ID when they approve your request.</p>
          </details>
          <details>
            <summary>I signed in with a usep.edu.ph email. Do I get it automatically?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Not by itself. A USeP email lets the venue page <strong>preview</strong> your discounted prices, but the discount is only <strong>granted from your USeP ID</strong> at approval. Upload the ID with your booking, even if your email is a USeP one.</p>
          </details>
          <details>
            <summary>Does the rate change?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">The venue office sets the rate. Whatever rate is in force when your booking is approved is the one written onto that booking &mdash; a later change never alters a booking already made.</p>
          </details>
        </section>

        <!-- ==================== HOSTEL ==================== -->
        <section class="fq-section" id="hostel" data-reveal>
          <h2><span>03</span>Hostel beds</h2>

          <details>
            <summary>How is the hostel different from booking a venue?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">You reserve <strong>beds, not rooms</strong>. Each room has six beds; you book one or more, and other guests may book the remaining beds in the same room. Book all six and the room is effectively yours. The price is <strong>per head, per night</strong>.</p>
          </details>
          <details>
            <summary>Communal CR or private CR &mdash; what is the difference?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a"><strong><?php echo htmlspecialchars($HOSTEL_CR_LABEL['communal']); ?></strong> rooms share a bathroom outside the room &mdash; <?php echo $fqPeso($HOSTEL_RATES['communal']); ?> per head, per night. <strong><?php echo htmlspecialchars($HOSTEL_CR_LABEL['private']); ?></strong> rooms have the bathroom inside &mdash; <?php echo $fqPeso($HOSTEL_RATES['private']); ?> per head, per night. The USeP discount applies to both.</p>
          </details>
          <details>
            <summary>How are nights counted?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">From check-in to check-out, <strong>not including the check-out day</strong>. Checking in on the 1st and out on the 4th is three nights &mdash; the 1st, 2nd and 3rd. The venue page shows how many beds are free for tonight; the booking page shows availability for your exact dates.</p>
          </details>
          <details>
            <summary>How do I pay for a hostel bed?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
<?php if ($REFUNDS_ENABLED): ?>
            <p class="fq-a">Hostel payments go through <strong>CEDU</strong>. After staff approve your ID, they obtain a payment order from CEDU for your stay; you then pay &mdash; GCash to the business account, or cash to staff &mdash; and receive the <strong>Official Receipt</strong>. Your bed is held while this happens.</p>
<?php else: ?>
            <p class="fq-a">Hostel payments go through <strong>CEDU</strong>, and &mdash; like venues &mdash; you pay <strong>after your stay</strong>. Staff approve your ID and hold your beds. After you check out they obtain a payment order (POS) from CEDU; payment opens then and is due within <strong><?php echo (int) $POSTPAY_GRACE_DAYS; ?> days of your check-out day</strong> &mdash; GCash to the designated account, or cash to staff &mdash; and you receive the <strong>Official Receipt</strong>.</p>
<?php endif; ?>
          </details>
        </section>

        <!-- ==================== GCASH ==================== -->
        <section class="fq-section" id="gcash" data-reveal>
          <h2><span>04</span>Paying with GCash</h2>

          <details>
            <summary>How do I pay with GCash?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Once staff approve your ID and reservation, the payment step unlocks. Send the amount shown to the GCash account on screen, take a screenshot of your GCash receipt, and upload it. The system reads the receipt on the spot and tells you whether it was accepted.</p>
          </details>
          <details>
            <summary>Why must I send the exact amount?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">The automatic check compares your receipt against the <strong>exact amount shown</strong>. A different amount is not confirmed on the spot &mdash; it goes to a coordinator to look at instead, which delays your booking. A receipt is only rejected outright when <strong>nothing</strong> on it matches, or when the same receipt has already been used.</p>
          </details>
          <details>
            <summary>What is the "Reference to include" (USEP-######)?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">That is your <strong>booking code</strong> in this system. Type it into the message or note field when you send the GCash payment, so staff can match the payment to your booking. It is different from GCash's own 13-digit reference number, which GCash prints on the receipt by itself.</p>
          </details>
          <details>
            <summary>What does the system check on my receipt?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">That the image is a real GCash send-money receipt; the GCash reference number; the <strong>exact amount</strong>; that the money went to the correct account; and the receipt date. It also rejects a receipt that was already submitted before &mdash; same image or same reference number.</p>
          </details>
          <details>
            <summary>My receipt says "a coordinator will check it". Is my booking lost?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">No. Some detail could not be matched automatically &mdash; a blurry screenshot, an amount that differs, an older receipt &mdash; so a staff member verifies it by hand. Your slot is held while they do; you do not need to resubmit.</p>
          </details>
          <details>
            <summary>My receipt was rejected. What do I do?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Read the reasons listed under the result &mdash; usually money sent to a different account, or a receipt that was already used. Fix the issue (send the correct payment if needed), then upload a new screenshot with the reference number and amount clearly readable, <strong>before your payment deadline</strong>.</p>
          </details>
        </section>

        <!-- ==================== CASH ==================== -->
        <section class="fq-section" id="cash" data-reveal>
          <h2><span>05</span>Paying in cash</h2>

          <details>
            <summary>Can I pay in cash instead of GCash?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <!-- The office name and hours mirror CASH_PAY in room-reservation.php — still a
                 placeholder until the venue office confirms them; keep the two in sync. -->
            <p class="fq-a">Yes. Choose <strong>"Cash &mdash; walk-in"</strong> at the payment step. Your reservation is submitted and held, and you pay in person at the <strong>USeP Cashier &mdash; Venue Reservations Window</strong> on campus (Monday to Friday, 8:00 AM &ndash; 5:00 PM). Quote your booking code and bring a valid ID. The booking is confirmed once the cashier records your payment<?php echo $REFUNDS_ENABLED ? ' &mdash; pay before your event date, or the slot may be released.' : '. Under the post-pay policy you do this after the event, within the ' . (int) $POSTPAY_GRACE_DAYS . '-day window.'; ?></p>
          </details>
        </section>

        <!-- ==================== AFTER / REFUNDS ==================== -->
        <section class="fq-section" id="after" data-reveal>
          <h2><span>06</span>After you book &middot; <?php echo $REFUNDS_ENABLED ? 'refunds' : 'refund policy'; ?></h2>

          <details>
            <summary>What happens after I submit my reservation?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">Two stages. First, staff review your <strong>ID and reservation</strong> &mdash; until they approve, your request is pending and payment is locked. Once approved, you pay (GCash or cash), and staff verify the payment itself &mdash; the GCash reference in the business account, or the cashier record &mdash; before the booking is finally confirmed. You can follow every step in <a href="booking-history.php">your booking history</a>.</p>
          </details>
<?php if ($REFUNDS_ENABLED): /* the admin refund switch — includes/refund-policy.php */ ?>
          <details>
            <summary>Who can ask for a refund?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">A booking that is <strong>approved</strong>, <strong>paid</strong>, and whose date <strong>has not been held yet</strong>. Those bookings show a <strong>Request refund</strong> link in your booking history. Unpaid bookings and past events cannot be refunded.</p>
          </details>
          <details>
            <summary>What do I need to submit?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">A reason, plus three documents: the <strong>system transaction receipt</strong>, your <strong>proof of payment</strong> (the GCash receipt, or the cashier receipt if you paid in cash), and the <strong>Official Receipt</strong>. You can file the request before the Official Receipt is ready &mdash; staff simply cannot pay the refund until they have it. If you paid by GCash, you also tell them which GCash number to send the refund to.</p>
          </details>
          <details>
            <summary>Does requesting a refund cancel my booking?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a"><strong>No.</strong> The booking stays yours while staff review the request. You can <strong>withdraw</strong> the request at any point before they decide, and keep the booking. Your date is only released once the refund has actually been completed.</p>
          </details>
          <details>
            <summary>What can staff decide?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">One of three outcomes, each with a note you can read in your booking history:</p>
            <div class="fq-a"><ul>
              <li><strong>Refunded</strong> &mdash; the money has been sent back; the proof (reference, amount, receipt) is attached to your booking.</li>
              <li><strong>Needs a fix</strong> &mdash; something is missing or unclear; correct it and resubmit.</li>
              <li><strong>Denied</strong> &mdash; with the reason. A denial is final, and the booking stays yours.</li>
            </ul></div>
          </details>
          <details>
            <summary>How is the money returned?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">The same way you paid. A GCash payment is refunded <strong>to GCash</strong>, to the number you gave in the request. A cash payment is refunded <strong>at the venue office</strong>; staff will tell you when it is ready to collect.</p>
          </details>
<?php else: ?>
          <details>
            <summary>Can I get a refund?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a"><strong>No.</strong> USeP does not give refunds: every booking is <strong>final and non-refundable</strong> once paid. Before you submit a booking you are asked to confirm that you understand this, so please check your date, room and details carefully before you pay.</p>
          </details>
          <details>
            <summary>I booked while refunds were still offered. Can I still ask for one?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a"><strong>Yes.</strong> A booking keeps the policy it was made under. If yours was made while refunds were offered, it still shows a <strong>Request refund</strong> link in <a href="booking-history.php">your booking history</a> while it is paid and the date has not been held yet &mdash; the request page lists the documents you need.</p>
          </details>
<?php endif; ?>
          <details>
            <summary>What if USeP closes or cancels my venue?<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>
            <p class="fq-a">If USeP has to close a room you have booked &mdash; for maintenance or any other reason &mdash; the venue office will offer you a <strong>replacement room</strong> or a <strong>new date</strong>. If you cannot accept either, your payment is returned in full. This does not depend on the refund policy.</p>
          </details>
        </section>

        <div class="fq-help" data-reveal>
          <div>
            <h3>Still need help?</h3>
            <p>Reach the venue coordination office through your USeP account email, or visit the USeP Cashier &mdash; Venue Reservations Window during office hours. Your booking history shows where every request stands.</p>
          </div>
          <a class="fq-btn" href="booking-history.php">Open my bookings<svg class="fq-icon" width="15" height="15"><use href="#fq-i-arrow"/></svg></a>
        </div>

      </div>
    </div>
  </div>
</main>
<?php if ($fqApp): ?>
</div><!-- /.app-main -->
<?php else: ?>

<footer class="fq-footer">
  <div class="fq-wrap">
    <div class="fq-footer-grid">
      <div class="fq-footer-brand">
        <img class="fq-footer-logo" src="../logo/Logo Header 3.png" alt="VENUSeP">
        <p class="fq-footer-about">Venue and hostel booking for the University of Southeastern Philippines, Tagum&ndash;Mabini Campus. Run by the campus venue office.</p>
      </div>
      <div>
        <h4>Book</h4>
        <div class="fq-footer-links">
          <a href="venusep_venue_booking.php#venue-listings">Browse venues</a>
          <a href="venusep_venue_booking.php#hostel-listings">Hostel beds</a>
          <a href="calendar.php">Availability calendar</a>
          <a href="booking-history.php">My bookings</a>
        </div>
      </div>
      <div>
        <h4>Help</h4>
        <div class="fq-footer-links">
          <a href="#gcash">How to pay by GCash</a>
          <a href="#after"><?php echo $REFUNDS_ENABLED ? 'Request a refund' : 'Refund policy'; ?></a>
          <a href="#discount">USeP discount rules</a>
          <a href="transaction-history.php">Transaction history</a>
        </div>
      </div>
    </div>
    <div class="fq-footer-bottom">
      <span>&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</span>
      <span><a href="#after">Booking &amp; cancellation policy</a></span>
    </div>
  </div>
</footer>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<script>
  /* Smooth in-page scrolling (JS, not CSS scroll-behavior — that breaks
     ScrollTrigger's measurements on zoom) and the "you are here" mark in the
     side list. Works without GSAP. */
  (function () {
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
      var id = a.getAttribute('href');
      if (id.length < 2) return;
      a.addEventListener('click', function (e) {
        var t = document.querySelector(id);
        if (!t) return;
        e.preventDefault();
        t.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
      });
    });
    var links = document.querySelectorAll('.fq-toc a');
    var sections = Array.prototype.map.call(links, function (a) { return document.querySelector(a.getAttribute('href')); });
    function mark() {
      var y = window.scrollY + 140, current = 0;
      sections.forEach(function (s, i) { if (s && s.offsetTop <= y) current = i; });
      links.forEach(function (a, i) { a.classList.toggle('is-here', i === current); });
    }
    window.addEventListener('scroll', mark, { passive: true });
    mark();
  })();

  /* Reveal motion — the landing page's rule: rise + fade in when a block
     scrolls into view, out when it leaves; re-evaluated after any refresh
     (zoom, resize). No GSAP or reduced-motion = everything simply visible. */
  (function () {
    if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    gsap.registerPlugin(ScrollTrigger);
    ScrollTrigger.config({ ignoreMobileResize: true });
    document.querySelectorAll('[data-reveal]').forEach(function (el, i) {
      gsap.set(el, { opacity: 0, y: 24 });
      function paint(on) {
        gsap.to(el, { opacity: on ? 1 : 0, y: on ? 0 : 24, duration: on ? 1 : 0.4, ease: 'power3.out', overwrite: 'auto',
                      delay: on && i < 2 ? i * 0.12 : 0, clearProps: on ? 'transform' : '' });
      }
      /* the sticky TOC rides along with the whole FAQ body, so it stays until the BODY ends — not its own slot */
      var sticky = el.classList.contains('fq-toc') ? el.parentElement : el;
      ScrollTrigger.create({ trigger: el, start: 'top 90%', endTrigger: sticky, end: 'bottom top',
        onToggle: function (s) { paint(s.isActive); }, onRefresh: function (s) { paint(s.isActive); } });
    });
  })();
</script>
</body>
</html>
