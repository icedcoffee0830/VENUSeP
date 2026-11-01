{{--
  The frame of a customer auth page (was includes/customer-auth-shell.php,
  cas_open() / cas_close()) — the same split layout as the customer login
  page, for the pages that only put a form in the card: forgot-password and
  reset-password. Styles: assets/css/customer-auth.css.

  A page provides:
    @section('page-title', 'Forgot password')
    @section('hero-title') Forgot your <br />password? @endsection   (trusted markup)
    @section('hero-sub', 'Sub text')
    @section('labelledby', 'forgotTitle')
    @section('card')  ...the card's contents... @endsection
--}}
<!DOCTYPE html>
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content" />
    <meta name="referrer" content="no-referrer" />
    <title>VENUSeP | @yield('page-title')</title>
    <link rel="icon" href="{{ asset('logo/Logo Header 3.png') }}" type="image/png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="{{ asset('assets/css/customer-auth.css') }}" />
  </head>
  <body class="customer-login-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="" />
        <div class="auth-hero-copy">
          <h2 class="auth-hero-title">@yield('hero-title')</h2>
          <p class="auth-hero-sub">@yield('hero-sub')</p>
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="@yield('labelledby')">
        <a class="auth-back" href="{{ route('customer.customer-login') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to login</a>
        <div class="auth-card">
          <a class="auth-brand" href="{{ route('customer.venues') }}" title="Back to VENUSeP"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP" /></a>
@yield('card')
        </div>
      </section>
    </main>
    <script>
      /* show / hide a password, same as the login page */
      document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
          const input = document.getElementById(toggle.dataset.passwordToggle);
          if (!input) return;
          const show = input.type === 'password';
          input.type = show ? 'text' : 'password';
          toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
          toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
          const icon = toggle.querySelector('i');
          if (icon) { icon.classList.toggle('bi-eye', !show); icon.classList.toggle('bi-eye-slash', show); }
        });
      });
    </script>
  </body>
</html>
