{{--
  THE ONE SIDEBAR (was includes/sidebar.php) — every admin AND customer page.
  $portal picks the menu ('admin' | 'customer'); $active = the label to highlight.
  Edit the menu HERE and every page updates.
--}}
@php
  legacy('auth');   /* admin_is_super() */
  $sbCounts = [];
  if ($portal === 'customer') {
    $items = [
        //  label                  route                            icon
        ['Browse Venues',       route('customer.venues'),              'bi-building'],
        ['Booking History',     route('customer.booking-history'),     'bi-clock-history'],
        ['Calendar',            route('customer.calendar'),            'bi-calendar-event'],
        ['Transaction History', route('customer.transaction-history'), 'bi-receipt'],
        ['Profile',             route('customer.customer-profile'),    'bi-person'],
        ['FAQ',                 route('customer.faq', ['in' => 'app']), 'bi-question-circle'],   /* ?in=app = keep the sidebar frame */
        ['Log Out',             route('customer.logout'),              'bi-box-arrow-right'],   /* ends the session */
    ];
  } else {
    /* What waits for this person, read on every page. The Staff count opens the tab that waits. */
    $sbIsAdmin = session('account_type') === 'admin';
    $sbWaiting = $sbIsAdmin ? \App\Queries\CoverQueries::waitingFor(admin_is_super() ? null
      : \Illuminate\Support\Facades\DB::selectOne('SELECT office_group FROM users WHERE id = ?', [(int) session('user_id')])?->office_group) : ['covers' => 0, 'moves' => 0];
    $sbTab = $sbWaiting['covers'] ? ['tab' => 'covers'] : ($sbWaiting['moves'] ? ['tab' => 'moves'] : []);
    $sbCounts = ['Booking Management' => \App\Queries\BookingQueries::needsAction(), 'Staff Management' => $sbWaiting['covers'] + $sbWaiting['moves']];
    $items = [
        ['Dashboard',           route('admin.dashboard'),              'bi-speedometer2'],
        ['Booking Management',  route('admin.booking-requests'),       'bi-calendar-check'],
        ['Calendar',            route('admin.calendar'),               'bi-calendar-event'],
        ['Transaction History', route('admin.transaction-history'),    'bi-receipt'],
        ['#Venues and people'],
        ['Venue Management',    route('admin.venue-management'),       'bi-building'],
        ['Staff Management',    route('admin.staff', $sbTab),          'bi-people', 'admin'],   /* admins only (staff:admin) */
        ['Customer Management', route('admin.customer-management'),    'bi-person-badge', 'admin'],   /* admins only (2026-10-31) */
        ['#Office setup'],
        ['Office Settings',     route('admin.office-settings'),        'bi-sliders', 'admin'],
        ['Payment Settings',    route('admin.payment-settings'),       'bi-wallet2'],
        ['FAQ Management',      route('admin.faq-management'),         'bi-question-circle', 'admin'],   /* admin-added FAQ entries (2026-09-18) */
        ['Backup & Restore',    route('admin.backup-restore'),         'bi-database-check', 'super'],    /* the super admin only (DB-DECISIONS 28) */
        ['#Records'],
        ['Reports',             route('admin.reports'),                'bi-bar-chart'],
        ['Activity',            route('admin.activity'),               'bi-clock-history', 'super'],
        ['#'],
        ['Settings',            route('admin.profile'),                'bi-gear'],
        ['Log Out',             route('admin.logout'),                 'bi-box-arrow-right'],   /* ends the session */
    ];
  }
  /* a 4th column 'admin' = an admin-only page: staff are not shown the link (Bug 5);
     'super' = the super admin's only. A '#Heading' starts a group: staff get a plain list. */
  $items = array_filter($items, fn ($i) => match ($i[3] ?? '') {
      'admin' => session('account_type') === 'admin',
      'super' => admin_is_super(),
      default => !str_starts_with($i[0], '#') || session('account_type') === 'admin',
  });
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
  /* the staff side: grouped and tighter, so every link fits a 768px-tall laptop; a count for what waits */
  aside.sidebar.grouped .menu{padding:10px 8px 14px}
  aside.sidebar.grouped .menu li{margin:0 0 2px}
  aside.sidebar.grouped .menu a{gap:8px;height:34px;padding:0 8px;white-space:nowrap}
  aside.sidebar.grouped .menu i{width:18px;font-size:17px}
  aside.sidebar.grouped .menu span{font-size:13px}
  aside.sidebar.grouped .menu li:last-child{margin-top:8px;padding-top:8px}
  aside.sidebar .menu li.grp{margin:10px 0 2px;padding:0 8px;color:#dac5c3;font-size:12px;font-weight:600;letter-spacing:.02em}
  aside.sidebar .menu span.cnt{display:inline-flex;align-items:center;justify-content:center;flex:none;min-width:20px;height:20px;margin-left:auto;padding:0 5px;border-radius:999px;background:#ffd166;color:#3a0c14;font-size:12px;font-weight:700;font-variant-numeric:tabular-nums}
  aside.sidebar .menu span.cnt[hidden]{display:none}
  @media (max-width:767.98px){aside.sidebar{transform:translateX(-100%)!important;will-change:transform}aside.sidebar.grouped .menu a{height:44px}}   /* will-change: the slide runs on the GPU */
</style>
<aside class="sidebar{{ $portal === 'customer' ? '' : ' grouped' }}" id="hdSidebar">
  <div class="logo"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP logo" /></div>
  <ul class="menu">
@foreach ($items as $item)
@if (str_starts_with($item[0], '#'))
@if ($item[0] !== '#')
    <li class="grp">{{ substr($item[0], 1) }}</li>
@endif
@else
@php [$label, $href, $icon] = $item; @endphp
    <li><a href="{{ $href }}"@if ($label === $active) class="active" aria-current="page"@endif><i class="bi {{ $icon }}"></i><span>{{ $label }}</span>@isset ($sbCounts[$label])@fragment('sb-' . $label)<span class="cnt" data-live="sb-{{ $label }}" aria-label="{{ $sbCounts[$label] }} need you"@unless ($sbCounts[$label]) hidden @endunless>{{ $sbCounts[$label] }}</span>@endfragment @endisset</a></li>
@endif
@endforeach
  </ul>
</aside>
