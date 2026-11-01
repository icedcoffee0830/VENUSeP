{{-- Ported from customer/customer-login.php (standalone auth page, no shell). --}}
<!DOCTYPE html>
<!-- ==================================================================
  CUSTOMER LOGIN — VENUSeP merged system
  ==================================================================
  Ported from the teammate's customer-login.php. Standalone AUTH page
  (no shell). Restyled to the team palette; fields + validation kept.
  REAL AUTH — PHP/PDO authentication against users + customers tables.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content" />
    <title>VENUSeP | Customer Login</title>
    <link rel="icon" href="{{ asset('logo/Logo Header 3.png') }}" type="image/png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="{{ asset('assets/css/two-factor.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/css/customer-auth.css') }}" />   <!-- the auth page look, shared with forgot/reset password -->
  </head>
  <body class="customer-login-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="" />
        <div class="auth-hero-copy">
          @if ($tfaView !== 'verify')
          <h2 class="auth-hero-title">Welcome to <br />VENUSeP! <span class="auth-wave">&#128075;</span></h2>
          <p class="auth-hero-sub">Reserve campus venues and hostel beds online. Check real availability, book in minutes, and pay by GCash or cash &mdash; no office visits.</p>
          @else
          @php(tfa_view_hero('verify'))
          @endif
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="{{ $tfaView === 'password' ? 'customerLoginTitle' : ($tfaView === 'google_signup' ? 'googleSignupTitle' : 'tfaTitle') }}">
        <a class="auth-back" href="{{ route('customer.venues') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to home</a>
        <div class="auth-card">
          <a class="auth-brand" href="{{ route('customer.venues') }}" title="Back to VENUSeP"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP" /></a>
          @if ($tfaView === 'verify')
          @php(tfa_view_code($tfaCsrf, (string)$pending['email'], $tfaError, $tfaLock))
          @elseif ($tfaView === 'google_signup')
          <div class="auth-heading">
            <h1 class="auth-title" id="googleSignupTitle">Finish creating your account</h1>
            <p class="auth-subtitle">Google confirmed your email. One last step before you can book.</p>
          </div>
          @if (!empty($googleErrors['form']))<p class="auth-notice" role="alert">{{ $googleErrors['form'] }}</p>@endif
          <form id="googleSignupForm" action="{{ route('customer.customer-login') }}" method="post">
            @csrf
            <div class="form-group">
              <div class="auth-readonly"><i class="bi bi-google" aria-hidden="true"></i><span><span class="form-label">Email address: </span>{{ (string)$googleSignup['email'] }}</span></div>
              <small class="auth-hint">From your Google account. You&rsquo;ll sign in with Google, so you don&rsquo;t need a VENUSeP password.</small>
            </div>
            <div class="form-group">
              <label for="gsFullName" class="form-label">Full name</label>
              <div class="input-group{{ !empty($googleErrors['full_name']) ? ' is-invalid' : '' }}"><span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span><input id="gsFullName" name="full_name" type="text" autocomplete="name" placeholder="Full name" maxlength="190" value="{{ $gsName }}" aria-describedby="gsFullNameMsg" required /></div>
              <small class="validation-message" id="gsFullNameMsg" aria-live="polite">{{ $googleErrors['full_name'] ?? '' }}</small>
            </div>
            <div class="form-group">
              <label for="gsPhone" class="form-label">Mobile number (optional)</label>
              <div class="input-group{{ !empty($googleErrors['contact_number']) ? ' is-invalid' : '' }}"><span class="input-group-text"><i class="bi bi-phone" aria-hidden="true"></i></span><input id="gsPhone" name="contact_number" type="tel" autocomplete="tel" inputmode="tel" placeholder="Mobile number (optional)" maxlength="20" value="{{ $gsPhone }}" aria-describedby="gsPhoneMsg gsPhoneHint" /></div>
              <small class="auth-hint" id="gsPhoneHint">09XX XXX XXXX. Used only if a GCash refund ever has to be sent to you.</small>
              <small class="validation-message" id="gsPhoneMsg" aria-live="polite">{{ $googleErrors['contact_number'] ?? '' }}</small>
            </div>
            <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="gsTerms" name="terms" aria-describedby="gsTermsMsg" required {{ $gsTerms ? 'checked' : '' }} /><span class="form-check-label">I agree to the Terms and Conditions</span></label>
            <small class="validation-message" id="gsTermsMsg" aria-live="polite">{{ $googleErrors['terms'] ?? '' }}</small>
            <button type="submit" name="google_step" value="create" class="btn-auth">Create my account</button>
          </form>
          <form class="auth-alt" action="{{ route('customer.customer-login') }}" method="post">
            @csrf
            <span class="auth-alt-text">Not you, or changed your mind?</span>
            <button type="submit" name="google_step" value="cancel" class="btn-auth btn-auth-outline" formnovalidate>Cancel</button>
          </form>
          @else
          <div class="auth-heading">
            <h1 class="auth-title" id="customerLoginTitle">Welcome back!</h1>
            <p class="auth-subtitle">Sign in to book venues and hostel beds.</p>
          </div>
          @if ($loginNotice !== '')<p class="auth-notice" role="status">{{ $loginNotice }}</p>@endif
          @if ($accountDisabled)<p class="auth-notice" id="accountDisabledNotice" role="status"><strong>Account disabled.</strong> This account has been disabled, so it can&rsquo;t sign in. If you think this is a mistake, contact the venue office.</p>@endif

          <!-- the form below is unchanged: same names, same POST, same PHP error output -->
          <form id="customerLoginForm" action="" method="post" novalidate data-auth-form="login">
            @csrf
            <div class="form-group">
              <label for="customerEmail" class="form-label">Email Address</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span><input id="customerEmail" name="email" type="email" autocomplete="email" placeholder="customer@example.com" aria-describedby="emailValidation" required /></div>
              <small class="validation-message" id="emailValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="customerPassword" class="form-label">Password</label>
              <div class="input-group password-field"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input id="customerPassword" name="password" type="password" autocomplete="current-password" placeholder="Enter password" aria-describedby="passwordValidation" required /><button class="password-toggle" type="button" data-password-toggle="customerPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="passwordValidation" aria-live="polite"></small>
            </div>
            <div class="auth-options">
              <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="rememberCustomer" name="remember_me" /><span class="form-check-label" title="Stay signed in on this device for 15 days. Don't tick it on a shared computer.">Remember me</span></label>
              <a href="{{ route('customer.forgot-password') }}" class="auth-link">Forgot Password?</a>
            </div>
            <button type="submit" class="btn-auth"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Login</button>
            @if ($loginError !== '')<small class="validation-message" id="loginServerError" aria-live="assertive">{{ $loginError }}</small>@endif
          </form>
          @if ($googleOn)
          <div class="auth-divider">or</div>
          <a class="btn-auth btn-google" href="{{ route('customer.google-start') }}" id="googleSignIn">{!! file_get_contents(public_path('assets/img/google-g.svg')) !!}Continue with Google</a>
          @endif
          <div class="auth-alt">
            <span class="auth-alt-text">Don&rsquo;t have an account yet?</span>
            <a class="btn-auth btn-auth-outline" href="{{ route('customer.customer-register') }}">Create an account</a>
          </div>
          @endif
        </div>
      </section>
    </main>
    @if ($tfaView !== 'password')
    <script src="{{ asset('assets/js/two-factor.js') }}"></script>
    @endif
    @if ($accountDisabled)
    {{-- Bug 2: the site's own alert modal; the notice in the card stays after it is closed --}}
    <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
    <script>
      venusepAlert('This account has been disabled, so it can’t sign in. If you think this is a mistake, contact the venue office.', { title: 'Account disabled' });
    </script>
    @endif

    <!-- Client-side validation; authentication is performed server-side by PHP. -->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const setMessage = function (id, message) {
          const el = document.getElementById(id);
          if (el) el.textContent = message;
        };

        const setFieldState = function (field, messageId, message) {
          if (!field) return;
          let target = field;
          if (field.type !== 'checkbox') target = field.closest('.input-group');
          if (target) target.classList.toggle('is-invalid', Boolean(message));
          field.setAttribute('aria-invalid', message ? 'true' : 'false');
          setMessage(messageId, message);
        };

        document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
          toggle.addEventListener('click', function () {
            const input = document.getElementById(toggle.dataset.passwordToggle);
            if (!input) return;

            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');

            const icon = toggle.querySelector('i');
            if (icon) {
              icon.classList.toggle('bi-eye', !show);
              icon.classList.toggle('bi-eye-slash', show);
            }
          });
        });

        /* "Remember me" applies to Google sign-in too: the box sits in the password
           form, so carry its state onto the Google link when it is clicked. */
        const googleSignIn = document.getElementById('googleSignIn');
        const rememberBox = document.getElementById('rememberCustomer');
        if (googleSignIn && rememberBox) {
          googleSignIn.addEventListener('click', function () {
            googleSignIn.href = @json(route('customer.google-start')) + (rememberBox.checked ? '?remember=1' : '');
          });
        }

        const form = document.getElementById('customerLoginForm');
        if (!form) return;

        form.querySelectorAll('input').forEach(function (field) {
          field.addEventListener(field.type === 'checkbox' ? 'change' : 'input', function () {
            const messageId = field.name === 'email'
              ? 'emailValidation'
              : field.name === 'password'
                ? 'passwordValidation'
                : null;

            if (messageId) setFieldState(field, messageId, '');
          });
        });

        form.addEventListener('submit', function (event) {
          const email = form.querySelector('[name="email"]');
          const password = form.querySelector('[name="password"]');
          let hasError = false;

          const passwordError = password.value ? '' : 'Please enter your password.';
          setFieldState(password, 'passwordValidation', passwordError);
          hasError = hasError || Boolean(passwordError);

          const emailError = email.value.trim() && email.checkValidity()
            ? ''
            : 'Please enter a valid email address.';
          setFieldState(email, 'emailValidation', emailError);
          hasError = hasError || Boolean(emailError);

          if (hasError) {
            event.preventDefault();
            const firstInvalid = form.querySelector('[aria-invalid="true"]');
            if (firstInvalid) firstInvalid.focus();
          }
          // If valid, allow the browser to POST to this PHP page.
        });
      });
    </script>
  </body>
</html>
