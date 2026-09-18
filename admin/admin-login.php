<?php
declare(strict_types=1);

/* The connection + credentials live in ONE place: includes/db.php. */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

venusep_session_start();

/* Already logged in on the staff side — no reason to see the form again. */
if (isset($_SESSION['user_id'], $_SESSION['account_type'])
    && in_array($_SESSION['account_type'], ['admin', 'staff'], true)) {
    header('Location: Admin_Dashboard.php');
    exit;
}

$loginError = '';

$pdo = venusep_db();
if ($pdo === null) {
    // Do not expose database credentials/errors to users.
    $loginError = 'Unable to connect to the database. Please contact the system administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $selectedDepartment = (string)($_POST['department'] ?? '');

    if ($loginError === '') {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $loginError = 'Please enter a valid email address.';
        } elseif ($password === '') {
            $loginError = 'Please enter your password.';
        } else {
            /*
             * The schema's users table stores:
             *   email, password_hash, account_type, is_active, last_login_at
             *
             * There is NO department column in users, so the department
             * selection is retained as a UI field but is not used as a
             * database credential. The actual authorization is account_type.
             */
            $stmt = $pdo->prepare(
                'SELECT id, email, username, password_hash, account_type, is_active
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (
                !$user ||
                !(bool)$user['is_active'] ||
                !in_array($user['account_type'], ['admin', 'staff'], true) ||
                !password_verify($password, $user['password_hash'])
            ) {
                $loginError = 'Invalid email or password.';
            } else {
                // Upgrade a valid legacy hash automatically when needed.
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $updateHash = $pdo->prepare(
                        'UPDATE users SET password_hash = :password_hash WHERE id = :id'
                    );
                    $updateHash->execute([
                        'password_hash' => $newHash,
                        'id' => $user['id'],
                    ]);
                }

                // Record the successful login using the schema's last_login_at.
                $updateLogin = $pdo->prepare(
                    'UPDATE users SET last_login_at = NOW() WHERE id = :id'
                );
                $updateLogin->execute(['id' => $user['id']]);

                // Secure PHP session instead of browser sessionStorage.
                // Start from an empty session so nothing from a previous login
                // (e.g. a customer on the same browser) survives into this one.
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['account_type'] = $user['account_type'];
                $_SESSION['department'] = $selectedDepartment;

                // Redirect only after server-side authentication succeeds.
                header('Location: Admin_Dashboard.php');
                exit;
            }
        }
    }
}
?>
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
      .auth-panel { display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 48px clamp(24px, 6vw, 88px); }   /* the card sits centred in the panel */
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
        .auth-panel { padding: 22px 20px 40px; }
        .auth-heading { margin-bottom: 18px; }
        .auth-brand { display: none; }
        .auth-title { font-size: 26px; }
        .form-group { scroll-margin-bottom: 120px; }
        .auth-brand { display: none; }
        .auth-card { max-width: 480px; margin: 0 auto; }
      }
      /* ---- admin only: the department picker (two radio cards) ---- */
      .department-label { display: block; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); margin: 0 0 8px 4px; }
      .department-options { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
      .department-card { display: block; cursor: pointer; margin: 0; }
      .department-card-header { position: relative; display: flex; align-items: center; gap: 10px; padding: 11px 12px; border: 1.5px solid var(--border); border-radius: 16px; background: #fff; transition: border-color .18s, box-shadow .18s; }
      .department-card input { position: absolute; opacity: 0; pointer-events: none; }
      .department-icon { font-size: 1.15rem; color: var(--crimson); flex: none; }
      .department-copy { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
      .department-copy strong { font-size: 13px; color: var(--ink); }
      .department-copy span { font-size: 12px; color: var(--muted); }
      .department-card:hover .department-card-header { border-color: #bdb7ac; }
      .department-card-header:has(input:checked) { border-color: var(--crimson); box-shadow: 0 0 0 3px rgba(161,22,38,.12); }
      .department-options.is-invalid .department-card-header { border-color: var(--danger); }
      @media (max-width: 420px) { .department-options { grid-template-columns: 1fr; } }
    </style>
  </head>
  <body class="admin-login-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="../logo/Logo Header 3.png" alt="" />
        <div class="auth-hero-copy">
          <h2 class="auth-hero-title">Welcome to <br />VENUSeP! <span class="auth-wave">&#128075;</span></h2>
          <p class="auth-hero-sub">The staff side: review booking requests, confirm payments, and keep the venues and hostel beds up to date.</p>
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="adminLoginTitle">
        <div class="auth-card">
          <a class="auth-brand" href="admin-login.php" title="VENUSeP staff"><img src="../logo/Logo Header 3.png" alt="VENUSeP" /></a>
          <div class="auth-heading">
            <h1 class="auth-title" id="adminLoginTitle">Staff sign in</h1>
            <p class="auth-subtitle">Sign in to manage venue bookings.</p>
          </div>

          <!-- the form below is unchanged: same names, same POST, same PHP -->
          <form id="adminLoginForm" action="" method="post" novalidate data-auth-form="login">
            <div class="form-group">
              <span class="department-label">Department</span>
              <div class="department-options" role="radiogroup" aria-describedby="departmentValidation">
                <label class="department-card" for="loginDepartmentUsep">
                  <span class="department-card-header">
                    <span class="department-icon"><i class="bi bi-building" aria-hidden="true"></i></span>
                    <span class="department-copy"><strong>USeP Venues</strong><span>University Venue Management</span></span>
                    <input type="radio" name="department" id="loginDepartmentUsep" value="usep-venues" checked required />
                  </span>
                </label>
                <label class="department-card" for="loginDepartmentAlumni">
                  <span class="department-card-header">
                    <span class="department-icon"><i class="bi bi-house" aria-hidden="true"></i></span>
                    <span class="department-copy"><strong>Bahay Alumni</strong><span>Alumni House Management</span></span>
                    <input type="radio" name="department" id="loginDepartmentAlumni" value="bahay-alumni" required />
                  </span>
                </label>
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
              <a href="#" class="auth-link">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-auth"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Login</button>
            <small class="success-message<?php echo $loginError !== '' ? ' is-error' : ''; ?>" id="loginSuccessMessage" aria-live="polite"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></small>
          </form>
          <div class="auth-alt">
            <span class="auth-alt-text">Don&rsquo;t have an admin account yet?</span>
            <a class="btn-auth btn-auth-outline" href="admin-register.php">Register</a>
          </div>
        </div>
      </section>
    </main>

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('adminLoginForm');

        const setMessage = function (id, message) {
          const el = document.getElementById(id);
          if (el) el.textContent = message;
        };

        const setFieldState = function (field, messageId, message) {
          if (!field) return;
          let target = field;
          if (field.matches('[type="radio"]')) {
            target = field.closest('.department-options');
          } else if (field.type !== 'checkbox') {
            target = field.closest('.input-group');
          }
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

        if (!form) return;

        form.querySelectorAll('input').forEach(function (field) {
          field.addEventListener(
            field.type === 'checkbox' || field.type === 'radio' ? 'change' : 'input',
            function () {
              setFieldState(field, field.getAttribute('aria-describedby'), '');
            }
          );
        });

        // The browser validates the fields; PHP performs the real authentication.
        form.addEventListener('submit', function (event) {
          const email = form.querySelector('[name="email"]');
          const password = form.querySelector('[name="password"]');
          const department = form.querySelector('[name="department"]');
          let hasError = false;

          setMessage('loginSuccessMessage', '');
          const loginMessage = document.getElementById('loginSuccessMessage');
          if (loginMessage) loginMessage.classList.remove('is-error');

          const selectedDepartment = form.querySelector('[name="department"]:checked');
          if (department) {
            const error = selectedDepartment ? '' : 'Please select a department.';
            setFieldState(department, 'departmentValidation', error);
            hasError = hasError || Boolean(error);
          }

          let emailError = '';
          if (!email.value.trim()) {
            emailError = 'Please enter your email address.';
          } else if (!email.checkValidity()) {
            emailError = 'Please enter a valid email address.';
          } else if (email.value.trim().length > 100) {
            emailError = 'Email address is too long.';
          }
          setFieldState(email, 'emailValidation', emailError);
          hasError = hasError || Boolean(emailError);

          let passwordError = '';
          if (!password.value) {
            passwordError = 'Please enter your password.';
          } else if (password.value.length < 8) {
            passwordError = 'Password must contain at least 8 characters.';
          } else if (password.value.length > 64) {
            passwordError = 'Password must not exceed 64 characters.';
          }
          setFieldState(password, 'passwordValidation', passwordError);
          hasError = hasError || Boolean(passwordError);

          if (hasError) {
            event.preventDefault();
            const firstInvalid = form.querySelector('[aria-invalid="true"]');
            if (firstInvalid) firstInvalid.focus();
          }
          // Otherwise allow normal POST to this PHP page.
        });
      });
    </script>
  </body>
</html>
