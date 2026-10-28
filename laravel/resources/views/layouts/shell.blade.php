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
    @push('scripts')     the page's <script> blocks, in their old order
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    @stack('head')

    @if ($portal === 'customer')
    <style>
      :root {
        --black: #1f1e1e; --border: #e5e5e5; --muted: #606a75;
        --venusep-sidebar-width: 235px; --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body { background: #fff; color: var(--black); font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; overflow-x: hidden; }
      .app-main { margin-left: var(--venusep-sidebar-width); padding-top: var(--venusep-header-height); min-height: 100vh; background: #fff; }
      .container-fluid { width: 100%; padding-inline: 30px; }
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
      }
    </style>
    @else
    <style>
      :root {
        --venusep-black: #1f1e1e;
        --venusep-cream: #ffffff;
        --venusep-border: #ddd7ce;
        --venusep-text: #050505;
        --venusep-sidebar-width: 235px;
        --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body {
        background: #fff;
        color: var(--venusep-text);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        overflow-x: hidden;
      }
      /* main content area sits right of the sidebar, below the header */
      .app-main {
        margin-left: var(--venusep-sidebar-width);
        padding-top: var(--venusep-header-height);
        min-height: 100vh;
        background: #fff;
      }
      .container-fluid { width: 100%; padding-inline: 30px; } /* uniform content inset — 30px sides on every admin page */
      .app-content-header { padding: 1.3rem 0 0; }
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
        .user-name { display: none; }
      }
    </style>
    @endif
    @stack('styles')
  </head>
  <body @hasSection('body-class') class="@yield('body-class')" @endif>
    <div class="app-wrapper">
      @include('partials.header', ['portal' => $portal])
      @include('partials.sidebar', ['portal' => $portal, 'active' => trim($__env->yieldContent('active'))])
      @yield('content')
    </div>
    @stack('body-end')
    @stack('scripts')
  </body>
</html>
