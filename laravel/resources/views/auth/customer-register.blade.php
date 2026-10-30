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
    <link rel="stylesheet" href="{{ asset('assets/css/customer-auth.css') }}" />   <!-- the auth pages' shared CSS -->
    <style>
      /* register only: on a phone the long form scrolls the page and starts at the top */
      @media (max-width: 860px) {
        html, body { height: auto; min-height: 100%; overflow-x: hidden; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .auth-panel { justify-content: flex-start; padding: 16px 20px max(48px, env(safe-area-inset-bottom)); }
      }
    </style>
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
            <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="terms" name="terms" aria-describedby="termsValidation" required /><span class="form-check-label">I agree to the Terms and Conditions</span></label>
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
      document.addEventListener('DOMContentLoaded', function () {
        const setMessage = function (id, message) { const el = document.getElementById(id); if (el) el.textContent = message; };
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
            if (icon) { icon.classList.toggle('bi-eye', !show); icon.classList.toggle('bi-eye-slash', show); }
          });
        });
        const CSRF = @json($crCsrf);
        document.querySelectorAll('[data-auth-form]').forEach(function (form) {
          form.querySelectorAll('input').forEach(function (field) {
            field.addEventListener(field.type === 'checkbox' ? 'change' : 'input', function () { setFieldState(field, field.getAttribute('aria-describedby'), ''); });
            field.addEventListener('focus', function () {
              if (!window.matchMedia('(max-width: 860px)').matches) return;
              window.setTimeout(function () { field.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 250);
            });
          });
          form.addEventListener('submit', function (event) {
            event.preventDefault();
            const email = form.querySelector('[name="email"]');
            const password = form.querySelector('[name="password"]');
            let hasError = false;
            setMessage(form.dataset.successTarget, '');
            const fullName = form.querySelector('[name="full_name"]');
            const contactNumber = form.querySelector('[name="contact_number"]');
            const confirmPassword = form.querySelector('[name="confirm_password"]');
            const terms = form.querySelector('[name="terms"]');
            const fullNameError = fullName.value.trim() ? '' : 'Please enter your full name.';
            const contactError = contactNumber.value.trim() ? '' : 'Please enter your contact number.';
            const passwordError = password.value.length >= 8 ? '' : 'Password must be at least 8 characters.';
            const confirmError = confirmPassword.value && confirmPassword.value === password.value ? '' : 'Passwords must match.';
            const termsError = terms.checked ? '' : 'You must agree to the Terms and Conditions.';
            setFieldState(fullName, 'fullNameValidation', fullNameError);
            setFieldState(contactNumber, 'contactNumberValidation', contactError);
            setFieldState(password, 'passwordValidation', passwordError);
            setFieldState(confirmPassword, 'confirmPasswordValidation', confirmError);
            setFieldState(terms, 'termsValidation', termsError);
            hasError = Boolean(fullNameError || contactError || passwordError || confirmError || termsError);
            const emailError = email.value.trim() && email.checkValidity() ? '' : 'Please enter a valid email address.';
            setFieldState(email, 'emailValidation', emailError);
            hasError = hasError || Boolean(emailError);
            if (hasError) { const f = form.querySelector('[aria-invalid="true"]'); if (f) f.focus(); return; }

            /* Create the account FOR REAL. These checks above stay because they
               give fast, per-field feedback — but the server runs all of them
               again, and it owns the ones the browser cannot answer honestly:
               whether the email is already registered, and whether the password
               hashes and stores. The page only claims success once it has. */
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            const body = new FormData(form);
            body.append('_token', CSRF);
            body.append('kind', 'customer');
            fetch(@json(route('root.register-submit')), { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
              .then(function (out) {
                if (submitBtn) submitBtn.disabled = false;
                if (!out.ok) {
                  /* Put the message on the FIELD it belongs to when the server
                     names one — "that email is already registered" beside the
                     email box beats a banner the eye slides past. */
                  const map = { full_name: 'fullNameValidation', email: 'emailValidation',
                                contact_number: 'contactNumberValidation', password: 'passwordValidation',
                                confirm_password: 'confirmPasswordValidation', terms: 'termsValidation' };
                  const target = out.field && form.querySelector('[name="' + out.field + '"]');
                  if (target) { setFieldState(target, map[out.field], out.message); target.focus(); }
                  else { setMessage(form.dataset.successTarget, out.message || 'The account was not created.'); }
                  return;
                }
                setMessage(form.dataset.successTarget, form.dataset.successMessage);
                window.setTimeout(function () { window.location.href = form.dataset.redirect; }, 800);
              })
              .catch(function () {
                if (submitBtn) submitBtn.disabled = false;
                setMessage(form.dataset.successTarget, 'Could not reach the server, so the account was not created.');
              });
          });
        });
      });
    </script>
  </body>
</html>
