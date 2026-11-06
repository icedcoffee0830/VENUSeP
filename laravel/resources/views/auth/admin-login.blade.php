{{-- Ported from admin/admin-login.php (standalone auth page, no shell). --}}
<!DOCTYPE html>
<!-- ==================================================================
  ADMIN LOGIN — VENUSeP merged system
  ==================================================================
  Ported from the teammate's admin-login.php. Standalone AUTH page:
  NO sidebar/header (you are not logged in yet). Restyled to the team
  palette; the field structure + validation are preserved.

  Connected to the VENUSeP `users` table using PDO + prepared statements.
  Authentication is performed server-side with password_verify() and a
  secure PHP session.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content" />
    <title>VENUSeP | Admin Login</title>
    <link rel="icon" href="{{ asset('logo/Logo Header 3.png') }}" type="image/png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/two-factor.css') }}" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/auth/admin-login.css') }}" />
  </head>
  <body class="admin-login-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="" />
        <div class="auth-hero-copy">
          @if ($tfaView === 'password')
          <h2 class="auth-hero-title">Welcome to <br />VENUSeP! <span class="auth-wave">&#128075;</span></h2>
          <p class="auth-hero-sub">The staff side: review booking requests, confirm payments, and keep the venues and hostel beds up to date.</p>
          @else
          @php(tfa_view_hero($tfaView))
          @endif
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="{{ $tfaView === 'password' ? 'adminLoginTitle' : 'tfaTitle' }}">
        <div class="auth-card">
          @if ($tfaView === 'password' || $tfaView === 'verify')
          <a class="auth-brand" href="{{ route('admin.admin-login') }}" title="VENUSeP staff"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP" /></a>
          @endif
          @if ($tfaView === 'verify')
          @php(tfa_view_code($tfaCsrf, (string)$pending['email'], $tfaError, $tfaLock))
          @elseif ($tfaView === 'enroll')
          @php(tfa_view_enroll($tfaCsrf, (string)$pending['email'], $tfaSecret, $tfaError))
          @elseif ($tfaView === 'codes')
          @php(tfa_view_codes($tfaCsrf, (array)$pending['codes'], (string)$pending['email'], $tfaError))
          @else
          <div class="auth-heading">
            <h1 class="auth-title" id="adminLoginTitle">Staff sign in</h1>
            <p class="auth-subtitle">Sign in to manage venue bookings.</p>
          </div>

          <!-- the form below is unchanged: same names, same POST, same PHP -->
          <form id="adminLoginForm" action="" method="post" novalidate data-auth-form="login">
            @csrf
            <div class="form-group">
              <label class="admin-check" for="loginAsAdmin">
                <input type="checkbox" name="as_admin" id="loginAsAdmin" value="1" @checked($asAdmin) />
                <span>I am signing in as an administrator<small>Administrators oversee all departments, so none needs to be selected.</small></span>
              </label>
              <span class="department-label">Department</span>
              <div class="department-options{{ $asAdmin ? ' is-off' : '' }}" role="radiogroup" aria-label="Department" aria-describedby="departmentValidation">
                {{-- One card per active venue. Staff pick their own venue (checked on the
                     server after the password); nothing is pre-picked. --}}
                @foreach ($departments as $deptId => $dept)
                <label class="department-card" for="loginDepartment{{ $deptId }}">
                  <span class="department-card-header">
                    <span class="department-icon"><i class="bi {{ $dept['icon'] }}" aria-hidden="true"></i></span>
                    <span class="department-copy"><strong>{{ $dept['name'] }}</strong><span>{{ $dept['line'] }}</span></span>
                    <input type="radio" name="department" id="loginDepartment{{ $deptId }}" value="{{ $deptId }}" @checked(!$asAdmin && $selectedDepartment === $deptId) @disabled($asAdmin) required />
                  </span>
                </label>
                @endforeach
              </div>
              <small class="validation-message" id="departmentValidation" aria-live="polite"></small>
            </div>

            <div class="form-group">
              <label for="adminEmail" class="form-label">Email Address</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span><input id="adminEmail" name="email" type="email" autocomplete="email" placeholder="admin@venusep.com" aria-describedby="emailValidation" maxlength="100" spellcheck="false" autocapitalize="none" required /></div>
              <small class="validation-message" id="emailValidation" aria-live="polite"></small>
            </div>

            <div class="form-group">
              <label for="adminPassword" class="form-label">Password</label>
              <div class="input-group password-field"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input id="adminPassword" name="password" type="password" autocomplete="current-password" placeholder="Enter password" aria-describedby="passwordValidation" minlength="8" maxlength="64" required /><button class="password-toggle" type="button" data-password-toggle="adminPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="passwordValidation" aria-live="polite"></small>
            </div>

            <div class="auth-options">
              <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="rememberAdmin" name="remember_me" /><span class="form-check-label">Remember Me</span></label>
              <a href="{{ route('admin.forgot-password') }}" class="auth-link">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-auth"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Login</button>
            <small class="success-message{{ $loginError !== '' ? ' is-error' : '' }}" id="loginSuccessMessage" aria-live="polite">{{ $loginError !== '' ? $loginError : $loginNotice }}</small>
          </form>
          <!-- No self-registration here (2026-10-28): staff and admin accounts are
               created by a signed-in administrator — Staff Management, Add account
               (admin-register.php is admin-only). A Register button here only
               bounced a signed-out visitor straight back to this page. -->
          <div class="auth-alt">
            <span class="auth-alt-text">Don&rsquo;t have an account yet? Ask an administrator &mdash; they create staff and admin accounts from <strong>Staff Management</strong>.</span>
          </div>
          @endif
        </div>
      </section>
    </main>
    @if ($tfaView !== 'password')
    <script src="{{ asset_stamped('assets/js/vendor/qrcode-generator-1.4.4.js') }}"></script>
    <script src="{{ asset_stamped('assets/js/two-factor.js') }}"></script>
    @endif

    <script src="{{ asset_stamped('assets/js/auth/admin-login.js') }}"></script>
  </body>
</html>
