<?php
declare(strict_types=1);
/* =====================================================================
   FORGOT PASSWORD, STEP 1 — ask for the email, send a reset link.
   includes/password-reset.php explains the whole flow and its security.

   The reply is the same whatever happened (account or not, sent or
   throttled), and it is shown after a redirect, so a refresh never
   sends a second email and the reply cannot be told apart by its URL.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/password-reset.php';
require_once __DIR__ . '/../includes/customer-auth-shell.php';

venusep_session_start();
header('Cache-Control: no-store');

$fpError = '';
$fpEmail = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fpEmail = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $fpError = 'This page expired. Try again.';
    } elseif ($fpEmail === '' || mb_strlen($fpEmail) > 190 || !filter_var($fpEmail, FILTER_VALIDATE_EMAIL)) {
        $fpError = 'Please enter a valid email address.';
    } else {
        $pdo = venusep_db();
        if ($pdo === null) {
            $fpError = 'The database is unreachable right now. Please try again in a few minutes.';
        } else {
            try {
                pr_request($pdo, $fpEmail, $_SERVER['REMOTE_ADDR'] ?? null);
            } catch (Throwable $e) {
                /* Logged, never shown: the reply must not differ for an address
                   that exists (an error could only happen for one that does). */
                error_log('VENUSeP forgot password: ' . $e->getMessage());
            }
            $_SESSION['forgot_sent'] = true;
            header('Location: forgot-password.php');
            exit;
        }
    }
}
$fpSent = !empty($_SESSION['forgot_sent']);
unset($_SESSION['forgot_sent']);
$fpCsrf = csrf_token();
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

cas_open('Forgot password', 'Forgot your <br />password?', 'It happens. We’ll email you a link to choose a new one.', 'forgotTitle');
?>
          <div class="auth-heading">
            <h1 class="auth-title" id="forgotTitle"><?= $fpSent ? 'Check your email' : 'Reset your password' ?></h1>
            <p class="auth-subtitle"><?= $fpSent ? '' : 'Enter the email you use for VENUSeP and we’ll send you a link.' ?></p>
          </div>
          <?php if ($fpSent): ?>
          <p class="auth-notice" role="status"><?= $e(PR_REQUEST_REPLY) ?></p>
          <a class="btn-auth" href="customer-login.php">Back to login</a>
          <div class="auth-alt">
            <span class="auth-alt-text">Didn&rsquo;t get it after a few minutes?</span>
            <a class="btn-auth btn-auth-outline" href="forgot-password.php">Send another link</a>
          </div>
          <?php else: ?>
          <form id="forgotForm" action="forgot-password.php" method="post">
            <input type="hidden" name="csrf" value="<?= $e($fpCsrf) ?>" />
            <div class="form-group">
              <label for="fpEmail" class="form-label">Email Address</label>
              <div class="input-group<?= $fpError !== '' ? ' is-invalid' : '' ?>"><span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span><input id="fpEmail" name="email" type="email" autocomplete="email" placeholder="customer@example.com" maxlength="190" value="<?= $e($fpEmail) ?>" aria-describedby="fpEmailMsg" required autofocus /></div>
              <small class="validation-message" id="fpEmailMsg" aria-live="polite"><?= $e($fpError) ?></small>
            </div>
            <button type="submit" class="btn-auth">Send reset link</button>
          </form>
          <div class="auth-alt">
            <span class="auth-alt-text">Remembered it?</span>
            <a class="btn-auth btn-auth-outline" href="customer-login.php">Back to login</a>
          </div>
          <?php endif; ?>
<?php
cas_close();
