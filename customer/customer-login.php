<?php
declare(strict_types=1);

/*
 * VENUSeP customer authentication
 * --------------------------------
 * The connection + credentials live in ONE place: includes/db.php.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/two-factor.php';
require_once __DIR__ . '/../includes/two-factor-views.php';
require_once __DIR__ . '/../includes/passwords.php';   /* venusep_password_upgrade() — Argon2id (#21) */
require_once __DIR__ . '/../includes/accounts.php';    /* cl_finish_login() — the only place a customer session is created */
require_once __DIR__ . '/../includes/google-auth.php'; /* sign-in with Google (#23) */

venusep_session_start();

$pdo = venusep_db();
if ($pdo === null) {
    http_response_code(500);
    exit('Database connection failed.');
}

$loginError = '';

/* Already signed in — including a device remembered by "Remember me" (#23),
   which customer_logged_in() signs back in here — straight to the booking page. */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && customer_logged_in()) {
    header('Location: venusep_venue_booking.php');
    exit;
}

/* One sentence left by another page (the Google callback, mostly), shown once. */
$loginNotice = is_string($_SESSION['login_notice'] ?? null) ? $_SESSION['login_notice'] : '';
unset($_SESSION['login_notice']);

/* A two-step sign-in step, only for customers who turned it on in their
   profile (DB-DECISIONS #20); the flow itself is tfa_login_step(). */
