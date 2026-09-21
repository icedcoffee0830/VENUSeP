<?php
/* =====================================================================
   SHARED CUSTOMER NAV — the ONE top bar the browse-mode customer pages use
   (landing page, FAQ, the two booking pages). The account-mode pages
   (booking history, calendar, profile, refund, transactions) use the
   sidebar shell in header.php + sidebar.php instead.

   Usage (from a page inside customer/):
       <?php $navMode = 'solid'; $navHere = 'faq'; include __DIR__ . '/../includes/customer-nav.php'; ?>

   $navMode  'solid'  (default) — the crimson bar from the first pixel.
             'hero'             — transparent over a dark hero, turns solid
                                  once the page scrolls past 90px (landing).
   $navHere  which link is the current page: a venue slug ('venue-bahay-alumni',
             'venue-usep-venues' ...) | 'hostel' | 'how' | 'bookings' | 'faq' |
             '' (default). Marked with the underline.
   $navSelf  the page's own file (defaults to the including script). On the
             landing page the Venues / Hostel / How it works links are in-page
             anchors; on every other page they point back to the landing page.

   Links and the logo path are relative to customer/. Reads the customer
   record for the name chip. Edit the menu HERE and every page updates.
   ===================================================================== */
require_once __DIR__ . '/auth.php';                                    /* customer_logged_in() — guest or customer? */
require_once __DIR__ . '/customer-bookings.php';                       /* $customerContact */
include_once __DIR__ . '/venue-rooms.php';                             /* $venueRooms — one nav link per venue */
include_once __DIR__ . '/hostel-rooms.php';                            /* $HOSTEL_VENUE — its name, from the one source */
$navMode = isset($navMode) ? $navMode : 'solid';
$navHere = isset($navHere) ? $navHere : '';
$navSelf = isset($navSelf) ? $navSelf : basename($_SERVER['SCRIPT_NAME']);   /* the page including this bar */
$navAuthed  = customer_logged_in();
$navCounter = counter_mode();     /* staff booking for a walk-in — see the block below */
/* The chip shows WHO LOGGED IN (the session, written by customer-login.php).
   Every customer page reads this same session row now. This chip was once the
   ONLY part that did: the rest showed a hard-coded demo customer, so one screen
   could name two different people. */
