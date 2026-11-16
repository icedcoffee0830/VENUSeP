{{--
  THE PAGE SHELL — every admin and customer page (the old [0] SHELL CSS +
  [2] HEADER BAR + [3] SIDEBAR blocks, which every page used to repeat).
  Pages extend layouts.admin or layouts.customer, never this file directly.

  A page provides:
    @section('title', 'Booking Requests')        -> <title>VENUSeP | Booking Requests</title>
    @section('active', 'Booking Management')     -> highlighted sidebar item
    @section('body-class', 'booking-history-page')   (optional)
    @push('head')    extra <link>/<meta> (CDN css, assets/css/confirm-modal.css, ...)
    @push('styles')  the page's own CSS [1], AND any line of its old [0] block that differs
                     from the shell CSS below (page CSS comes after it, so it wins)
    @section('content')  everything that was inside .app-wrapper after the sidebar
                     (normally <main class="app-main">...</main>)
    @push('body-end')    markup that sat after .app-wrapper (modals, toasts)
    @push('scripts')     the page's script tags, in their old order
--}}
<!DOCTYPE html>
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>VENUSeP | @yield('title')</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/500.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    @stack('head')

    @if ($portal === 'customer')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/shell-customer.css') }}" />
    @else
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/shell-admin.css') }}" />
    @endif
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/chips.css') }}" />
    @stack('styles')
  </head>
  <body @hasSection('body-class') class="@yield('body-class')" @endif>
    <div class="app-wrapper">
      @include('partials.header', ['portal' => $portal])
      {{-- @section('active', '...') stores the label HTML-escaped ("Backup &amp; Restore"), so it is
           decoded back before the sidebar compares it with its own labels --}}
      @include('partials.sidebar', ['portal' => $portal, 'active' => html_entity_decode(trim($__env->yieldContent('active')), ENT_QUOTES | ENT_HTML5, 'UTF-8')])
      @yield('content')
    </div>
    @stack('body-end')
    @stack('scripts')
  </body>
</html>
