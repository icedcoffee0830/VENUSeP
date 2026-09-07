<?php
declare(strict_types=1);

session_start();

/*
 * VENUSeP customer authentication
 * --------------------------------
 * Update these four values for your MySQL installation.
 * For production, move them to environment variables or a config file
 * outside the web root.
 */
$dbHost = 'localhost';
$dbName = 'venusep';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
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
                c.user_id AS customer_id,
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Customer Login</title>
    <link rel="icon" href="../logo/Logo Header 3.png" type="image/png" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <style>
      /* ---- shared AUTH styles (customer-login + customer-register) ---- */
      :root { --black: #1f1e1e; --border: #e0dcd4; --muted: #6e6a64; --danger: #b23a3a; }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body { font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--black); background: #f4f2ee; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 32px 16px; }
      .auth-card { width: 100%; max-width: 430px; background: #fff; border: 1px solid var(--border); border-radius: 20px; box-shadow: 0 12px 40px rgba(31, 30, 30, 0.08); padding: 30px 30px 26px; }
      .auth-brand { display: flex; justify-content: center; margin-bottom: 6px; }
      .auth-brand img { height: 54px; width: auto; object-fit: contain; }
      .auth-heading { text-align: center; margin-bottom: 20px; }
      .auth-title { font-size: 1.4rem; font-weight: 800; margin: 6px 0 3px; }
      .auth-subtitle { color: var(--muted); font-size: 0.88rem; margin: 0; }
      .form-group { margin-bottom: 14px; }
      .form-label { display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 6px; }
      .input-group { display: flex; align-items: center; border: 1px solid var(--border); border-radius: 11px; background: #fff; overflow: hidden; transition: .18s; }
      .input-group:focus-within { border-color: var(--black); box-shadow: 0 0 0 3px rgba(31, 30, 30, 0.08); }
      .input-group.is-invalid { border-color: var(--danger); }
      .input-group-text { display: inline-flex; align-items: center; justify-content: center; width: 42px; align-self: stretch; color: var(--muted); font-size: 0.95rem; }
      .input-group input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: inherit; font-size: 0.92rem; padding: 11px 12px 11px 0; color: var(--black); }
      .password-toggle { border: 0; background: transparent; color: var(--muted); cursor: pointer; padding: 0 12px; align-self: stretch; }
      .auth-options { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 4px 0 16px; font-size: 0.82rem; }
      .form-check { display: inline-flex; align-items: center; gap: 7px; }
      .form-check-input { width: 15px; height: 15px; accent-color: var(--black); }
      .form-check-label { color: var(--muted); }
      .auth-link { color: var(--black); font-weight: 700; text-decoration: none; }
      .auth-link:hover { text-decoration: underline; }
      .btn-auth { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; border: 0; border-radius: 12px; background: var(--black); color: #fff; font: inherit; font-size: 0.92rem; font-weight: 700; cursor: pointer; transition: background .18s; }
      .btn-auth:hover { background: #000; }
      .validation-message { display: block; min-height: 1em; margin-top: 5px; color: var(--danger); font-size: 0.72rem; }
      .success-message { display: block; text-align: center; margin-top: 12px; color: #1c7a4f; font-size: 0.82rem; font-weight: 600; }
      .auth-footer-link { text-align: center; margin: 18px 0 0; font-size: 0.85rem; color: var(--muted); }
      @media (max-width: 480px) { .auth-card { padding: 24px 20px; } }
    </style>
  </head>
  <body class="customer-login-page">
    <main class="login-page">
      <section class="login-shell" aria-labelledby="customerLoginTitle">
        <div class="auth-card">
          <div class="auth-brand"><img src="../logo/Logo Header 3.png" alt="VENUSeP" /></div>
          <div class="auth-heading">
            <h1 class="auth-title" id="customerLoginTitle">Customer Login</h1>
            <p class="auth-subtitle">Sign in to book and manage venue reservations.</p>
          </div>

          <!-- [SIM] on valid input the script redirects to the booking landing -->
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
            <button type="submit" class="btn-auth"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Sign In</button>
            <?php if ($loginError !== ''): ?><small class="validation-message" id="loginServerError" aria-live="assertive"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
          </form>
          <p class="auth-footer-link">Don&rsquo;t have an account? <a href="customer-register.php" class="auth-link">Create an account</a></p>
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
