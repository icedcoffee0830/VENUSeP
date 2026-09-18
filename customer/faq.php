<?php require_once __DIR__ . '/../includes/auth.php'; venusep_session_start(); /* public page — the session only tells the header whether someone is logged in */ ?>
<?php
/* FAQ — customer help page. Reads the live rates and the discount so the
   answers can never drift from the system: the hostel rates come from
   includes/hostel-rooms.php and the discount from includes/pricing.php.
   Nothing here writes anything. The accordions are native <details>, so the
   page works with no JavaScript at all; GSAP only adds the reveal motion. */
require_once __DIR__ . '/../includes/customer-bookings.php';
/* EVERY question comes from the database (includes/faqs.php): the built-in
   ones and the ones admins added on admin/faq-management.php. Only the rows
   for the CURRENT refund policy, only active ones. */
require_once __DIR__ . '/../includes/faqs.php';
$fqRows = faqs_by_section(false, (bool) $REFUNDS_ENABLED);
/* the <details> list for one section; the section heading stays in the page */
function fq_section_html($key)
{
    global $fqRows;
    if (!faq_sections()) {
        return '<p class="fq-a fq-unavailable">The FAQ is temporarily unavailable. Please try again in a moment.</p>';
    }
    $html = '';
    foreach ((isset($fqRows[$key]) ? $fqRows[$key] : []) as $r) {
        $html .= '<details><summary>' . faq_question_html($r['question'])
               . '<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>'
               . faq_answer_html($r['answer']) . '</details>';
    }
    return $html;
}                            /* $customerContact for the nav chip */

$fqName     = $customerContact['name'];
$fqFirst    = explode(' ', trim($fqName))[0];
$fqParts    = preg_split('/\s+/', trim($fqName));
$fqInitials = strtoupper(substr($fqParts[0], 0, 1) . substr(end($fqParts), 0, 1));

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
<?php echo fq_section_html('booking'); ?>
        </section>

        <!-- ==================== DISCOUNT ==================== -->
        <section class="fq-section" id="discount" data-reveal>
          <h2><span>02</span>The USeP discount</h2>
<?php echo fq_section_html('discount'); ?>
        </section>

        <!-- ==================== HOSTEL ==================== -->
        <section class="fq-section" id="hostel" data-reveal>
          <h2><span>03</span>Hostel beds</h2>
<?php echo fq_section_html('hostel'); ?>
        </section>

        <!-- ==================== GCASH ==================== -->
        <section class="fq-section" id="gcash" data-reveal>
          <h2><span>04</span>Paying with GCash</h2>
<?php echo fq_section_html('gcash'); ?>
        </section>

        <!-- ==================== CASH ==================== -->
        <section class="fq-section" id="cash" data-reveal>
          <h2><span>05</span>Paying in cash</h2>
<?php echo fq_section_html('cash'); ?>
        </section>

        <!-- ==================== AFTER / REFUNDS ==================== -->
        <section class="fq-section" id="after" data-reveal>
          <h2><span>06</span>After you book &middot; <?php echo $REFUNDS_ENABLED ? 'refunds' : 'refund policy'; ?></h2>
<?php echo fq_section_html('after'); ?>
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
