<?php
/* =====================================================================
   The frame of a customer auth page — the same split layout as
   customer-login.php (crimson panel left, card right), for the pages
   that only need to put a form in the card: forgot-password.php and
   reset-password.php. Styles: assets/css/customer-auth.css.

   cas_open('Forgot password', 'Forgot your <br />password?', 'Sub text', 'titleId');
   ...the card's contents...
   cas_close();
   ===================================================================== */

/* $heroTitleHtml is trusted markup written in the page itself; the rest is escaped. */
function cas_open(string $pageTitle, string $heroTitleHtml, string $heroSub, string $labelledBy): void
{
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    ?><!DOCTYPE html>
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content" />
    <meta name="referrer" content="no-referrer" />
    <title>VENUSeP | <?= $e($pageTitle) ?></title>
    <link rel="icon" href="../logo/Logo Header 3.png" type="image/png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="../assets/css/customer-auth.css" />
  </head>
  <body class="customer-login-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="../logo/Logo Header 3.png" alt="" />
        <div class="auth-hero-copy">
          <h2 class="auth-hero-title"><?= $heroTitleHtml ?></h2>
          <p class="auth-hero-sub"><?= $e($heroSub) ?></p>
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="<?= $e($labelledBy) ?>">
        <a class="auth-back" href="customer-login.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to login</a>
        <div class="auth-card">
          <a class="auth-brand" href="venusep_venue_booking.php" title="Back to VENUSeP"><img src="../logo/Logo Header 3.png" alt="VENUSeP" /></a>
<?php
}

function cas_close(): void
{
    ?>
        </div>
      </section>
    </main>
    <script>
      /* show / hide a password, same as the login page */
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
    </script>
  </body>
</html>
<?php
}