$navName  = $navAuthed && !empty($_SESSION['customer_name']) ? $_SESSION['customer_name'] : $customerContact['name'];
$navFirst = explode(' ', trim($navName))[0];
$navParts = preg_split('/\s+/', trim($navName));
$navInit  = strtoupper(substr($navParts[0], 0, 1) . substr(end($navParts), 0, 1));
$navHome  = $navSelf === 'venusep_venue_booking.php' ? '' : 'venusep_venue_booking.php';   /* anchors stay in-page on the landing */
$navLinks = [];
foreach (array_values(array_unique(array_column($venueRooms, 'venue'))) as $navVenue) {   /* Bahay Alumni, USeP Venues, ... */
  $navSlug = 'venue-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($navVenue));           /* -> the landing page's group anchor */
  $navLinks[] = [$navSlug, $navHome . '#' . $navSlug, $navVenue];
}
$navLinks = array_merge($navLinks, [
  ['hostel',   $navHome . '#hostel-listings', $HOSTEL_VENUE],
  ['how',      $navHome . '#how',             'How it works'],
  ['bookings', 'booking-history.php',         'My bookings'],
  ['faq',      'faq.php',                     'FAQ'],
]);
if (!$navAuthed) {                                                      /* a guest has no bookings to show */
  $navLinks = array_values(array_filter($navLinks, function ($l) { return $l[0] !== 'bookings'; }));
}
?>
<style>
  /* nav styles live HERE so every page gets them with the include */
  .cn-nav { position: fixed; top: 0; left: 0; right: 0; z-index: 60; border-bottom: 1px solid transparent;
    transition: background 480ms cubic-bezier(.16,1,.3,1), border-color 480ms cubic-bezier(.16,1,.3,1), box-shadow 480ms cubic-bezier(.16,1,.3,1); }
  .cn-nav.solid { background: linear-gradient(100deg, rgba(138,18,34,.97) 0%, rgba(58,12,20,.97) 52%, rgba(18,8,10,.97) 100%);
    border-bottom-color: rgba(255,255,255,.14); box-shadow: 0 10px 30px rgba(10,4,5,.3); backdrop-filter: blur(12px); }
  .cn-nav.is-static { position: sticky; }            /* solid pages: the bar scrolls with the page flow, not over it */
  .cn-wrap { max-width: 1240px; margin: 0 auto; padding: 0 28px; height: 84px; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
  .cn-nav.is-static .cn-wrap { height: 76px; }
  .cn-logo img { height: 25px; width: auto; display: block; filter: invert(1); }
  .cn-links { display: flex; align-items: center; gap: 28px; }
  .cn-links a { position: relative; font-size: 14px; font-weight: 600; letter-spacing: .01em; color: #fff; text-decoration: none; }
  .cn-links a::after { content: ""; position: absolute; left: 0; right: 0; bottom: -7px; height: 2px;
    background: currentColor; transform: scaleX(0); transform-origin: left; transition: transform 240ms cubic-bezier(.22,.61,.36,1); }
  .cn-links a:hover::after, .cn-links a.is-here::after { transform: scaleX(1); }
  .cn-links a.is-here { color: #ffd166; }
  .cn-user { display: inline-flex; align-items: center; gap: 11px; padding: 7px 17px 7px 7px; border-radius: 999px;
    border: 1px solid rgba(255,255,255,.32); background: rgba(255,255,255,.1); min-height: 48px; color: #fff; text-decoration: none; }
  .cn-user:hover { color: #fff; background: rgba(255,255,255,.18); }
  .cn-avatar { width: 34px; height: 34px; border-radius: 50%; background: #fff; color: #1d1214;
    display: grid; place-items: center; font-size: 12.5px; font-weight: 700; }
  .cn-name { font-size: 13.5px; font-weight: 700; }
  /* guest: Log in (text) + Sign up (pill) where the chip would be; customer: chip + Log out */
  .cn-auth { display: inline-flex; align-items: center; gap: 14px; }
  .cn-login { font-size: 14px; font-weight: 600; color: #fff; text-decoration: none; }
  .cn-login:hover { color: #ffd166; }
  .cn-signup { display: inline-flex; align-items: center; min-height: 42px; padding: 0 20px; border-radius: 999px; background: #fff; color: #a11626; font-size: 14px; font-weight: 700; text-decoration: none; transition: background 200ms, color 200ms; }
  .cn-signup:hover { background: #ffd166; color: #120809; }
  .cn-logout { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.75); text-decoration: none; }
  .cn-logout:hover { color: #ffd166; }
  .cn-burger { display: none; width: 44px; height: 44px; place-items: center; border-radius: 12px;
    border: 1px solid rgba(255,255,255,.3); background: rgba(255,255,255,.1); cursor: pointer; }
  .cn-menu { display: none; }
  @media (max-width: 720px) {
    .cn-wrap, .cn-nav.is-static .cn-wrap { padding: 0 18px; height: 72px; }
    .cn-links, .cn-name, .cn-logout { display: none; }
    /* guest on a phone: "Log in" (outlined) beside "Sign up" (white pill), so
       the login is one tap without opening the menu */
    .cn-auth { gap: 8px; }
    .cn-login { display: inline-flex; align-items: center; min-height: 36px; padding: 0 14px; border-radius: 999px; border: 1px solid rgba(255,255,255,.55); font-size: 13px; }
    .cn-signup { min-height: 36px; padding: 0 14px; font-size: 13px; }
    .cn-burger { display: grid; }
    .cn-user { padding: 0; border: 0; background: transparent; min-height: 0; }
    .cn-logo img { height: 21px; }
    /* the phone menu: the same links, dropped under the bar */
    .cn-menu.open { display: grid; gap: 4px; padding: 10px 18px 16px; background: rgba(18,8,10,.98); border-top: 1px solid rgba(255,255,255,.1); }
    .cn-menu a { display: block; padding: 12px 14px; border-radius: 12px; font-size: 15px; font-weight: 600; color: #fff; text-decoration: none; }
    .cn-menu a:hover, .cn-menu a.is-here { background: rgba(255,255,255,.1); }
  }
</style>
<?php
/* DEMO MODE banner. The landing page and the FAQ are PUBLIC and use this bar
   rather than includes/header.php, so the warning has to be repeated here —
   otherwise the two pages a visitor is most likely to arrive on would be the
   only ones not saying the system is in a showcase. */
require_once __DIR__ . '/demo-mode.php';
echo demo_banner_html();
?>
<header class="cn-nav<?php echo $navMode === 'hero' ? '' : ' solid is-static'; ?>" id="cnNav">
  <div class="cn-wrap">
    <a class="cn-logo" href="venusep_venue_booking.php" title="Back to the landing page"><img src="../logo/Logo Header 3.png" alt="VENUSeP"></a>
    <nav class="cn-links">
<?php foreach ($navLinks as $l): ?>
      <a href="<?php echo htmlspecialchars($l[1]); ?>"<?php echo $l[0] === $navHere ? ' class="is-here" aria-current="page"' : ''; ?>><?php echo $l[2]; ?></a>
<?php endforeach; ?>
    </nav>
    <div style="display:flex;align-items:center;gap:10px">
<?php if ($navCounter): ?>
      <!-- COUNTER MODE: no Log in / Sign up. This screen runs on the STAFF
           session, and a customer signing in here would replace it — the app
           keeps one account_type per session, so the staff member at the next
           monitor would be signed out mid-booking. The counter bar on the page
           says whose screen this is. -->
      <span class="cn-name" style="opacity:.8">Counter</span>
<?php elseif ($navAuthed): ?>
      <a class="cn-user" href="customer-profile.php" title="Your profile">
        <span class="cn-avatar"><?php echo htmlspecialchars($navInit); ?></span>
        <span class="cn-name"><?php echo htmlspecialchars($navFirst); ?></span>
      </a>
      <a class="cn-logout" href="logout.php">Log out</a>
<?php else: ?>
      <span class="cn-auth">
        <a class="cn-login" href="customer-login.php">Log in</a>
        <a class="cn-signup" href="customer-register.php">Sign up</a>
      </span>
<?php endif; ?>
      <button class="cn-burger" type="button" aria-label="Menu" aria-controls="cnMenu" aria-expanded="false" onclick="var m=document.getElementById('cnMenu');m.classList.toggle('open');this.setAttribute('aria-expanded',m.classList.contains('open'))">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
    </div>
  </div>
  <nav class="cn-menu" id="cnMenu">
<?php foreach ($navLinks as $l): ?>
    <a href="<?php echo htmlspecialchars($l[1]); ?>"<?php echo $l[0] === $navHere ? ' class="is-here"' : ''; ?>><?php echo $l[2]; ?></a>
<?php endforeach; ?>
<?php if ($navCounter): /* same reason as the bar above: no way to sign a customer in here */ ?>
<?php elseif ($navAuthed): ?>
    <a href="customer-profile.php">Profile</a>
    <a href="logout.php">Log out</a>
<?php else: ?>
    <a href="customer-login.php">Log in</a>
    <a href="customer-register.php">Sign up</a>
<?php endif; ?>
  </nav>
</header>
<?php if ($navMode === 'hero'): ?>
<script>
  /* hero mode: transparent over the hero, crimson once you scroll past it */
  (function () {
    var nav = document.getElementById('cnNav');
    function paint() { nav.classList.toggle('solid', window.scrollY > 90); }
    window.addEventListener('scroll', paint, { passive: true });
    paint();
  })();
</script>
<?php endif; ?>
