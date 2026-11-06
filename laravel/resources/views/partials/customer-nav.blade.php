{{--
  The browse-mode customer top bar (legacy/includes/customer-nav.php, run as-is).
  @include('partials.customer-nav', ['navMode' => 'hero', 'navHere' => 'faq'])
    navMode  'solid' (default) | 'hero' (transparent over the landing hero)
    navHere  which link is current: a venue slug | 'hostel' | 'how' | 'bookings' | 'faq'
    navSelf  optional: the page's ROUTE NAME (defaults to the current route) —
             'customer.venues' makes Venues / Hostel / How it works in-page anchors
    navCounterHasOwnBar  true on room-reservation (it renders its own counter bar)
--}}
@php
    /* A page that already has these (the landing page filters $venueRooms) keeps its own. */
    $navLegacy = legacy('auth', 'customer-bookings', 'venue-rooms', 'hostel-rooms');
    $customerContact ??= $navLegacy['customerContact'];   // set in customer-bookings.php
    $venueRooms ??= $navLegacy['venueRooms'];             // set in venue-rooms.php
    $HOSTEL_VENUE ??= $navLegacy['HOSTEL_VENUE'];         // set in hostel-rooms.php
    include base_path('legacy/includes/customer-nav.php');
@endphp
@include('partials.block-modal')
