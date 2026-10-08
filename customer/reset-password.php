<?php
declare(strict_types=1);
/* =====================================================================
   FORGOT PASSWORD, STEP 2 — the link from the email lands here.
   includes/password-reset.php explains the whole flow and its security.

   THE TOKEN LEAVES THE URL AT ONCE. The first visit checks it, moves the
   reset's id into the session, and redirects to this page without it, so
   the token is not left in the address bar, the history, or a screenshot.
   No referrer is ever sent from here either (header + meta), so it cannot
   leak to the fonts or icons this page loads.

   It does not sign anyone in: after the new password they log in, and the
   two-step code is still asked for if they turned it on.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/password-reset.php';
require_once __DIR__ . '/../includes/customer-auth-shell.php';

venusep_session_start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$pdo = venusep_db();

/* ---- the link itself: check it, then drop it from the URL ---- */
if (isset($_GET['token'])) {
    unset($_SESSION['pw_reset']);
    $found = $pdo !== null && is_string($_GET['token']) ? pr_find($pdo, $_GET['token']) : null;
    if ($found !== null) {
        session_regenerate_id(true);
        $_SESSION['pw_reset'] = ['id' => $found['id'], 'user_id' => $found['user_id']];
    }
    header('Location: reset-password.php');
    exit;
}

$state = $_SESSION['pw_reset'] ?? null;
$reset = ($pdo !== null && is_array($state)) ? pr_find_id($pdo, (int) $state['id'], (int) $state['user_id']) : null;

$rpErrors = [];
if ($reset !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new     = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $rpErrors['form'] = 'This page expired. Try again.';
    } elseif (strlen($new) < 8) {
        $rpErrors['new_password'] = 'Use a password of at least 8 characters.';
    } elseif ($new !== $confirm) {
        $rpErrors['confirm_password'] = 'The two passwords do not match.';
    } elseif (!venusep_password_ready()) {
        $rpErrors['form'] = VENUSEP_PASSWORD_SETUP_ERROR;
    } else {
        try {
            $done = pr_complete($pdo, $reset['id'], $reset['user_id'], $new);
        } catch (Throwable $e) {
            error_log('VENUSeP reset password: ' . $e->getMessage());
            $done = null;
            $rpErrors['form'] = 'Something went wrong, so your password was not changed. Please try again.';
        }
        if ($done === true) {
            unset($_SESSION['pw_reset']);
            $_SESSION['login_notice'] = 'Your password was changed. Log in with your new password.';
            header('Location: customer-login.php');
            exit;
        }
        if ($done === false) {
            $reset = null;   // used in another tab, replaced by a newer link, or expired meanwhile
        }
    }
}
if ($reset === null) {
    unset($_SESSION['pw_reset']);
}
$rpCsrf = csrf_token();
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$err = function (string $k) use ($rpErrors, $e) { return $e($rpErrors[$k] ?? ''); };

cas_open('Reset password', 'Choose a new <br />password', 'Use one you don’t use anywhere else. You’ll log in with it straight after.', 'resetTitle');
?>
          <?php if ($reset === null): ?>
          <div class="auth-heading">
            <h1 class="auth-title" id="resetTitle">This link can&rsquo;t be used</h1>
            <p class="auth-subtitle">It has expired, was already used, or a newer link was sent after it. Links work once, for 30 minutes.</p>
          </div>
          <a class="btn-auth" href="forgot-password.php">Send a new link</a>
          <div class="auth-alt">
            <span class="auth-alt-text">Remembered your password?</span>
            <a class="btn-auth btn-auth-outline" href="customer-login.php">Back to login</a>
          </div>
          <?php else: ?>
          <div class="auth-heading">
            <h1 class="auth-title" id="resetTitle">Choose a new password</h1>
            <p class="auth-subtitle">For <?= $e($reset['email']) ?></p>
          </div>
          <?php if (!empty($rpErrors['form'])): ?><p class="auth-notice" role="alert"><?= $err('form') ?></p><?php endif; ?>
          <form id="resetForm" action="reset-password.php" method="post">
            <input type="hidden" name="csrf" value="<?= $e($rpCsrf) ?>" />
            <input type="email" name="username" value="<?= $e($reset['email']) ?>" autocomplete="username" hidden />
            <div class="form-group">
              <label for="rpNew" class="form-label">New password</label>
              <div class="input-group password-field<?= !empty($rpErrors['new_password']) ? ' is-invalid' : '' ?>"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input id="rpNew" name="new_password" type="password" autocomplete="new-password" placeholder="New password (8+ characters)" minlength="8" aria-describedby="rpNewMsg" required autofocus /><button class="password-toggle" type="button" data-password-toggle="rpNew" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="rpNewMsg" aria-live="polite"><?= $err('new_password') ?></small>
            </div>
            <div class="form-group">
              <label for="rpConfirm" class="form-label">Confirm new password</label>
              <div class="input-group password-field<?= !empty($rpErrors['confirm_password']) ? ' is-invalid' : '' ?>"><span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><input id="rpConfirm" name="confirm_password" type="password" autocomplete="new-password" placeholder="Confirm new password" aria-describedby="rpConfirmMsg" required /><button class="password-toggle" type="button" data-password-toggle="rpConfirm" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="rpConfirmMsg" aria-live="polite"><?= $err('confirm_password') ?></small>
            </div>
            <button type="submit" class="btn-auth">Change password</button>
          </form>
          <p class="auth-footer-link">Changing it signs this account out everywhere else.</p>
          <?php endif; ?>
<?php
cas_close();
