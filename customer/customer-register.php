<?php
/* A CSRF token for the registration POST. The page itself is public — anyone
   may sign up — but the token stops another site posting this form on a
   visitor's behalf. */
require_once __DIR__ . '/../includes/auth.php';
venusep_session_start();
$crCsrf = csrf_token();
?>
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
    <link rel="icon" href="../logo/Logo Header 3.png" type="image/png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <style>
      /* ==================================================================
         AUTH UI — split layout (customer-login + customer-register share it)
         Left: the crimson panel from the landing hero (gradient, grain, arcs,
         greeting). Right: the form on white with underline fields.
         Only the look changed; the form's classes (.input-group, .is-invalid,
         .validation-message …) are the same names the validation script uses.
         ================================================================== */
      :root { --black: #1f1e1e; --ink: #120809; --crimson: #a11626; --crimson-lo: #7d0f1e; --yellow: #ffd166;
              --border: #d9d4cc; --muted: #6e6a64; --danger: #b23a3a; --font-display: Archivo, Inter, system-ui, sans-serif; }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body { font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--black); background: #fff; min-height: 100vh; }

      /* ---- the split ---- */
      .auth-split { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); min-height: 100vh; min-height: 100svh; }

      /* ---- left: crimson panel ---- */
      .auth-hero { position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; padding: 48px 56px 36px;
        color: #fff; background: linear-gradient(162deg, #7d1120 0%, #3c0c14 48%, #120809 100%); }
      .auth-hero::before { content: ""; position: absolute; inset: 0; pointer-events: none;
        background: radial-gradient(900px 560px at 86% 2%, rgba(232,62,74,.32), transparent 62%), radial-gradient(800px 560px at 6% 100%, rgba(217,147,13,.16), transparent 60%); }
      .auth-hero-grain { position: absolute; inset: 0; pointer-events: none; opacity: .07; mix-blend-mode: overlay; background-size: 180px 180px;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); }
      .auth-hero-arcs { position: absolute; right: -22%; top: -18%; width: 120%; height: 120%; pointer-events: none; opacity: .95; }
      .auth-hero-arcs circle { fill: none; stroke: rgba(255,255,255,.11); stroke-width: 1.2; }
      .auth-hero-logo { position: relative; align-self: flex-start; height: 34px; width: auto; filter: invert(1); }   /* align-self: a flex column would stretch it wide */
      .auth-hero-copy { position: relative; max-width: 30rem; }
      .auth-hero-title { font-family: var(--font-display); font-weight: 800; font-size: clamp(42px, 5.2vw, 66px); line-height: 1.02; letter-spacing: -.02em; margin: 0 0 22px; }
      .auth-wave { display: inline-block; transform-origin: 70% 70%; animation: auth-wave 2.4s ease-in-out 1.2s 2; }
      @keyframes auth-wave { 0%,100% { transform: rotate(0); } 15% { transform: rotate(16deg); } 30% { transform: rotate(-8deg); } 45% { transform: rotate(14deg); } 60% { transform: rotate(-4deg); } 75% { transform: rotate(8deg); } }
      .auth-hero-sub { font-size: 17px; line-height: 1.6; color: rgba(255,255,255,.86); margin: 0; }
      .auth-hero-foot { position: relative; margin: 0; font-size: 13px; color: rgba(255,255,255,.6); }

      /* ---- right: the form panel ---- */
      .auth-panel { position: relative; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 48px clamp(24px, 6vw, 88px); }   /* the card sits centred in the panel */
      /* "Back to home" — pinned to the panel's top-left corner; a static row above the card on phones */
      .auth-back { position: absolute; top: 24px; left: 28px; display: inline-flex; align-items: center; gap: 8px; min-height: 36px; padding: 0 14px 0 10px; border-radius: 999px;
        border: 1px solid #e5e5e5; background: #fff; color: var(--muted); font-size: 13px; font-weight: 600; text-decoration: none; transition: color 160ms, border-color 160ms, background 160ms; }
      .auth-back svg { width: 16px; height: 16px; flex: none; }
      .auth-back:hover { color: #a11626; border-color: #a11626; background: #fff6f6; }
      .auth-card { width: 100%; max-width: 420px; }
      .auth-brand { display: block; margin-bottom: clamp(36px, 8vh, 84px); }
      .auth-brand img { height: 30px; width: auto; display: block; margin: 0 auto; }
      .auth-heading { margin-bottom: 26px; text-align: center; }
      .auth-title { font-family: var(--font-display); font-size: 30px; font-weight: 800; letter-spacing: -.02em; margin: 0 0 8px; }
      .auth-subtitle { color: var(--muted); font-size: 14px; line-height: 1.55; margin: 0; }

/* fields: pill inputs with a leading icon; the label stays for screen readers */
      .form-group { margin-bottom: 14px; }
      .form-label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
      .input-group { display: flex; align-items: center; border: 1.5px solid var(--border); border-radius: 999px; background: #fff; transition: border-color .18s, box-shadow .18s; }
      .input-group:focus-within { border-color: var(--crimson); box-shadow: 0 0 0 4px rgba(161,22,38,.1); }
      .input-group.is-invalid { border-color: var(--danger); }
      .input-group-text { display: inline-flex; align-items: center; justify-content: center; width: 46px; align-self: stretch; color: var(--crimson); font-size: 1rem; }
      .input-group input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: inherit; font-size: 14.5px; font-weight: 600; padding: 13px 16px 13px 0; color: var(--ink); }
      .input-group input::placeholder { color: #a9a39b; font-weight: 500; }
      /* Chrome paints an autofilled field light blue, which shows as a box inside the pill — keep it white */
      .input-group input:-webkit-autofill, .input-group input:-webkit-autofill:hover, .input-group input:-webkit-autofill:focus {
        -webkit-box-shadow: 0 0 0 1000px #fff inset; -webkit-text-fill-color: var(--ink); caret-color: var(--ink); border-radius: 999px; transition: background-color 9999s ease-out; }
      .password-toggle { border: 0; background: transparent; color: var(--muted); cursor: pointer; padding: 0 16px 0 10px; align-self: stretch; }
      .password-toggle:hover { color: var(--ink); }
      .auth-options { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 4px 4px 22px; font-size: 13px; }
      .form-check { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
      .form-check-input { width: 15px; height: 15px; accent-color: var(--crimson); }
      .form-check-label { color: var(--muted); }
      form > .form-check { margin: 4px 0 4px; }                 /* the register page's terms box stands alone */
      .auth-link { color: var(--ink); font-weight: 700; text-decoration: underline; text-underline-offset: 3px; }
      .auth-link:hover { color: var(--crimson); }
      .btn-auth { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 50px; border: 0; border-radius: 999px;
        background: var(--crimson); color: #fff; font: inherit; font-size: 15px; font-weight: 700; cursor: pointer; transition: background .18s, transform .18s; box-shadow: 0 10px 24px rgba(161,22,38,.22); }
      .btn-auth:hover { background: var(--crimson-lo); }
      .btn-auth:active { transform: translateY(1px); }
      .btn-auth .bi { display: none; }
      .validation-message { display: block; min-height: 1em; margin-top: 5px; color: var(--danger); font-size: 12px; }
      .success-message { display: block; text-align: center; margin-top: 12px; color: #1c7a4f; font-size: 13px; font-weight: 600; }
      .auth-footer-link { text-align: center; margin: 22px 0 0; font-size: 13.5px; color: var(--muted); }
      /* the other action: a small prompt, then an outlined pill (white, crimson text + stroke) */
      .auth-alt { margin-top: 22px; text-align: center; }
      .auth-alt-text { display: block; font-size: 13px; color: var(--muted); margin-bottom: 10px; }
      .btn-auth-outline { background: #fff; color: var(--crimson); border: 1.5px solid var(--crimson); box-shadow: none; text-decoration: none; }
      .btn-auth-outline:hover { background: rgba(161,22,38,.06); color: var(--crimson-lo); border-color: var(--crimson-lo); }

      /* ---- phone: the crimson panel becomes a short header strip ---- */
      @media (max-width: 860px) {
        html, body { height: auto; min-height: 100%; overflow-x: hidden; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .auth-split { grid-template-columns: 1fr; min-height: 0; }
        /* a short strip, not a tall panel: the form must start high enough that the
           password box stays above the keyboard (in-app browsers do not scroll for it) */
        .auth-hero { padding: 16px 20px 18px; flex-direction: row; align-items: center; justify-content: space-between; gap: 14px; }
        .auth-hero-logo { height: 22px; }
        .auth-hero-copy { max-width: none; }
        .auth-hero-title { font-size: 20px; margin: 0; line-height: 1.15; }
        .auth-hero-title br { display: none; }
        .auth-hero-sub { display: none; }
        .auth-hero-foot { display: none; }
        .auth-hero-arcs { right: -40%; top: -60%; width: 160%; height: 220%; }
        .auth-panel { justify-content: flex-start; padding: 16px 20px max(48px, env(safe-area-inset-bottom)); }
        .auth-back { position: static; align-self: flex-start; margin-bottom: 14px; min-height: 32px; }
        .auth-heading { margin-bottom: 18px; }
        .auth-brand { display: none; }
        .auth-title { font-size: 26px; }
        .form-group { scroll-margin-bottom: 120px; }
        .auth-brand { display: none; }
        .auth-card { max-width: 480px; margin: 0 auto; }
      }
    </style>
  </head>
  <body class="customer-register-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="../logo/Logo Header 3.png" alt="" />
        <div class="auth-hero-copy">
          <h2 class="auth-hero-title">Join <br />VENUSeP! <span class="auth-wave">&#128075;</span></h2>
          <p class="auth-hero-sub">One account for every campus venue and hostel bed. Book, track your requests and pay online &mdash; all in one place.</p>
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="customerRegisterTitle">
        <a class="auth-back" href="venusep_venue_booking.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to home</a>
        <div class="auth-card">
          <a class="auth-brand" href="venusep_venue_booking.php" title="Back to VENUSeP"><img src="../logo/Logo Header 3.png" alt="VENUSeP" /></a>
          <div class="auth-heading">
            <h1 class="auth-title" id="customerRegisterTitle">Create your account</h1>
            <p class="auth-subtitle">It takes less than a minute.</p>
          </div>

          <!-- On success the server has created the account; then to the login page. -->
          <form id="customerRegisterForm" action="" method="post" novalidate data-auth-form="register" data-redirect="customer-login.php" data-success-target="registrationSuccessMessage" data-success-message="Account created successfully. Redirecting to login...">
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
          <div class="auth-alt">
            <span class="auth-alt-text">Already have an account?</span>
            <a class="btn-auth btn-auth-outline" href="customer-login.php">Login</a>
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
        const CSRF = <?php echo json_encode($crCsrf); ?>;
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
            body.append('csrf', CSRF);
            body.append('kind', 'customer');
            fetch('../register-submit.php', { method: 'POST', body: body, credentials: 'same-origin' })
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
