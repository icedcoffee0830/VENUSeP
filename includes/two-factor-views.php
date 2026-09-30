<?php
/* =====================================================================
   TWO-STEP VERIFICATION — the sign-in screens (DB-DECISIONS #20).

   Shared by admin/admin-login.php and customer/customer-login.php, which
   use the same auth layout and class names. The crimson panel says where
   the person is (tfa_view_hero); the white card holds only the action.
   Every form posts back to the page it is on, with a csrf token and a
   tfa_step telling the page which step it is.

   The pages load assets/css/two-factor.css and, while a step is showing,
   assets/js/two-factor.js (QR drawing, copy/download, the "saved" tick).
   ===================================================================== */

function tfa_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/* The crimson panel's copy for each step: a short headline, the reason, and a trail. */
function tfa_view_hero(string $mode): void
{
    $copy = [
        'verify' => ['One more <br />step.', 'Your account uses two-step verification. Enter the code from your phone to finish signing in.'],
        'enroll' => ['Set up <br />your phone.', 'Admin accounts need a code from your phone each time you sign in. Setting it up takes about a minute.'],
        'codes'  => ['Keep these <br />safe.', 'If you lose your phone, a recovery code is the only way back into your account. Nobody at VENUSeP can reset it for you.'],
    ];
    $trail = $mode === 'verify'
        ? ['Password', 'Code from your phone']
        : ['Password', 'Scan with your phone', 'Save your recovery codes'];
    $current = $mode === 'codes' ? 2 : 1;
    [$title, $sub] = $copy[$mode] ?? $copy['verify'];
    ?>
          <h2 class="auth-hero-title"><?php echo $title; ?></h2>
          <p class="auth-hero-sub"><?php echo tfa_e($sub); ?></p>
          <ol class="auth-trail">
            <?php foreach ($trail as $i => $label):
                $state = $i < $current ? 'is-done' : ($i === $current ? 'is-current' : ''); ?>
            <li class="<?php echo $state; ?>"><span class="auth-trail-mark"><?php echo $i < $current ? '<i class="bi bi-check2" aria-hidden="true"></i>' : $i + 1; ?></span><?php echo tfa_e($label); ?></li>
            <?php endforeach; ?>
          </ol>
    <?php
}

/* "Use a different account": ends the half-finished sign-in. */
function tfa_view_cancel(string $csrf): void
{
    ?>
          <form class="tfa-cancel" method="post" action="">
            <input type="hidden" name="csrf" value="<?php echo tfa_e($csrf); ?>" />
            <input type="hidden" name="tfa_step" value="cancel" />
            <button type="submit" class="tfa-text-btn">Use a different account</button>
          </form>
    <?php
}

/* The lock message, built so assets/js/two-factor.js can count it down in place:
   only the number and its unit change, and they are aria-live="off" so a screen
   reader hears the lock once, not every second. Mirrored in the JS lockCountdown(). */
function tfa_view_lock(int $seconds): void
{
    ?><span data-tfa-text>Too many wrong codes. Try again in <span aria-live="off" data-tfa-wait><?php echo tfa_e(tfa_wait_text($seconds)); ?></span>.</span><?php
}

/* After a correct password, for an account that already has two-step verification.
   $lockSeconds > 0 means a lock is running: the countdown replaces any other error. */
function tfa_view_code(string $csrf, string $account, string $error, int $lockSeconds = 0): void
{
    $invalid = $lockSeconds > 0 || $error !== '';
    ?>
          <div class="auth-heading">
            <h1 class="auth-title" id="tfaTitle">Enter your code</h1>
            <p class="auth-subtitle">Open Google Authenticator and type the 6-digit code shown for <strong><?php echo tfa_e(tfa_entry_name($account)); ?></strong>.</p>
          </div>
          <form method="post" action="" novalidate>
            <input type="hidden" name="csrf" value="<?php echo tfa_e($csrf); ?>" />
            <input type="hidden" name="tfa_step" value="code" />
            <div class="form-group">
              <label for="tfaCode" class="form-label">Verification code</label>
              <div class="input-group tfa-code<?php echo $invalid ? ' is-invalid' : ''; ?>">
                <span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                <input id="tfaCode" name="code" type="text" inputmode="text" autocomplete="one-time-code" maxlength="11"
                       placeholder="123 456" spellcheck="false" autocapitalize="characters" autofocus required
                       aria-describedby="<?php echo $invalid ? 'tfaError' : 'tfaHelp'; ?>"<?php echo $invalid ? ' aria-invalid="true"' : ''; ?> />
              </div>
              <?php if ($lockSeconds > 0): ?>
              <p class="tfa-error" id="tfaError" role="alert" data-tfa-countdown="<?php echo $lockSeconds; ?>"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?php tfa_view_lock($lockSeconds); ?></p>
              <?php elseif ($error !== ''): ?>
              <p class="tfa-error" id="tfaError" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span><?php echo tfa_e($error); ?></span></p>
              <?php else: ?>
              <p class="tfa-hint" id="tfaHelp"><i class="bi bi-life-preserver" aria-hidden="true"></i>Lost your phone? Type one of your recovery codes instead.</p>
              <?php endif; ?>
            </div>
            <button type="submit" class="btn-auth">Verify</button>
          </form>
    <?php
    tfa_view_cancel($csrf);
}

