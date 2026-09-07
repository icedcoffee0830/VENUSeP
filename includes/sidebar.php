<?php
/* =====================================================================
   SHARED SIDEBAR — the ONE sidebar every admin page uses.

   How to use (from a page inside admin/):
       <?php $active = 'Venue Management'; include __DIR__ . '/../includes/sidebar.php'; ?>
   $active = the menu label to highlight (see $items below).

   Edit the menu HERE and every page updates. Links + the logo path are
   written relative to the admin/ folder — if a page OUTSIDE admin/ ever
   includes this, revisit the paths.
   ===================================================================== */
$active = isset($active) ? $active : '';
// $portal picks the menu: 'admin' (default) or 'customer'. Same black look
// either way — only the links change. Links are bare filenames so they
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
    ['Log Out',             'customer-login.php',              'bi-box-arrow-right'],
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
    ['Settings',            'venusep_profile.php',             'bi-gear'],
  ];
}
?>
<style>
  /* sidebar styles live HERE so every page gets them with the include.
     Team look: BLACK (#1f1e1e) sidebar, white text, logo strip on top
     (continuous with the header bar). Every property is set explicitly
     with !important so leftover page CSS can never restyle the sidebar. */
  aside.sidebar{position:fixed!important;left:0!important;top:0!important;width:235px!important;min-width:235px!important;height:100vh!important;background:#1f1e1e!important;color:#fff!important;z-index:1035!important;overflow-y:auto!important;border-right:1px solid #2d2b29!important;box-shadow:none!important;transform:none!important;padding:0!important;margin:0!important}
  aside.sidebar .logo{height:58px;display:flex;align-items:center;justify-content:center;padding:14px 20px;background:#1f1e1e;border-bottom:1px solid #333}
  aside.sidebar .logo img{display:block;max-width:150px;max-height:36px;width:auto;height:auto}
  aside.sidebar .menu{list-style:none;padding:15px 10px;margin:0}
  aside.sidebar .menu li{margin:0 0 8px;padding:0;list-style:none}
  aside.sidebar .menu a{display:flex;align-items:center;gap:15px;height:42px;padding:0 15px;color:#e5e5e5!important;background:transparent;text-decoration:none;border-radius:10px;border:1px solid transparent;font-size:14px;transition:.2s}
  aside.sidebar .menu a:hover{background:#343333!important}
  aside.sidebar .menu a.active{background:#343333!important;border:1px solid #ffffff40!important}
  aside.sidebar .menu i{width:20px;text-align:center;font-size:18px;color:inherit}
  aside.sidebar .menu span{font-size:14px}
  @media (max-width:767.98px){aside.sidebar{transform:translateX(-100%)!important}}
</style>
<aside class="sidebar">
  <div class="logo"><img src="../logo/Logo Header 2.png" alt="VENUSeP logo" /></div>
  <ul class="menu">
<?php foreach ($items as $it): ?>
    <li><a href="<?php echo $it[1]; ?>"<?php echo $it[0] === $active ? ' class="active"' : ''; ?>><i class="bi <?php echo $it[2]; ?>"></i><span><?php echo $it[0]; ?></span></a></li>
<?php endforeach; ?>
  </ul>
</aside>
