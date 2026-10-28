{{--
  The browse-mode customer top bar (legacy/includes/customer-nav.php, run as-is).
  @include('partials.customer-nav', ['navMode' => 'hero', 'navHere' => 'faq'])
    navMode  'solid' (default) | 'hero' (transparent over the landing hero)
    navHere  which link is current: a venue slug | 'hostel' | 'how' | 'bookings' | 'faq'
    navSelf  optional: the page's ROUTE NAME (defaults to the current route) —
             'customer.venues' makes Venues / Hostel / How it works in-page anchors
    navCounterHasOwnBar  true on room-reservation (it renders its own counter bar)
--}}
@php extract(legacy('auth', 'customer-bookings', 'venue-rooms', 'hostel-rooms', 'demo-mode'), EXTR_SKIP); include base_path('legacy/includes/customer-nav.php'); @endphp