/* First admin sign-in without a phone set up: scan, then confirm with a code. */
function tfa_view_enroll(string $csrf, string $account, string $secret, string $error): void
{
    $uri = totp_uri($secret, $account);
    $grouped = implode(' ', str_split($secret, 4));
    ?>
          <div class="auth-heading">
            <h1 class="auth-title" id="tfaTitle">Scan with your app</h1>
            <p class="auth-subtitle">In Google Authenticator, tap <strong>+</strong>, then <strong>Scan a QR code</strong>.</p>
          </div>
          <p class="tfa-app-note">No app yet? Install <strong>Google Authenticator</strong> from the Play Store or App Store. Any authenticator app works.</p>
          <div class="tfa-qr" data-tfa-qr="<?php echo tfa_e($uri); ?>"></div>
          <p class="tfa-key-label">Can't scan it? Type this key instead:</p>
          <div class="tfa-key-row">
            <code class="tfa-key" translate="no"><?php echo tfa_e($grouped); ?></code>
            <button type="button" class="tfa-mini" data-tfa-copy="<?php echo tfa_e($secret); ?>"><i class="bi bi-copy" aria-hidden="true"></i><span>Copy</span></button>
          </div>
          <p class="tfa-tip">Tip: scan it on a second phone too, as a backup.</p>
          <hr class="tfa-rule" />
          <form method="post" action="" novalidate>
            <input type="hidden" name="csrf" value="<?php echo tfa_e($csrf); ?>" />
            <input type="hidden" name="tfa_step" value="enroll_confirm" />
            <label for="tfaCode" class="tfa-field-label">Enter the 6-digit code shown for <?php echo tfa_e(tfa_entry_name($account)); ?></label>
            <div class="form-group">
              <div class="input-group tfa-code<?php echo $error !== '' ? ' is-invalid' : ''; ?>">
                <span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                <input id="tfaCode" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7"
                       placeholder="123 456" spellcheck="false" required<?php echo $error !== '' ? ' aria-invalid="true" aria-describedby="tfaError" autofocus' : ''; ?> />
              </div>
              <?php if ($error !== ''): ?>
              <p class="tfa-error" id="tfaError" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span><?php echo tfa_e($error); ?></span></p>
              <?php endif; ?>
            </div>
            <button type="submit" class="btn-auth">Turn on</button>
          </form>
    <?php
    tfa_view_cancel($csrf);
}

/* The profile panel's three hidden steps (prompt, set-up, new codes) and its message
   line. Both profile pages render the status and action buttons in their own style;
   this is the part assets/js/two-factor.js drives, so its data-tfa-* contract lives
   in one place. $cls maps btn / primary / input to each page's own class names. */
