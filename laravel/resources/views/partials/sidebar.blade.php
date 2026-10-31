{{--
  THE ONE SIDEBAR (was includes/sidebar.php) — every admin AND customer page.
  $portal picks the menu ('admin' | 'customer'); $active = the label to highlight.
  Edit the menu HERE and every page updates.
--}}
@php
  $items = $portal === 'customer'
    ? [
        //  label                  route                            icon
        ['Browse Venues',       route('customer.venues'),              'bi-building'],
        ['Booking History',     route('customer.booking-history'),     'bi-clock-history'],
        ['Calendar',            route('customer.calendar'),            'bi-calendar-event'],
        ['Transaction History', route('customer.transaction-history'), 'bi-receipt'],
        ['Profile',             route('customer.customer-profile'),    'bi-person'],
        ['FAQ',                 route('customer.faq', ['in' => 'app']), 'bi-question-circle'],   /* ?in=app = keep the sidebar frame */
        ['Log Out',             route('customer.logout'),              'bi-box-arrow-right'],   /* ends the session */
      ]
    : [
        ['Dashboard',           route('admin.dashboard'),              'bi-speedometer2'],
        ['Venue Management',    route('admin.venue-management'),       'bi-building'],
        ['Booking Management',  route('admin.booking-requests'),       'bi-calendar-check'],
        ['Transaction History', route('admin.transaction-history'),    'bi-receipt'],
        ['Calendar',            route('admin.calendar'),               'bi-calendar-event'],
        ['Staff Management',    route('admin.staff'),                  'bi-people', 'admin'],   /* admins only (staff:admin) */
        ['Customer Management', route('admin.customer-management'),    'bi-person-badge', 'admin'],   /* admins only (2026-10-31) */
        ['Reports',             route('admin.reports'),                'bi-bar-chart'],
        ['Payment Settings',    route('admin.payment-settings'),       'bi-wallet2'],
        ['FAQ Management',      route('admin.faq-management'),         'bi-question-circle'],   /* admin-added FAQ entries (2026-09-18) */
        ['Backup & Restore',    route('admin.backup-restore'),         'bi-database-check'],    /* staff: back up; admins: download / restore (2026-10-27) */
        ['Settings',            route('admin.profile'),                'bi-gear'],
        ['Log Out',             route('admin.logout'),                 'bi-box-arrow-right'],   /* ends the session */
      ];
  /* a 4th column 'admin' = an admin-only page: staff are not shown the link (Bug 5) */
  $items = array_filter($items, fn ($i) => ($i[3] ?? '') !== 'admin' || session('account_type') === 'admin');
@endphp
<style>
  /* crimson to black, continuous with the header. */
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
  <div class="logo"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP logo" /></div>
  <ul class="menu">
@foreach ($items as [$label, $href, $icon])
    <li><a href="{{ $href }}"@if ($label === $active) class="active"@endif><i class="bi {{ $icon }}"></i><span>{{ $label }}</span></a></li>
@endforeach
  </ul>
</aside>
