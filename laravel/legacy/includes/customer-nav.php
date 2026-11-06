<?php
/* Holds: the top bar of the customer browse pages. */
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
$navSelf = isset($navSelf) ? $navSelf : (string) optional(request()->route())->getName();   /* Laravel: the including page's ROUTE NAME (customer.venues on the landing) */   /* the page including this bar */
$navAuthed  = customer_logged_in();
$navCounter = counter_mode();     /* staff booking for a walk-in — see the block below */
/* room-reservation.php renders its own counter bar (staff name + this same
   link) right below this nav — set by the including page so this one doesn't
   repeat it. */
$navCounterHasOwnBar = isset($navCounterHasOwnBar) ? $navCounterHasOwnBar : false;
/* The chip shows WHO LOGGED IN (the session, written by customer-login.php).
   Every customer page reads this same session row now. This chip was once the
   ONLY part that did: the rest showed a hard-coded demo customer, so one screen
   could name two different people. */
$navName  = $navAuthed && session('customer_name') ? session('customer_name') : $customerContact['name'];
$navFirst = explode(' ', trim($navName))[0];
$navParts = preg_split('/\s+/', trim($navName));
$navInit  = strtoupper(substr($navParts[0], 0, 1) . substr(end($navParts), 0, 1));
$navPhoto = $navAuthed ? ($customerContact['photo'] ?? '') : '';   /* the profile photo, else the initials */
/* anchors stay in-page on the landing. In counter mode every link carries
   counter=1, or one click drops staff into the customer pages (2026-10-27). */
$navCounterQ = $navCounter ? '?counter=1' : '';
$navHome  = $navSelf === 'customer.venues' ? '' : route('customer.venues') . $navCounterQ;
$navLinks = [];
foreach (array_values(array_unique(array_column($venueRooms, 'venue'))) as $navVenue) {   /* Bahay Alumni, USeP Venues, ... */
  $navSlug = 'venue-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($navVenue));           /* -> the landing page's group anchor */
  $navLinks[] = [$navSlug, $navHome . '#' . $navSlug, $navVenue];
}
$navLinks = array_merge($navLinks, [
  ['hostel',   $navHome . '#hostel-listings', $HOSTEL_VENUE],
  ['how',      $navHome . '#how',             'How it works'],
  ['bookings', route('customer.booking-history'),         'My bookings'],
  ['faq',      route('customer.faq') . $navCounterQ,      'FAQ'],
]);
if (!office_hostel_shown()) {                                           /* counter mode: another office's hostel (DB-DECISIONS 28) */
  $navLinks = array_values(array_filter($navLinks, function ($l) { return $l[0] !== 'hostel'; }));
}
$navBlocked = \App\Http\Controllers\Customer\BookingRules::blockedOfficeNames();   /* offices this customer is blocked at are not listed */
$navLinks = array_values(array_filter($navLinks, function ($l) use ($navBlocked) { return !in_array($l[2], $navBlocked, true); }));
if (!$navAuthed) {                                                      /* a guest has no bookings to show */
  $navLinks = array_values(array_filter($navLinks, function ($l) { return $l[0] !== 'bookings'; }));
}
?>
<link rel="stylesheet" href="<?php echo asset_stamped('assets/css/customer-nav.css'); ?>" />
<header class="cn-nav<?php echo $navMode === 'hero' ? '' : ' solid is-static'; ?>" id="cnNav">
  <div class="cn-wrap">
    <a class="cn-logo" href="<?php echo route('customer.venues') . $navCounterQ; ?>" title="Back to the landing page"><img src="<?php echo asset('logo/Logo Header 3.png'); ?>" alt="VENUSeP"></a>
    <nav class="cn-links">
<?php foreach ($navLinks as $l): ?>
      <a href="<?php echo htmlspecialchars($l[1]); ?>"<?php echo $l[0] === $navHere ? ' class="is-here" aria-current="page"' : ''; ?>><?php echo $l[2]; ?></a>
<?php endforeach; ?>
    </nav>
    <div style="display:flex;align-items:center;gap:10px">
<?php if ($navCounter || $navAuthed): ?>
      <?php $nbLook = 'nav'; include __DIR__ . '/notification-bell.php'; /* notifications (2026-10-28): the customer's, or the staff member's at the counter */ ?>
<?php endif; ?>
<?php if ($navCounter): ?>
      <!-- COUNTER MODE: no Log in / Sign up. This screen runs on the STAFF
           session, and a customer signing in here would replace it — the app
           keeps one account_type per session, so the staff member at the next
           monitor would be signed out mid-booking. The counter bar on the page
           says whose screen this is; this link is the way back to the admin
           dashboard on the pages that have no counter bar of their own
           (the landing page, FAQ). -->
      <span class="cn-name" style="color:#fff">Counter</span>
<?php if (!$navCounterHasOwnBar): ?>
      <a class="cn-leave" href="<?php echo route('admin.dashboard'); ?>">Leave counter mode</a>
<?php endif; ?>
<?php elseif ($navAuthed): ?>
      <a class="cn-user" href="<?php echo route('customer.customer-profile'); ?>" title="Your profile">
<?php if ($navPhoto !== ''): ?>
        <img class="cn-avatar" src="<?php echo htmlspecialchars(asset($navPhoto)); ?>" alt="" width="34" height="34">
<?php else: ?>
        <span class="cn-avatar"><?php echo htmlspecialchars($navInit); ?></span>
<?php endif; ?>
        <span class="cn-name"><?php echo htmlspecialchars($navFirst); ?></span>
      </a>
      <a class="cn-logout" href="<?php echo route('customer.logout'); ?>">Log out</a>
<?php else: ?>
      <span class="cn-auth">
        <a class="cn-login" href="<?php echo route('customer.customer-login'); ?>">Log in</a>
        <a class="cn-signup" href="<?php echo route('customer.customer-register'); ?>">Sign up</a>
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
<?php if (!$navCounterHasOwnBar): ?>
    <a href="<?php echo route('admin.dashboard'); ?>">Leave counter mode</a>
<?php endif; ?>
<?php elseif ($navAuthed): ?>
    <a href="<?php echo route('customer.customer-profile'); ?>">Profile</a>
    <a href="<?php echo route('customer.logout'); ?>">Log out</a>
<?php else: ?>
    <a href="<?php echo route('customer.customer-login'); ?>">Log in</a>
    <a href="<?php echo route('customer.customer-register'); ?>">Sign up</a>
<?php endif; ?>
  </nav>
</header>
<?php if ($navMode === 'hero'): ?>
<script src="<?php echo asset_stamped('assets/js/customer-nav.js'); ?>"></script>
<?php endif; ?>