function tfa_view_panel_steps(bool $enabled, string $account, array $cls): void
{
    ?>
                    <form class="tfa-block" data-tfa-prompt hidden novalidate>
                      <div class="tfa-inline" style="margin-top:0">
                        <div class="tfa-field">
                          <label for="tfaCurrent" data-tfa-prompt-label>Current code or recovery code</label>
                          <input class="<?php echo tfa_e($cls['input']); ?>" id="tfaCurrent" data-tfa-current spellcheck="false" autocapitalize="characters" />
                        </div>
                        <button class="<?php echo tfa_e($cls['primary']); ?>" type="submit" data-tfa-prompt-go>Continue</button>
                        <button class="<?php echo tfa_e($cls['btn']); ?>" type="button" data-tfa-back>Cancel</button>
                      </div>
                    </form>

                    <form class="tfa-block" data-tfa-setup hidden novalidate>
                      <div class="tfa-setup">
                        <div class="tfa-qr" data-tfa-qr=""></div>
                        <div>
                          <ol class="tfa-steps">
                            <li><?php echo $enabled ? 'On the new phone, install' : 'Install'; ?> <strong>Google Authenticator</strong> from the Play Store or App Store (any authenticator app works).</li>
                            <li>In the app, tap <strong>+</strong>, then <strong>Scan a QR code</strong>.</li>
                            <li>Type the 6-digit code shown for <strong><?php echo tfa_e(tfa_entry_name($account)); ?></strong> below.<?php echo $enabled ? ' Your old phone stops working once this is done.' : ''; ?></li>
                          </ol>
                          <p class="tfa-key-label">Can't scan it? Type this key instead:</p>
                          <code class="tfa-key" data-tfa-key translate="no"></code>
                          <div class="tfa-inline">
                            <div class="tfa-field">
                              <label for="tfaNewCode"><?php echo $enabled ? 'Code from the new phone' : 'Code from the app'; ?></label>
                              <input class="<?php echo tfa_e($cls['input']); ?>" id="tfaNewCode" data-tfa-new-code spellcheck="false" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456" />
                            </div>
                            <button class="<?php echo tfa_e($cls['primary']); ?>" type="submit"><?php echo $enabled ? 'Use this phone' : 'Turn on'; ?></button>
                            <button class="<?php echo tfa_e($cls['btn']); ?>" type="button" data-tfa-back>Cancel</button>
                          </div>
                        </div>
                      </div>
                    </form>

                    <div class="tfa-block" data-tfa-codes-block hidden>
                      <div data-tfa-codes="[]" data-tfa-account="<?php echo tfa_e($account); ?>">
                        <ul class="tfa-codes" data-tfa-codes-list translate="no" aria-label="Your recovery codes"></ul>
                        <div class="tfa-row">
                          <button class="<?php echo tfa_e($cls['btn']); ?>" type="button" data-tfa-download><i class="bi bi-download" aria-hidden="true"></i><span>Download (.txt)</span></button>
                          <button class="<?php echo tfa_e($cls['btn']); ?>" type="button" data-tfa-copy-codes><i class="bi bi-copy" aria-hidden="true"></i><span>Copy</span></button>
                        </div>
                      </div>
                      <label class="tfa-confirm"><input type="checkbox" data-tfa-saved /><span>I saved these codes somewhere safe, away from my phone.</span></label>
                      <div class="tfa-row"><button class="<?php echo tfa_e($cls['primary']); ?>" type="button" data-tfa-saved-submit>Done</button></div>
                    </div>

                    <p class="tfa-message" data-tfa-message role="status" aria-live="polite"></p>
    <?php
}

/* Shown once, right after set-up. No "different account" way out: 2FA is already on. */
function tfa_view_codes(string $csrf, array $codes, string $account, string $error): void
{
    ?>
          <div class="auth-heading">
            <h1 class="auth-title" id="tfaTitle">Save your recovery codes</h1>
            <p class="auth-subtitle">Each code works once. Use one to sign in if your phone is lost or not with you.</p>
          </div>
          <div data-tfa-codes="<?php echo tfa_e(json_encode(array_values($codes))); ?>" data-tfa-account="<?php echo tfa_e($account); ?>">
            <ul class="tfa-codes" translate="no" aria-label="Your <?php echo count($codes); ?> recovery codes">
              <?php foreach ($codes as $c): ?><li><?php echo tfa_e($c); ?></li><?php endforeach; ?>
            </ul>
            <div class="tfa-code-actions">
              <button type="button" class="tfa-mini" data-tfa-download><i class="bi bi-download" aria-hidden="true"></i><span>Download (.txt)</span></button>
              <button type="button" class="tfa-mini" data-tfa-copy-codes><i class="bi bi-copy" aria-hidden="true"></i><span>Copy</span></button>
            </div>
          </div>
          <form method="post" action="" novalidate>
            <input type="hidden" name="csrf" value="<?php echo tfa_e($csrf); ?>" />
            <input type="hidden" name="tfa_step" value="codes_done" />
            <label class="tfa-confirm" for="tfaSaved">
              <input class="form-check-input" type="checkbox" id="tfaSaved" name="saved" value="1" data-tfa-saved required />
              <span>I saved these codes somewhere safe, away from my phone.</span>
            </label>
            <?php if ($error !== ''): ?>
            <p class="tfa-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span><?php echo tfa_e($error); ?></span></p>
            <?php endif; ?>
            <button type="submit" class="btn-auth" data-tfa-saved-submit>Continue</button>
          </form>
    <?php
}
