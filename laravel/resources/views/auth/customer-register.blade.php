{{-- Ported from customer/customer-register.php (standalone auth page, no shell). --}}
<!DOCTYPE html>
<!-- ==================================================================
  CUSTOMER REGISTRATION — VENUSeP merged system
  ==================================================================
  Ported from the teammate's customer-register.php. Standalone AUTH page
  (no shell). Restyled to the team palette; fields + validation kept.
  WIRED: posts to register-submit.php, which creates the users + customers
  rows, then sends them to the login page — signing in proves the password
  they just chose actually works.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content" />
    <title>VENUSeP | Customer Registration</title>
    <link rel="icon" href="{{ asset('logo/Logo Header 3.png') }}" type="image/png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer-auth.css') }}" />   <!-- the auth pages' shared CSS -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/auth/customer-register.css') }}" />
  </head>
  <body class="customer-register-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="" />
        <div class="auth-hero-copy">
          <h2 class="auth-hero-title">Join <br />VENUSeP! <span class="auth-wave">&#128075;</span></h2>
          <p class="auth-hero-sub">One account for every campus venue and hostel bed. Book, track your requests and pay online &mdash; all in one place.</p>
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="customerRegisterTitle">
        <a class="auth-back" href="{{ route('customer.venues') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to home</a>
        <div class="auth-card">
          <a class="auth-brand" href="{{ route('customer.venues') }}" title="Back to VENUSeP"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP" /></a>
          <div class="auth-heading">
            <h1 class="auth-title" id="customerRegisterTitle">Create your account</h1>
            <p class="auth-subtitle">It takes less than a minute.</p>
          </div>

          <!-- On success the server has created the account; then to the login page. -->
          <form id="customerRegisterForm" action="" method="post" novalidate data-auth-form="register" data-redirect="{{ route('customer.customer-login') }}" data-success-target="registrationSuccessMessage" data-success-message="Account created successfully. Redirecting to login...">
            <div class="form-group">
              <label for="fullName" class="form-label">Full Name</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span><input id="fullName" name="full_name" type="text" autocomplete="name" placeholder="Enter full name" aria-describedby="fullNameValidation" required /></div>
              <small class="validation-message" id="fullNameValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="email" class="form-label">Email Address</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span><input id="email" name="email" type="email" autocomplete="email" placeholder="customer@example.com" aria-describedby="emailValidation" required /></div>
              <small class="validation-message" id="emailValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="contactNumber" class="form-label">Contact Number</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-telephone" aria-hidden="true"></i></span><input id="contactNumber" name="contact_number" type="tel" autocomplete="tel" placeholder="09XXXXXXXXX" aria-describedby="contactNumberValidation" required /></div>
              <small class="validation-message" id="contactNumberValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="password" class="form-label">Password</label>
              <div class="input-group password-field"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input id="password" name="password" type="password" autocomplete="new-password" placeholder="Create password" minlength="8" aria-describedby="passwordValidation" required /><button class="password-toggle" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="passwordValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="confirmPassword" class="form-label">Confirm Password</label>
              <div class="input-group password-field"><span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><input id="confirmPassword" name="confirm_password" type="password" autocomplete="new-password" placeholder="Confirm password" aria-describedby="confirmPasswordValidation" required /><button class="password-toggle" type="button" data-password-toggle="confirmPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="confirmPasswordValidation" aria-live="polite"></small>
            </div>
            <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="terms" name="terms" aria-describedby="termsValidation" required /><span class="form-check-label">I agree to the <a class="auth-link" href="{{ route('terms') }}" target="_blank" rel="noopener">Terms and Conditions</a></span></label>
            <small class="validation-message" id="termsValidation" aria-live="polite"></small>
            <button type="submit" class="btn-auth"><i class="bi bi-person-plus" aria-hidden="true"></i>Create Account</button>
            <small class="success-message" id="registrationSuccessMessage" aria-live="polite"></small>
          </form>
          @if ($crGoogleOn)
          <div class="auth-divider">or</div>
          <a class="btn-auth btn-google" href="{{ route('customer.google-start') }}">{!! file_get_contents(public_path('assets/img/google-g.svg')) !!}Sign up with Google</a>
          @endif
          <div class="auth-alt">
            <span class="auth-alt-text">Already have an account?</span>
            <a class="btn-auth btn-auth-outline" href="{{ route('customer.customer-login') }}">Login</a>
          </div>
        </div>
      </section>
    </main>

    <!-- Client-side validation for fast feedback; the server repeats every check. -->
    <script>
  /* Values from PHP for assets/js/auth/customer-register.js */
  var customerRegisterPage = {
    csrf: @json($crCsrf),
    registerSubmitUrl: @json(route('root.register-submit')),
  };
</script>
<script src="{{ asset_stamped('assets/js/auth/customer-register.js') }}"></script>
  </body>
</html>
