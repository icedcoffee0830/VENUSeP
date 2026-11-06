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
<style>
  .hd-logo,.ct-bar{display:none}
  /* the same width where the shell hides the sidebar; screen only, so a receipt prints as before */
  @media screen and (max-width:767.98px){
    aside.sidebar,nav.app-header .hd-wrap>.hd-nav:first-child,nav.app-header li:has(>.hd-sep,>.hd-user){display:none}   /* the ☰ menu; Me replaces the name chip */
    nav.app-header .hd-logo{display:flex;align-items:center;min-height:44px;margin-right:auto}
    nav.app-header .hd-logo img{display:block;height:21px;width:auto;filter:invert(1)}
    nav.app-header .hd-quick,nav.app-header .nb-bell{width:44px;height:44px;padding:0;justify-content:center}
    .app-main{padding-bottom:calc(60px + env(safe-area-inset-bottom))}   /* the page ends above the bar */
    .ct-bar{position:fixed;left:0;right:0;bottom:0;display:grid;grid-template-columns:repeat(5,1fr);padding:0 4px env(safe-area-inset-bottom);background:#fff;border-top:1px solid #e5e5e5;font-family:Inter,system-ui,sans-serif}
    .ct-bar a{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;min-height:59px;color:#606a75;font-size:11px;font-weight:600;text-decoration:none;touch-action:manipulation;-webkit-tap-highlight-color:transparent}
    .ct-bar a[aria-current]{color:#a11626}
    .ct-bar a[aria-current]::before{content:"";position:absolute;top:0;width:26px;height:3px;border-radius:0 0 3px 3px;background:#a11626}
    .ct-bar a:focus-visible{outline:2px solid #a11626;outline-offset:-3px;border-radius:10px}
    .ct-ico{position:relative;font-size:20px;line-height:1}
    .ct-dot{position:absolute;top:-1px;right:-5px;width:10px;height:10px;border-radius:50%;background:#d11a2a;box-shadow:0 0 0 2px #fff;overflow:hidden;text-indent:100%;white-space:nowrap}
  }
</style>
<a class="hd-logo" href="{{ route('customer.venues') }}"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP" width="120" height="21"></a>
<nav class="ct-bar" aria-label="Main">
@foreach ($ctTabs as [$key, $label, $href, $icon])
  <a href="{{ $href }}" data-tab="{{ $key }}"@if ($key === $ctHere) aria-current="page"@endif><span class="ct-ico"><i class="bi {{ $icon }}" aria-hidden="true"></i>@if ($key === 'bookings')<span class="ct-dot" hidden>, needs your attention</span>@endif</span>{{ $label }}</a>
@endforeach
</nav>
<script>
  /* iPhones report the safe area only to a page that asks for it; phones only, so a wide
     screen's sidebar stays clear of the notch */
  if (matchMedia('(max-width:767.98px)').matches) document.querySelector('meta[name=viewport]').content += ', viewport-fit=cover';
</script>
@endif
