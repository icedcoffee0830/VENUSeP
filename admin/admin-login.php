<?php
declare(strict_types=1);

session_start();

/*
 * VENUSeP database connection
 * XAMPP defaults: MySQL user = root, password = empty.
 * Change these values if your local MySQL setup is different.
 */
$dbHost = '127.0.0.1';
$dbName = 'venusep';
$dbUser = 'root';
$dbPass = '';

$loginError = '';

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
                session_regenerate_id(true);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Admin Login</title>
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
        max-width: 440px;
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

      /* department picker — two selectable cards */
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
      .department-card input:checked + .department-copy,
      .department-card-header:has(input:checked) { border-color: var(--black); box-shadow: 0 0 0 1px var(--black) inset; }
      .department-options.is-invalid .department-card-header { border-color: var(--danger); }

      /* inputs with a leading icon + optional trailing password toggle */
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

      .btn-auth {
        width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        min-height: 46px; border: 0; border-radius: 12px;
        background: var(--black); color: #fff; font: inherit; font-size: 0.92rem; font-weight: 700;
        cursor: pointer; transition: background .18s;
      }
      .btn-auth:hover { background: #000; }

      .validation-message { display: block; min-height: 1em; margin-top: 5px; color: var(--danger); font-size: 0.72rem; }
      .success-message { display: block; text-align: center; margin-top: 12px; color: #1c7a4f; font-size: 0.82rem; font-weight: 600; }
      .success-message.is-error { color: var(--danger); }
      .auth-footer-link { text-align: center; margin: 18px 0 0; font-size: 0.85rem; color: var(--muted); }

      @media (max-width: 480px) {
        .auth-card { padding: 24px 20px; }
        .department-options { grid-template-columns: 1fr; }
      }
    </style>
  </head>
  <body class="admin-login-page">
    <main class="login-page">
      <section class="login-shell" aria-labelledby="adminLoginTitle">
        <div class="auth-card">
          <div class="auth-brand"><img src="../logo/Logo Header 3.png" alt="VENUSeP" /></div>
          <div class="auth-heading">
            <h1 class="auth-title" id="adminLoginTitle">CHANGE Login</h1>
            <p class="auth-subtitle">Sign in to manage venue bookings.</p>
          </div>

          <!-- Server-side authentication is handled at the top of this PHP file. -->
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

            <button type="submit" class="btn-auth"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Sign In</button>
            <small class="success-message<?php echo $loginError !== '' ? ' is-error' : ''; ?>" id="loginSuccessMessage" aria-live="polite"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></small>
          </form>
          <p class="auth-footer-link">Don&rsquo;t have an admin account? <a href="admin-register.php" class="auth-link">Register</a></p>
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
