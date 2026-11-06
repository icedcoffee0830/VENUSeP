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
    $sbWaiting = $sbIsAdmin ? \App\Queries\CoverQueries::waitingForMe() : ['covers' => 0, 'moves' => 0];
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
<link rel="stylesheet" href="{{ asset_stamped('assets/css/sidebar.css') }}" />
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
    <li><a href="{{ $href }}"@if ($label === $active) class="active" aria-current="page"@endif><i class="bi {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>@isset ($sbCounts[$label])@fragment('sb-' . $label)<span class="cnt" data-live="sb-{{ $label }}" aria-label="{{ $sbCounts[$label] }} need you"@unless ($sbCounts[$label]) hidden @endunless>{{ $sbCounts[$label] }}</span>@endfragment @endisset</a></li>
@endif
@endforeach
  </ul>
</aside>