$tfaError = '';
$googleErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tfa_step'])) {
    [$loginError, $tfaError] = tfa_login_step($pdo, 'customer', 'customer-login.php',
        function (array $pending) use ($pdo) {
            cl_finish_login($pdo, (int)$pending['user_id'], !empty($pending['remember']));
        });
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['google_step'])) {
    /* The one-time "finish your account" screen for a brand-new Google identity
       (google-callback.php put it in the session). The name, email and Google id
       come from the SESSION, never from this form, except the name they may edit. */
    $signup = google_signup_pending();
    if ($signup === null) {
        $loginNotice = 'That sign-up timed out. Choose Continue with Google again.';
    } elseif (!csrf_valid($_POST['csrf'] ?? null)) {
        $googleErrors['form'] = 'This page expired. Try again.';
    } elseif ($_POST['google_step'] === 'cancel') {
        unset($_SESSION['google_signup']);
        header('Location: customer-login.php');
        exit;
    } else {
        require_once __DIR__ . '/../includes/bookings.php';   /* cb_normalise_mobile() — the same rule as registration */
        $gName  = trim((string)($_POST['full_name'] ?? ''));
        $gPhone = trim((string)($_POST['contact_number'] ?? ''));
        $gPhoneNorm = $gPhone === '' ? null : cb_normalise_mobile($gPhone);
        if ($gName === '' || mb_strlen($gName) > 190) {
            $googleErrors['full_name'] = 'Enter your full name.';
        }
        if ($gPhone !== '' && $gPhoneNorm === null) {
            $googleErrors['contact_number'] = 'Enter a mobile number in the form 09XX XXX XXXX.';
        }
        if (empty($_POST['terms'])) {
            $googleErrors['terms'] = 'You must agree to the Terms and Conditions.';
        }
        if (!$googleErrors) {
            try {
                $newUserId = google_create_customer($pdo, (string)$signup['sub'], (string)$signup['email'], $gName, $gPhoneNorm);
            } catch (PDOException $e) {
                unset($_SESSION['google_signup']);
                if ($e->getCode() === '23000') {
                    /* The email (or this Google account) was registered between
                       Google's reply and this click. Same rule as the callback. */
                    $loginNotice = 'This email is already registered. Log in with your email and password. You can connect Google from your profile afterwards.';
                } else {
                    error_log('VENUSeP Google sign-up: ' . $e->getMessage());
                    $loginNotice = 'Something went wrong, so the account was not created. Please try again.';
                }
                $newUserId = null;
            }
            if ($newUserId !== null) {
                cl_finish_login($pdo, $newUserId, !empty($signup['remember']));   // never returns
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        if ($customer && $customer['is_active'] && $customer['password_hash'] === null) {
            /* Created through Google, no password set yet. Saying so reveals only
               that the email is registered, which the sign-up form already does,
               and spares them guessing at a password that does not exist. */
            $loginError = google_enabled()
                ? 'This account uses Google sign-in. Use Continue with Google below.'
                : 'This account uses Google sign-in, which is not set up on this server yet.';
        } elseif (!$customer || !$customer['is_active'] || !password_verify($password, $customer['password_hash'])) {
            // Use one generic message so the page does not reveal whether an email exists.
            $loginError = 'Invalid email or password.';
        } else {
            // Re-hash an old bcrypt (or older-settings) hash to Argon2id (DB-DECISIONS #21).
            venusep_password_upgrade($pdo, (int)$customer['user_id'], $password, $customer['password_hash']);
            /* "Remember me" rides along through the code step (it is in the pending
               state), so the device is only remembered once the code is passed too. */
            tfa_after_password($pdo, (int)$customer['user_id'], 'customer', 'customer', $customer['email'],
                ['remember' => $rememberMe], 'customer-login.php',
                function () use ($pdo, $customer, $rememberMe) {
                    cl_finish_login($pdo, (int)$customer['user_id'], $rememberMe);
                });
        }
    }
}

/* Password correct, code pending: not logged in yet (customer_logged_in() needs user_id). */
$pending = tfa_pending('customer');
$tfaView = $pending === null ? 'password' : 'verify';
/* A brand-new Google identity waiting for its one-time "finish your account" screen. */
$googleSignup = $tfaView === 'password' ? google_signup_pending() : null;
if ($googleSignup !== null) {
    $tfaView = 'google_signup';
}
if ($tfaView !== 'password') {
    header('Cache-Control: no-store');
}
$tfaCsrf = $tfaView !== 'password' ? csrf_token() : '';
$googleOn = google_enabled();
$tfaLock = $tfaView === 'verify' ? tfa_lock_seconds($pdo, (int)$pending['user_id']) : 0;   // resume a running lock's countdown
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
    <link rel="stylesheet" href="../assets/css/two-factor.css" />
    <link rel="stylesheet" href="../assets/css/customer-auth.css" />   <!-- the auth page look, shared with forgot/reset password -->
  </head>
  <body class="customer-login-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="../logo/Logo Header 3.png" alt="" />
        <div class="auth-hero-copy">
          <?php if ($tfaView !== 'verify'): ?>
          <h2 class="auth-hero-title">Welcome to <br />VENUSeP! <span class="auth-wave">&#128075;</span></h2>
          <p class="auth-hero-sub">Reserve campus venues and hostel beds online. Check real availability, book in minutes, and pay by GCash or cash &mdash; no office visits.</p>
          <?php else: tfa_view_hero('verify'); endif; ?>
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="<?php echo $tfaView === 'password' ? 'customerLoginTitle' : ($tfaView === 'google_signup' ? 'googleSignupTitle' : 'tfaTitle'); ?>">
        <a class="auth-back" href="venusep_venue_booking.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to home</a>
        <div class="auth-card">
          <a class="auth-brand" href="venusep_venue_booking.php" title="Back to VENUSeP"><img src="../logo/Logo Header 3.png" alt="VENUSeP" /></a>
          <?php if ($tfaView === 'verify'): tfa_view_code($tfaCsrf, (string)$pending['email'], $tfaError, $tfaLock); ?>
          <?php elseif ($tfaView === 'google_signup'):
            /* The one-time "finish your account" screen (#23). The email is Google's
               and cannot be changed here; the name is pre-filled and editable. */
            $gsName  = isset($_POST['full_name']) ? (string)$_POST['full_name'] : (string)$googleSignup['name'];
            $gsPhone = isset($_POST['contact_number']) ? (string)$_POST['contact_number'] : '';
            $gsErr   = function (string $field) use ($googleErrors): string {
                return htmlspecialchars($googleErrors[$field] ?? '', ENT_QUOTES, 'UTF-8');
            };
          ?>
          <div class="auth-heading">
            <h1 class="auth-title" id="googleSignupTitle">Finish creating your account</h1>
            <p class="auth-subtitle">Google confirmed your email. One last step before you can book.</p>
          </div>
          <?php if (!empty($googleErrors['form'])): ?><p class="auth-notice" role="alert"><?= $gsErr('form') ?></p><?php endif; ?>
          <form id="googleSignupForm" action="customer-login.php" method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($tfaCsrf, ENT_QUOTES, 'UTF-8') ?>" />
            <div class="form-group">
              <div class="auth-readonly"><i class="bi bi-google" aria-hidden="true"></i><span><span class="form-label">Email address: </span><?= htmlspecialchars((string)$googleSignup['email'], ENT_QUOTES, 'UTF-8') ?></span></div>
              <small class="auth-hint">From your Google account. You&rsquo;ll sign in with Google, so you don&rsquo;t need a VENUSeP password.</small>
            </div>
            <div class="form-group">
              <label for="gsFullName" class="form-label">Full name</label>
              <div class="input-group<?= !empty($googleErrors['full_name']) ? ' is-invalid' : '' ?>"><span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span><input id="gsFullName" name="full_name" type="text" autocomplete="name" placeholder="Full name" maxlength="190" value="<?= htmlspecialchars($gsName, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="gsFullNameMsg" required /></div>
              <small class="validation-message" id="gsFullNameMsg" aria-live="polite"><?= $gsErr('full_name') ?></small>
            </div>
            <div class="form-group">
              <label for="gsPhone" class="form-label">Mobile number (optional)</label>
              <div class="input-group<?= !empty($googleErrors['contact_number']) ? ' is-invalid' : '' ?>"><span class="input-group-text"><i class="bi bi-phone" aria-hidden="true"></i></span><input id="gsPhone" name="contact_number" type="tel" autocomplete="tel" inputmode="tel" placeholder="Mobile number (optional)" maxlength="20" value="<?= htmlspecialchars($gsPhone, ENT_QUOTES, 'UTF-8') ?>" aria-describedby="gsPhoneMsg gsPhoneHint" /></div>
              <small class="auth-hint" id="gsPhoneHint">09XX XXX XXXX. Used only if a GCash refund ever has to be sent to you.</small>
              <small class="validation-message" id="gsPhoneMsg" aria-live="polite"><?= $gsErr('contact_number') ?></small>
            </div>
            <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="gsTerms" name="terms" aria-describedby="gsTermsMsg" required <?= !empty($_POST['terms']) ? 'checked' : '' ?> /><span class="form-check-label">I agree to the Terms and Conditions</span></label>
            <small class="validation-message" id="gsTermsMsg" aria-live="polite"><?= $gsErr('terms') ?></small>
            <button type="submit" name="google_step" value="create" class="btn-auth">Create my account</button>
          </form>
          <form class="auth-alt" action="customer-login.php" method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($tfaCsrf, ENT_QUOTES, 'UTF-8') ?>" />
            <span class="auth-alt-text">Not you, or changed your mind?</span>
            <button type="submit" name="google_step" value="cancel" class="btn-auth btn-auth-outline" formnovalidate>Cancel</button>
          </form>
          <?php else: ?>
          <div class="auth-heading">
            <h1 class="auth-title" id="customerLoginTitle">Welcome back!</h1>
            <p class="auth-subtitle">Sign in to book venues and hostel beds.</p>
          </div>
          <?php if ($loginNotice !== ''): ?><p class="auth-notice" role="status"><?= htmlspecialchars($loginNotice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

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
              <label class="form-check"><input class="form-check-input" type="checkbox" value="1" id="rememberCustomer" name="remember_me" /><span class="form-check-label" title="Stay signed in on this device for 15 days. Don't tick it on a shared computer.">Remember me</span></label>
              <a href="forgot-password.php" class="auth-link">Forgot Password?</a>
            </div>
            <button type="submit" class="btn-auth"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Login</button>
            <?php if ($loginError !== ''): ?><small class="validation-message" id="loginServerError" aria-live="assertive"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
          </form>
          <?php if ($googleOn): ?>
          <div class="auth-divider">or</div>
          <a class="btn-auth btn-google" href="google-start.php" id="googleSignIn"><?php readfile(__DIR__ . '/../assets/img/google-g.svg'); ?>Continue with Google</a>
          <?php endif; ?>
          <div class="auth-alt">
            <span class="auth-alt-text">Don&rsquo;t have an account yet?</span>
            <a class="btn-auth btn-auth-outline" href="customer-register.php">Create an account</a>
          </div>
          <?php endif; ?>
        </div>
      </section>
    </main>
    <?php if ($tfaView !== 'password'): ?>
    <script src="../assets/js/two-factor.js"></script>
    <?php endif; ?>

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
            googleSignIn.href = 'google-start.php' + (rememberBox.checked ? '?remember=1' : '');
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
