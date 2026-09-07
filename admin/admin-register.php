<?php ?>
<!DOCTYPE html>
<!-- ==================================================================
  ADMIN REGISTRATION — VENUSeP merged system
  ==================================================================
  Ported from the teammate's admin-register.php. Standalone AUTH page:
  NO sidebar/header. Restyled to the team palette; fields + validation
  preserved (department, full name, email, contact, password + confirm,
  terms).

  [SIM] front-end mockup only — no account is created. On valid input it
  shows a message and redirects to the login page. Replace the inline
  script with real PHP registration + database insert later.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Admin Registration</title>
    <link rel="icon" href="../logo/Logo Header 3.png" type="image/png" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <style>
      /* ---- shared AUTH styles (same block in admin-login + admin-register) ---- */
      :root {
        --black: #1f1e1e;
        --border: #e0dcd4;
        --muted: #6e6a64;
        --danger: #b23a3a;
        --radius: 14px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body {
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        color: var(--black);
        background: #f4f2ee;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 32px 16px;
      }

      .auth-card {
        width: 100%;
        max-width: 460px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 20px;
        box-shadow: 0 12px 40px rgba(31, 30, 30, 0.08);
        padding: 30px 30px 26px;
      }
      .auth-brand { display: flex; justify-content: center; margin-bottom: 6px; }
      .auth-brand img { height: 54px; width: auto; object-fit: contain; }
      .auth-heading { text-align: center; margin-bottom: 20px; }
      .auth-title { font-size: 1.4rem; font-weight: 800; margin: 6px 0 3px; }
      .auth-subtitle { color: var(--muted); font-size: 0.88rem; margin: 0; }

      .form-group { margin-bottom: 14px; }
      .form-label, .department-label { display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 6px; }

      .department-options { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
      .department-card { display: block; cursor: pointer; margin: 0; }
      .department-card-header {
        display: flex; align-items: center; gap: 9px;
        border: 1px solid var(--border); border-radius: 12px;
        padding: 11px 12px; transition: .18s; height: 100%;
      }
      .department-card input { position: absolute; opacity: 0; pointer-events: none; }
      .department-icon { font-size: 1.15rem; color: var(--black); flex: none; }
      .department-copy { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
      .department-copy strong { font-size: 0.82rem; }
      .department-copy span { font-size: 0.68rem; color: var(--muted); }
      .department-card:hover .department-card-header { border-color: #bdb7ac; }
      .department-card-header:has(input:checked) { border-color: var(--black); box-shadow: 0 0 0 1px var(--black) inset; }
      .department-options.is-invalid .department-card-header { border-color: var(--danger); }

      .input-group { display: flex; align-items: center; border: 1px solid var(--border); border-radius: 11px; background: #fff; overflow: hidden; transition: .18s; }
      .input-group:focus-within { border-color: var(--black); box-shadow: 0 0 0 3px rgba(31, 30, 30, 0.08); }
      .input-group.is-invalid { border-color: var(--danger); }
      .input-group-text { display: inline-flex; align-items: center; justify-content: center; width: 42px; align-self: stretch; color: var(--muted); font-size: 0.95rem; }
      .input-group input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: inherit; font-size: 0.92rem; padding: 11px 12px 11px 0; color: var(--black); }
      .password-toggle { border: 0; background: transparent; color: var(--muted); cursor: pointer; padding: 0 12px; align-self: stretch; }

      .form-check { display: inline-flex; align-items: center; gap: 7px; font-size: 0.82rem; }
      .form-check-input { width: 15px; height: 15px; accent-color: var(--black); flex: none; }
      .form-check-label { color: var(--muted); }
      .auth-link { color: var(--black); font-weight: 700; text-decoration: none; }
      .auth-link:hover { text-decoration: underline; }

      .btn-auth {
        width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        min-height: 46px; margin-top: 6px; border: 0; border-radius: 12px;
        background: var(--black); color: #fff; font: inherit; font-size: 0.92rem; font-weight: 700;
        cursor: pointer; transition: background .18s;
      }
      .btn-auth:hover { background: #000; }

      .validation-message { display: block; min-height: 1em; margin-top: 5px; color: var(--danger); font-size: 0.72rem; }
      .success-message { display: block; text-align: center; margin-top: 12px; color: #1c7a4f; font-size: 0.82rem; font-weight: 600; }
      .auth-footer-link { text-align: center; margin: 18px 0 0; font-size: 0.85rem; color: var(--muted); }

      @media (max-width: 480px) {
        .auth-card { padding: 24px 20px; }
        .department-options { grid-template-columns: 1fr; }
      }
    </style>
  </head>
  <body class="admin-register-page">
    <main class="login-page">
      <section class="login-shell" aria-labelledby="adminRegisterTitle">
        <div class="auth-card">
          <div class="auth-brand"><img src="../logo/Logo Header 3.png" alt="VENUSeP" /></div>
          <div class="auth-heading">
            <h1 class="auth-title" id="adminRegisterTitle">Admin Registration</h1>
            <p class="auth-subtitle">Create an administrator account for VENUSeP.</p>
          </div>

          <!-- [SIM] on valid input the script redirects to admin-login.php (no real account is created) -->
          <form id="adminRegisterForm" action="" method="post" novalidate data-auth-form="register" data-redirect="admin-login.php" data-success-target="registrationSuccessMessage" data-success-message="Account created successfully. Redirecting to login...">
            <div class="form-group">
              <span class="department-label">Department</span>
              <div class="department-options" role="radiogroup" aria-describedby="departmentValidation">
                <label class="department-card" for="registerDepartmentUsep">
                  <span class="department-card-header">
                    <span class="department-icon"><i class="bi bi-building" aria-hidden="true"></i></span>
                    <span class="department-copy"><strong>USeP Venues</strong><span>University Venue Management</span></span>
                    <input type="radio" name="department" id="registerDepartmentUsep" value="usep-venues" checked required />
                  </span>
                </label>
                <label class="department-card" for="registerDepartmentAlumni">
                  <span class="department-card-header">
                    <span class="department-icon"><i class="bi bi-house" aria-hidden="true"></i></span>
                    <span class="department-copy"><strong>Bahay Alumni</strong><span>Alumni House Management</span></span>
                    <input type="radio" name="department" id="registerDepartmentAlumni" value="bahay-alumni" required />
                  </span>
                </label>
              </div>
              <small class="validation-message" id="departmentValidation" aria-live="polite"></small>
            </div>

            <div class="form-group">
              <label for="adminFullName" class="form-label">Full Name</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span><input id="adminFullName" name="full_name" type="text" autocomplete="name" placeholder="Enter full name" aria-describedby="fullNameValidation" required /></div>
              <small class="validation-message" id="fullNameValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="adminEmail" class="form-label">Email Address</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span><input id="adminEmail" name="email" type="email" autocomplete="email" placeholder="admin@venusep.com" aria-describedby="emailValidation" required /></div>
              <small class="validation-message" id="emailValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="adminContactNumber" class="form-label">Contact Number</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-telephone" aria-hidden="true"></i></span><input id="adminContactNumber" name="contact_number" type="tel" autocomplete="tel" placeholder="09XXXXXXXXX" aria-describedby="contactNumberValidation" required /></div>
              <small class="validation-message" id="contactNumberValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="adminPassword" class="form-label">Password</label>
              <div class="input-group password-field"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input id="adminPassword" name="password" type="password" autocomplete="new-password" placeholder="Create password" minlength="8" aria-describedby="passwordValidation" required /><button class="password-toggle" type="button" data-password-toggle="adminPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="passwordValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="adminConfirmPassword" class="form-label">Confirm Password</label>
              <div class="input-group password-field"><span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><input id="adminConfirmPassword" name="confirm_password" type="password" autocomplete="new-password" placeholder="Confirm password" aria-describedby="confirmPasswordValidation" required /><button class="password-toggle" type="button" data-password-toggle="adminConfirmPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="confirmPasswordValidation" aria-live="polite"></small>
            </div>
            <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="adminTerms" name="terms" aria-describedby="termsValidation" required /><span class="form-check-label">I agree to the Terms and Conditions</span></label>
            <small class="validation-message" id="termsValidation" aria-live="polite"></small>

            <button type="submit" class="btn-auth"><i class="bi bi-person-plus" aria-hidden="true"></i>Create Account</button>
            <small class="success-message" id="registrationSuccessMessage" aria-live="polite"></small>
          </form>
          <p class="auth-footer-link">Already have an account? <a href="admin-login.php" class="auth-link">Login</a></p>
        </div>
      </section>
    </main>

    <!-- [SIM] front-end validation + fake redirect (ported from the teammate's auth.js).
         Replace with real PHP registration + database insert later. -->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const setMessage = function (id, message) {
          const el = document.getElementById(id);
          if (el) el.textContent = message;
        };
        const setFieldState = function (field, messageId, message) {
          if (!field) return;
          let target = field;
          if (field.matches('[type="radio"]')) target = field.closest('.department-options');
          else if (field.type !== 'checkbox') target = field.closest('.input-group');
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

        document.querySelectorAll('[data-auth-form]').forEach(function (form) {
          form.querySelectorAll('input').forEach(function (field) {
            field.addEventListener(field.type === 'checkbox' || field.type === 'radio' ? 'change' : 'input', function () {
              setFieldState(field, field.getAttribute('aria-describedby'), '');
            });
          });

          form.addEventListener('submit', function (event) {
            event.preventDefault();
            const email = form.querySelector('[name="email"]');
            const password = form.querySelector('[name="password"]');
            const department = form.querySelector('[name="department"]');
            let hasError = false;
            setMessage(form.dataset.successTarget, '');

            if (department) {
              const selected = form.querySelector('[name="department"]:checked');
              const message = selected ? '' : 'Please select a department.';
              setFieldState(department, 'departmentValidation', message);
              hasError = hasError || Boolean(message);
            }

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
            hasError = hasError || Boolean(fullNameError || contactError || passwordError || confirmError || termsError);

            const emailError = email.value.trim() && email.checkValidity() ? '' : 'Please enter a valid email address.';
            setFieldState(email, 'emailValidation', emailError);
            hasError = hasError || Boolean(emailError);

            if (hasError) {
              const firstInvalid = form.querySelector('[aria-invalid="true"]');
              if (firstInvalid) firstInvalid.focus();
              return;
            }

            // TODO: replace with real PHP registration (validate, hash password, insert row).
            setMessage(form.dataset.successTarget, form.dataset.successMessage);
            window.setTimeout(function () { window.location.href = form.dataset.redirect; }, 800);
          });
        });
      });
    </script>
  </body>
</html>
