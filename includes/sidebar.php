<?php
/* =====================================================================
   SHARED SIDEBAR — the ONE sidebar every admin AND customer page uses.

   How to use (from a page inside admin/):
       <?php $active = 'Venue Management'; include __DIR__ . '/../includes/sidebar.php'; ?>
   $active = the menu label to highlight (see $items below).

   Edit the menu HERE and every page updates. Links + the logo path are
   written relative to the admin/ folder — if a page OUTSIDE admin/ ever
   includes this, revisit the paths.
   ===================================================================== */
$active = isset($active) ? $active : '';
// $portal picks the menu: 'admin' (default) or 'customer'. Same crimson look
// either way (since 2026-09-18) — only the links change. Links are bare filenames so they
// resolve inside the including page's own folder (admin/ or customer/).
$portal = isset($portal) ? $portal : 'admin';
if ($portal === 'customer') {
  $items = [
    //  label                  link (relative to customer/)       icon
    ['Browse Venues',       'venusep_venue_booking.php',       'bi-building'],
    ['Booking History',     'booking-history.php',             'bi-clock-history'],
    ['Calendar',            'calendar.php',                    'bi-calendar-event'],
    ['Transaction History', 'transaction-history.php',         'bi-receipt'],
    ['Profile',             'customer-profile.php',            'bi-person'],
    ['FAQ',                 'faq.php?in=app',                  'bi-question-circle'],   /* ?in=app = keep the sidebar frame */
    ['Log Out',             'logout.php',                      'bi-box-arrow-right'],   /* ends the session */
  ];
} else {
  $items = [
    //  label                  link (relative to admin/)          icon
    ['Dashboard',           'Admin_Dashboard.php',             'bi-speedometer2'],
    ['Venue Management',    'venue-management.php',            'bi-building'],
    ['Booking Management',  'booking-requests.php',            'bi-calendar-check'],
    ['Transaction History', 'transaction-history.php',         'bi-receipt'],
    ['Calendar',            'calendar.php',                    'bi-calendar-event'],
    ['Staff Management',    'venusep_staffManagement.php',     'bi-people'],
    ['Reports',             'Quarterly_Reports.php',           'bi-bar-chart'],
    ['Payment Settings',    'payment-settings.php',            'bi-wallet2'],
    ['FAQ Management',      'faq-management.php',              'bi-question-circle'],   /* admin-added FAQ entries (2026-09-18) */
    ['Settings',            'venusep_profile.php',             'bi-gear'],
    ['Log Out',             'logout.php',                      'bi-box-arrow-right'],   /* ends the session */
  ];
}
?>
<style>
  /* customer sidebar — crimson to black, continuous with the customer header.
     Same structure and !important discipline as the admin one below. */
  aside.sidebar{position:fixed!important;left:0!important;top:0!important;width:235px!important;min-width:235px!important;height:100vh!important;color:#fff!important;z-index:1035!important;overflow-y:auto!important;border-right:1px solid rgba(255,255,255,.1)!important;box-shadow:none!important;transform:none!important;padding:0!important;margin:0!important;
    background:linear-gradient(180deg,#8a1222 0%,#3c0c14 42%,#120809 100%)!important;transition:transform 320ms cubic-bezier(.16,1,.3,1)!important}
  aside.sidebar .logo{height:58px;display:flex;align-items:center;justify-content:center;padding:14px 20px;background:transparent;border-bottom:1px solid rgba(255,255,255,.12)}
  aside.sidebar .logo img{display:block;max-width:150px;max-height:36px;width:auto;height:auto;filter:invert(1)}
  aside.sidebar .menu{list-style:none;padding:16px 12px;margin:0}
  aside.sidebar .menu li{margin:0 0 6px;padding:0;list-style:none}
  aside.sidebar .menu a{display:flex;align-items:center;gap:14px;height:44px;padding:0 14px;color:#f2d0cb!important;background:transparent;text-decoration:none;border-radius:12px;border:1px solid transparent;font-size:14px;font-weight:600;transition:background 180ms ease,color 180ms ease}
  aside.sidebar .menu a:hover{background:rgba(255,255,255,.09)!important;color:#fff!important}
  aside.sidebar .menu a.active{background:rgba(255,255,255,.14)!important;border:1px solid rgba(255,255,255,.18)!important;color:#fff!important}
  aside.sidebar .menu a.active i{color:#ffd166}
  aside.sidebar .menu i{width:20px;text-align:center;font-size:18px;color:inherit}
  aside.sidebar .menu span{font-size:14px}
  aside.sidebar .menu li:last-child{margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.1)}
  @media (max-width:767.98px){aside.sidebar{transform:translateX(-100%)!important;will-change:transform}}   /* will-change: the slide runs on the GPU */
</style>
<aside class="sidebar" id="hdSidebar">
  <div class="logo"><img src="../logo/Logo Header 3.png" alt="VENUSeP logo" /></div>
  <ul class="menu">
<?php foreach ($items as $it): ?>
    <li><a href="<?php echo $it[1]; ?>"<?php echo $it[0] === $active ? ' class="active"' : ''; ?>><i class="bi <?php echo $it[2]; ?>"></i><span><?php echo $it[0]; ?></span></a></li>
<?php endforeach; ?>
  </ul>
</aside>
