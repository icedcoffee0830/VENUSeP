{{--
  THE PHONE TAB BAR (Aron 2026-11-05, option A) — replaces the ☰ menu on the account pages
  listed in the match below, phone width only. Sits inside the header, so its logo is in
  the bar's row and every page with the header can have it. A page not listed (pay,
  refund request) keeps the ☰ menu; so does a guest or staff at the counter on the FAQ.
  The red dot is painted by the bell's script, from the bell's own reply (needsYou).
--}}
@php
  legacy('auth');
  $ctHere = match (request()->route()?->getName()) {
    'customer.booking-history', 'customer.receipt' => 'bookings',
    'customer.transaction-history'                 => 'payments',
    'customer.calendar'                            => 'calendar',
    'customer.customer-profile', 'customer.faq'    => 'me',   /* the header is on the FAQ only when opened in-app */
    default                                        => null,
  };
  $ctTabs = [
    //  key         label       route                                  icon
    ['venues',   'Venues',   route('customer.venues'),              'bi-building'],
    ['bookings', 'Bookings', route('customer.booking-history'),     'bi-clock-history'],
    ['payments', 'Payments', route('customer.transaction-history'), 'bi-receipt'],
    ['calendar', 'Calendar', route('customer.calendar'),            'bi-calendar-event'],
    ['me',       'Me',       route('customer.customer-profile'),    'bi-person'],
  ];
@endphp
@if ($ctHere !== null && customer_logged_in() && !counter_mode())
<link rel="stylesheet" href="{{ asset_stamped('assets/css/customer-tabs.css') }}" />
<a class="hd-logo" href="{{ route('customer.venues') }}"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP" width="120" height="21"></a>
<nav class="ct-bar" aria-label="Main">
@foreach ($ctTabs as [$key, $label, $href, $icon])
  <a href="{{ $href }}" data-tab="{{ $key }}"@if ($key === $ctHere) aria-current="page"@endif><span class="ct-ico"><i class="bi {{ $icon }}" aria-hidden="true"></i>@if ($key === 'bookings')<span class="ct-dot" hidden>, needs your attention</span>@endif</span>{{ $label }}</a>
@endforeach
</nav>
<script src="{{ asset_stamped('assets/js/customer-tabs.js') }}"></script>
@endif
