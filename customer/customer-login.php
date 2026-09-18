<?php
declare(strict_types=1);

/*
 * VENUSeP customer authentication
 * --------------------------------
 * The connection + credentials live in ONE place: includes/db.php.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

venusep_session_start();

$pdo = venusep_db();
if ($pdo === null) {
    http_response_code(500);
    exit('Database connection failed.');
}

$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rememberMe = isset($_POST['remember_me']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $loginError = 'Please enter a valid email address.';
    } elseif ($password === '') {
        $loginError = 'Please enter your password.';
    } else {
        /*
         * A customer login is a users row (account_type='customer')
         * connected to exactly one customers row through customers.user_id.
         */
        $stmt = $pdo->prepare(
            'SELECT
                u.id AS user_id,
                u.email,
                u.password_hash,
                u.is_active,
                c.id AS customer_id,
                c.full_name,
                c.phone,
                c.address,
                c.university_id_no
             FROM users u
             INNER JOIN customers c ON c.user_id = u.id
             WHERE u.email = :email
               AND u.account_type = :account_type
             LIMIT 1'
        );

        $stmt->execute([
            ':email' => $email,
            ':account_type' => 'customer',
        ]);

        $customer = $stmt->fetch();

        if (!$customer || !$customer['is_active'] || !password_verify($password, $customer['password_hash'])) {
            // Use one generic message so the page does not reveal whether an email exists.
            $loginError = 'Invalid email or password.';
        } else {
            // Prevent session fixation after successful authentication.
            session_regenerate_id(true);

            // Start from an empty session so nothing from a previous login
            // (e.g. an admin on the same browser) survives into this one.
            $_SESSION = [];
            $_SESSION['user_id'] = (int)$customer['user_id'];
            $_SESSION['customer_id'] = (int)$customer['customer_id'];
            $_SESSION['account_type'] = 'customer';
            $_SESSION['customer_name'] = $customer['full_name'];
            $_SESSION['customer_email'] = $customer['email'];

            $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
            $update->execute([':id' => $customer['user_id']]);

            // Remember Me is intentionally not implemented with a permanent
            // cookie here; the session remains the safer default.
            header('Location: venusep_venue_booking.php');
            exit;
        }
    }
}
?>
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
        .auth-panel { padding: 16px 20px 40px; }
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
  <body class="customer-login-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="../logo/Logo Header 3.png" alt="" />
        <div class="auth-hero-copy">
          <h2 class="auth-hero-title">Welcome to <br />VENUSeP! <span class="auth-wave">&#128075;</span></h2>
          <p class="auth-hero-sub">Reserve campus venues and hostel beds online. Check real availability, book in minutes, and pay by GCash or cash &mdash; no office visits.</p>
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="customerLoginTitle">
        <a class="auth-back" href="venusep_venue_booking.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to home</a>
        <div class="auth-card">
          <a class="auth-brand" href="venusep_venue_booking.php" title="Back to VENUSeP"><img src="../logo/Logo Header 3.png" alt="VENUSeP" /></a>
          <div class="auth-heading">
            <h1 class="auth-title" id="customerLoginTitle">Welcome back!</h1>
            <p class="auth-subtitle">Sign in to book venues and hostel beds.</p>
          </div>

          <!-- the form below is unchanged: same names, same POST, same PHP error output -->
          <form id="customerLoginForm" action="" method="post" novalidate data-auth-form="login">
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
              <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="rememberCustomer" name="remember_me" /><span class="form-check-label">Remember Me</span></label>
              <a href="#" class="auth-link">Forgot Password?</a>
            </div>
            <button type="submit" class="btn-auth"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Login</button>
            <?php if ($loginError !== ''): ?><small class="validation-message" id="loginServerError" aria-live="assertive"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
          </form>
          <div class="auth-alt">
            <span class="auth-alt-text">Don&rsquo;t have an account yet?</span>
            <a class="btn-auth btn-auth-outline" href="customer-register.php">Create an account</a>
          </div>
        </div>
      </section>
    </main>

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
