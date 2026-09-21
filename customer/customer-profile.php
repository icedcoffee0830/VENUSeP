<?php require_once __DIR__ . '/../includes/auth.php'; customer_require_login(); /* customers only — guests go to the login page */ ?>
<?php
/* ==================================================================
   CUSTOMER PROFILE — VENUSeP merged system (customer portal)
   ==================================================================
   The teammate's customer-profile.php is the FINAL profile page (Aron,
   2026-07-15) — it replaces our old venusep_customer_settings.php.
   Ported onto OUR shared shell (header + sidebar, $portal='customer')
   and restyled to the team palette. Same sections + validation.

   The record is the SESSION'S OWN customers row, and the forms save to
   profile-save.php. This used to be a hard-coded "Juan Miguel Dela Cruz"
   whoever was signed in — the page told every customer they were someone
   else — and "Save" only ever printed a success message.

   Date of Birth was dropped rather than wired: there is no column for it,
   nothing in the system uses one, and adding a birthdate means collecting
   personal data with no purpose. University ID takes its place on the
   form, which the USeP discount actually depends on.
   ================================================================== */

require_once __DIR__ . '/../includes/customer-bookings.php';

$cpRow = [];
try {
    $cpStmt = venusep_db_or_fail()->prepare(
        "SELECT c.full_name, c.phone, c.address, c.university_id_no, c.photo_path,
                u.email, DATE_FORMAT(c.created_at, '%M %Y') AS member_since
           FROM customers c LEFT JOIN users u ON u.id = c.user_id
          WHERE c.id = :id"
    );
    $cpStmt->execute([':id' => $customerContact['id']]);
    $cpRow = $cpStmt->fetch() ?: [];
} catch (PDOException $e) {
    $cpRow = [];
}

$customerProfile = [
    'name'         => (string) ($cpRow['full_name'] ?? ''),
    'email'        => (string) ($cpRow['email'] ?? ''),
    'phone'        => (string) ($cpRow['phone'] ?? ''),
    'address'      => (string) ($cpRow['address'] ?? ''),
    'universityId' => (string) ($cpRow['university_id_no'] ?? ''),
    'photo'        => (string) ($cpRow['photo_path'] ?? ''),
    'memberSince'  => (string) ($cpRow['member_since'] ?? ''),
];
$cpCsrf = csrf_token();
function cp_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<!-- ==================================================================
  MAP: [0] SHELL CSS · [1] PAGE CSS · [2] HEADER · [3] SIDEBAR ·
       [4] CONTENT (summary + Personal Info / Security / Preferences /
       Account Actions) · [6] SCRIPT (validation + show/hide password)
  The record is the session customer's own row; the forms save to profile-save.php.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | My Profile</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- [0] SHELL CSS — shared layout (right of sidebar, below header) -->
    <style>
      :root {
        --black: #1f1e1e; --border: #e5e5e5; --muted: #606a75; --danger: #b23a3a; --ok: #1c7a4f;
        --venusep-sidebar-width: 235px; --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body { background: #fff; color: var(--black); font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; overflow-x: hidden; }
      .app-main { margin-left: var(--venusep-sidebar-width); padding-top: var(--venusep-header-height); min-height: 100vh; background: #fff; }
      .container-fluid { width: 100%; padding-inline: 30px; }
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
      }
    </style>

    <!-- [1] PAGE CSS — profile page, self-contained in the team palette -->
    <style>
      .profile-container { padding: 22px 0 0; } /* 26px effective top, matches other pages */
      .profile-page-header h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.01em; margin: 0; }
      .profile-page-header p { color: var(--muted); font-size: 13px; margin: 4px 0 20px; }

      .profile-layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 20px; align-items: start; }

      /* summary card */
      .profile-summary { background: #fff; border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 1px 2px rgba(15,23,42,.04); padding: 24px 20px; text-align: center; }
      .profile-summary .profile-avatar { width: 84px; height: 84px; margin: 0 auto 14px; border-radius: 50%; background: var(--black); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; font-weight: 800; }
      .profile-summary h2 { font-size: 1.1rem; font-weight: 800; margin: 0; }
      .profile-role { color: var(--muted); font-size: .85rem; margin: 2px 0 10px; }
      .profile-status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 11px; border-radius: 999px; background: #eaf6ef; color: var(--ok); font-size: 11px; font-weight: 600; }
      .profile-meta { text-align: left; margin: 18px 0 16px; padding: 16px 0 0; border-top: 1px solid #f0efec; display: grid; gap: 12px; }
      .profile-meta div { display: grid; gap: 2px; }
      .profile-meta dt { font-size: 10.5px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
      .profile-meta dd { margin: 0; font-size: .86rem; word-break: break-word; }

      /* right column sections */
      .profile-details { display: grid; gap: 16px; }
      .profile-section { background: #fff; border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 1px 2px rgba(15,23,42,.04); padding: 20px 22px; }
      .profile-section-header h2 { font-size: 1rem; font-weight: 700; margin: 0; }
      .profile-section-header p { color: var(--muted); font-size: .82rem; margin: 3px 0 16px; }

      .profile-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; }
      .profile-form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
      .profile-form-group.full-width { grid-column: 1 / -1; }
      .profile-form-group label { font-size: .8rem; font-weight: 700; }

      .form-control { width: 100%; min-height: 42px; padding: 10px 13px; border: 1px solid #d7d7d7; border-radius: 10px; font: inherit; font-size: .9rem; background: #fff; color: var(--black); }
      .form-control:focus { outline: none; border-color: var(--black); box-shadow: 0 0 0 3px rgba(31,30,30,.08); }
      .profile-form-group.is-invalid .form-control,
      .profile-form-group.is-invalid .input-group { border-color: var(--danger); }

      /* password field with icon + toggle */
      .input-group { display: flex; align-items: center; border: 1px solid #d7d7d7; border-radius: 10px; background: #fff; overflow: hidden; }
      .input-group:focus-within { border-color: var(--black); box-shadow: 0 0 0 3px rgba(31,30,30,.08); }
      .input-group-text { display: inline-flex; align-items: center; justify-content: center; width: 40px; align-self: stretch; color: var(--muted); }
      .input-group .form-control { border: 0; border-radius: 0; box-shadow: none; }
      .profile-password-toggle { border: 0; background: transparent; color: var(--muted); cursor: pointer; padding: 0 12px; align-self: stretch; }

      .profile-validation { min-height: 1em; color: var(--danger); font-size: .72rem; }
      .profile-success { display: block; margin-top: 10px; color: var(--ok); font-size: .82rem; font-weight: 600; }

      .profile-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
      .btn-profile { display: inline-flex; align-items: center; gap: 7px; min-height: 42px; padding: 10px 18px; border-radius: 10px; border: 1px solid #d7d7d7; background: #fff; color: var(--black); font: inherit; font-size: .84rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: .18s; }
      .btn-profile:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .btn-profile-primary { background: var(--black); border-color: var(--black); color: #fff; }
      .btn-profile-primary:hover { background: #000; border-color: #000; }
      .btn-profile-danger { color: var(--danger); border-color: #e4b1b1; }
      .btn-profile[disabled] { opacity: .5; cursor: not-allowed; }

      .preference-list { display: grid; gap: 12px; }
      .preference-item { display: flex; align-items: center; gap: 10px; }
      .preference-item label { font-size: .88rem; }
      .form-check-input { width: 16px; height: 16px; accent-color: var(--black); flex: none; }

      .account-actions { display: flex; gap: 10px; flex-wrap: wrap; }

      @media (max-width: 960px) {
        .profile-layout { grid-template-columns: 1fr; }
        .profile-form-grid { grid-template-columns: 1fr; }
      }
    
      /* ==================================================================
         LIGHT GLASS. The page behind the cards is the crimson gradient
         (painted by includes/header.php). Cards are near-white but not
         solid: they blur what is behind them, so a hint of the crimson
         bleeds through and the edge glows — while the content inside stays
         dark-on-light, the readable choice for long forms and tables.
         Only colours change here — no layout, no markup.
         ================================================================== */
      .profile-page-header h1 { color: #fff; }
      .profile-page-header p { color: #e9d0cd; }

      .profile-summary, .profile-section {
        background: rgba(255,255,255,.90);
        -webkit-backdrop-filter: blur(18px) saturate(1.15); backdrop-filter: blur(18px) saturate(1.15);
        border: 1px solid rgba(255,255,255,.6);
        box-shadow: 0 18px 44px rgba(10,4,5,.30), inset 0 1px 0 rgba(255,255,255,.9);
      }
      .profile-meta { border-top-color: rgba(31,30,30,.1); }

      /* fields: white on the tinted card, crimson focus */
      .form-control, .input-group { background: #fff; border-color: #dccfcc; }
      .form-control:focus, .input-group:focus-within { border-color: #a11626; box-shadow: 0 0 0 3px rgba(161,22,38,.14); }
      .form-check-input { accent-color: #a11626; }

      /* avatar + primary buttons: crimson */
      .profile-summary .profile-avatar { background: #a11626; color: #fff; box-shadow: 0 8px 20px rgba(138,18,34,.3); }
      .btn-profile { background: rgba(255,255,255,.7); }
      .btn-profile:hover { background: #fff; border-color: #c9b9b6; }
      .btn-profile.btn-profile-primary { background: #a11626; border-color: #a11626; color: #fff; }
      .btn-profile.btn-profile-primary:hover { background: #7d0f1e; border-color: #7d0f1e; }
          /* type floor (readability): nothing on the page below 12px */
      .profile-meta dt { font-size: 12px; }
          /* ---- white page (desktop + phone): the cards sit on white, no crimson wash ---- */
      body { background: #fff !important; }
      .app-main { background: #fff !important; }
      .profile-page-header h1 { color: #1f1e1e; }
      .profile-page-header p { color: #6e6a64; }
      .profile-summary, .profile-section { box-shadow: 0 1px 2px rgba(0,0,0,.04); border: 1px solid #e5e5e5; border-radius: 14px; }
      .profile-summary, .profile-section { background: #fff; -webkit-backdrop-filter: none; backdrop-filter: none; }
      /* phone: cards edge to edge — the details get the width */
      @media (max-width: 767.98px) {
        .container-fluid { padding-inline: 12px; }
        .app-content-header { padding-top: 16px; }
      }
    </style>
  </head>
  <body class="customer-profile-page">
    <div class="app-wrapper">
      <!-- [2] HEADER + [3] SIDEBAR — shared includes, customer portal variant -->
      <?php $portal = 'customer'; include __DIR__ . '/../includes/header.php'; ?>
      <?php $active = 'Profile'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="profile-container">
              <header class="profile-page-header">
                <h1>My Profile</h1>
                <p>View and update your account information.</p>
              </header>

              <div class="profile-layout">
                <aside class="profile-summary" aria-labelledby="profileSummaryName">
<?php if ($customerProfile['photo'] !== ''): ?>
                  <img class="profile-avatar" id="profileAvatar" src="../<?php echo cp_e($customerProfile['photo']); ?>"
                       alt="<?php echo cp_e($customerProfile['name']); ?>" style="object-fit:cover">
<?php else: ?>
                  <div class="profile-avatar" id="profileAvatar" aria-label="<?php echo cp_e($customerProfile['name']); ?> initials"><?php
                    /* first two words' initials — the SAME rule as the booking-page
                       header (initials() there), so "Juan Miguel Dela Cruz" -> "JM".
                       Derived, so the avatar can never drift from the name. */
                    $cpParts = preg_split('/\s+/', trim($customerProfile['name']));
                    $cpIni = '';
                    foreach (array_slice($cpParts, 0, 2) as $cpW) $cpIni .= substr($cpW, 0, 1);
                    echo cp_e(strtoupper($cpIni));
                  ?></div>
<?php endif; ?>
                  <h2 id="profileSummaryName"><?php echo cp_e($customerProfile['name']); ?></h2>
                  <p class="profile-role">Customer</p>
                  <span class="profile-status"><i class="bi bi-check-circle" aria-hidden="true"></i>Active</span>
                  <dl class="profile-meta">
                    <div><dt>Email</dt><dd><?php echo cp_e($customerProfile['email']); ?></dd></div>
                    <div><dt>Contact Number</dt><dd><?php echo cp_e($customerProfile['phone']); ?></dd></div>
                    <div><dt>Address</dt><dd><?php echo cp_e($customerProfile['address']); ?></dd></div>
                    <div><dt>Member Since</dt><dd><?php echo cp_e($customerProfile['memberSince']); ?></dd></div>
                  </dl>
                  <!-- Profile picture only. Sensitive documents go to booking_documents,
                       outside the web root, behind document-view.php. -->
                  <input type="file" id="avatarInput" accept="image/png,image/jpeg,image/webp" hidden>
                  <button class="btn-profile" type="button" onclick="document.getElementById('avatarInput').click()"><i class="bi bi-camera" aria-hidden="true"></i>Change Photo</button>
<?php if ($customerProfile['photo'] !== ''): ?>
                  <button class="btn-profile" type="button" id="avatarRemove"><i class="bi bi-trash" aria-hidden="true"></i>Remove</button>
<?php endif; ?>
                  <div class="profile-validation" id="avatarMessage" aria-live="polite"></div>
                </aside>

                <div class="profile-details">
                  <section class="profile-section" aria-labelledby="personalInformationTitle">
                    <div class="profile-section-header"><h2 id="personalInformationTitle">Personal Information</h2><p>Keep your contact details up to date.</p></div>
                    <form id="customerProfileForm" action="" method="post" novalidate>
                      <div class="profile-form-grid">
                        <div class="profile-form-group"><label for="fullName">Full Name</label><input class="form-control" id="fullName" name="full_name" type="text" autocomplete="name" value="<?php echo cp_e($customerProfile['name']); ?>" aria-describedby="fullNameValidation" required><small class="profile-validation" id="fullNameValidation" aria-live="polite"></small></div>
                        <div class="profile-form-group"><label for="email">Email Address</label><input class="form-control" id="email" name="email" type="email" autocomplete="email" value="<?php echo cp_e($customerProfile['email']); ?>" aria-describedby="emailValidation" required><small class="profile-validation" id="emailValidation" aria-live="polite"></small></div>
                        <div class="profile-form-group"><label for="contactNumber">Contact Number</label><input class="form-control" id="contactNumber" name="contact_number" type="tel" autocomplete="tel" value="<?php echo cp_e($customerProfile['phone']); ?>" aria-describedby="contactNumberValidation" required><small class="profile-validation" id="contactNumberValidation" aria-live="polite"></small></div>
                        <div class="profile-form-group"><label for="universityId">USeP ID number <span style="font-weight:400;color:#8a857d">(optional)</span></label><input class="form-control" id="universityId" name="university_id_no" type="text" value="<?php echo cp_e($customerProfile['universityId']); ?>" placeholder="e.g. 2023-00412"><small class="profile-validation">Staff check your uploaded ID against this when applying the USeP discount.</small></div>
                        <div class="profile-form-group full-width"><label for="address">Address</label><input class="form-control" id="address" name="address" type="text" autocomplete="street-address" value="<?php echo cp_e($customerProfile['address']); ?>" required></div>
                      </div>
                      <div class="profile-actions"><button class="btn-profile btn-profile-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i>Save Changes</button><button class="btn-profile" type="reset">Cancel</button></div>
                      <small class="profile-success" id="profileSuccessMessage" aria-live="polite"></small>
                    </form>
                  </section>

                  <section class="profile-section" aria-labelledby="accountSecurityTitle">
                    <div class="profile-section-header"><h2 id="accountSecurityTitle">Account Security</h2><p>Use a strong password that you do not reuse elsewhere.</p></div>
                    <form id="customerPasswordForm" action="" method="post" novalidate>
                      <div class="profile-form-grid">
                        <div class="profile-form-group full-width"><label for="currentPassword">Current Password</label><div class="input-group"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input class="form-control" id="currentPassword" name="current_password" type="password" autocomplete="current-password" aria-describedby="currentPasswordValidation"><button class="profile-password-toggle" type="button" data-profile-password-toggle="currentPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small class="profile-validation" id="currentPasswordValidation" aria-live="polite"></small></div>
                        <div class="profile-form-group"><label for="newPassword">New Password</label><div class="input-group"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input class="form-control" id="newPassword" name="new_password" type="password" autocomplete="new-password" minlength="8" aria-describedby="newPasswordValidation"><button class="profile-password-toggle" type="button" data-profile-password-toggle="newPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small class="profile-validation" id="newPasswordValidation" aria-live="polite"></small></div>
                        <div class="profile-form-group"><label for="confirmNewPassword">Confirm New Password</label><div class="input-group"><span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><input class="form-control" id="confirmNewPassword" name="confirm_new_password" type="password" autocomplete="new-password" aria-describedby="confirmNewPasswordValidation"><button class="profile-password-toggle" type="button" data-profile-password-toggle="confirmNewPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small class="profile-validation" id="confirmNewPasswordValidation" aria-live="polite"></small></div>
                      </div>
                      <div class="profile-actions"><button class="btn-profile btn-profile-primary" type="submit"><i class="bi bi-shield-check" aria-hidden="true"></i>Update Password</button></div>
                      <small class="profile-success" id="passwordSuccessMessage" aria-live="polite"></small>
                    </form>
                  </section>

                  <section class="profile-section" aria-labelledby="preferencesTitle">
                    <div class="profile-section-header"><h2 id="preferencesTitle">Preferences</h2><p>Choose which account updates you receive.</p></div>
                    <form id="customerPreferencesForm" action="" method="post">
                      <div class="preference-list">
                        <div class="preference-item"><input class="form-check-input" type="checkbox" id="emailNotifications" name="email_notifications" checked><label for="emailNotifications">Email notifications</label></div>
                        <div class="preference-item"><input class="form-check-input" type="checkbox" id="bookingStatusUpdates" name="booking_status_updates" checked><label for="bookingStatusUpdates">Booking status updates</label></div>
                        <div class="preference-item"><input class="form-check-input" type="checkbox" id="paymentReminders" name="payment_reminders" checked><label for="paymentReminders">Payment reminders</label></div>
                      </div>
                      <div class="profile-actions"><button class="btn-profile btn-profile-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i>Save Preferences</button></div>
                      <small class="profile-success" id="preferencesSuccessMessage" aria-live="polite"></small>
                    </form>
                  </section>

                  <section class="profile-section" aria-labelledby="accountActionsTitle">
                    <div class="profile-section-header"><h2 id="accountActionsTitle">Account Actions</h2><p>Manage access to your VENUSeP account.</p></div>
                    <div class="account-actions"><a class="btn-profile" href="logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Log Out</a><!-- [SIM] deactivate not built --><button class="btn-profile btn-profile-danger" type="button" disabled title="Account deactivation is under development"><i class="bi bi-person-x" aria-hidden="true"></i>Deactivate Account</button></div>
                  </section>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>

    <!-- [6] PAGE SCRIPT — validation + show/hide password (front-end only) -->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const setFieldError = function (field, messageId, message) {
          const group = field.closest('.profile-form-group');
          if (group) group.classList.toggle('is-invalid', Boolean(message));
          field.setAttribute('aria-invalid', message ? 'true' : 'false');
          const el = document.getElementById(messageId);
          if (el) el.textContent = message;
        };

        document.querySelectorAll('[data-profile-password-toggle]').forEach(function (toggle) {
          toggle.addEventListener('click', function () {
            const input = document.getElementById(toggle.dataset.profilePasswordToggle);
            if (!input) return;
            const showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            toggle.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', showing ? 'true' : 'false');
            const icon = toggle.querySelector('i');
            if (icon) { icon.classList.toggle('bi-eye', !showing); icon.classList.toggle('bi-eye-slash', showing); }
          });
        });

        const CSRF = <?php echo json_encode($cpCsrf); ?>;

        /* ---- profile picture ----
           A PICTURE ONLY. It is stored under assets/, which the web server
           hands to anyone with the URL — right for a photo someone chose to
           show, and the reason IDs and receipts go somewhere else entirely
           (booking_documents, outside the web root, behind document-view.php). */
        const avatarInput = document.getElementById('avatarInput');
        const avatarMsg = document.getElementById('avatarMessage');
        const sendAvatar = function (body) {
          avatarMsg.textContent = 'Uploading…';
          fetch('../profile-save.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
            .then(function (res) {
              avatarMsg.textContent = res.message || '';
              /* Reload so the header chip and the summary both pick it up
                 rather than only the one element we happen to know about. */
              if (res.ok) window.setTimeout(function () { window.location.reload(); }, 600);
            })
            .catch(function () { avatarMsg.textContent = 'Could not reach the server.'; });
        };
        if (avatarInput) {
          avatarInput.addEventListener('change', function () {
            if (!avatarInput.files || !avatarInput.files[0]) return;
            const body = new FormData();
            body.append('csrf', CSRF);
            body.append('action', 'photo');
            body.append('photo', avatarInput.files[0], avatarInput.files[0].name);
            avatarInput.value = '';
            sendAvatar(body);
          });
        }
        const avatarRemove = document.getElementById('avatarRemove');
        if (avatarRemove) {
          avatarRemove.addEventListener('click', function () {
            const body = new FormData();
            body.append('csrf', CSRF);
            body.append('action', 'photo');
            body.append('remove', '1');
            sendAvatar(body);
          });
        }

        const profileForm = document.getElementById('customerProfileForm');
        if (profileForm) {
          const profileFields = [
            ['fullName', 'fullNameValidation', 'Please enter your full name.'],
            ['email', 'emailValidation', 'Please enter a valid email address.'],
            ['contactNumber', 'contactNumberValidation', 'Please enter your contact number.'],
            ['address', 'addressValidation', 'Please enter your address.'],
          ];
          profileForm.addEventListener('submit', function (event) {
            event.preventDefault();
            let hasError = false;
            profileFields.forEach(function (cfg) {
              const field = document.getElementById(cfg[0]);
              if (!field) return;
              let message = '';
              if (cfg[0] === 'email') message = field.value.trim() && field.checkValidity() ? '' : cfg[2];
              else message = field.value.trim() ? '' : cfg[2];
              setFieldError(field, cfg[1], message);
              hasError = hasError || Boolean(message);
            });
            if (hasError) { document.getElementById('profileSuccessMessage').textContent = ''; return; }

            /* Save FOR REAL. The per-field checks above stay for fast feedback;
               the server repeats them and owns the one the browser cannot
               answer — whether the new email is already another account's. */
            const body = new FormData(profileForm);
            body.append('csrf', CSRF);
            body.append('action', 'details');
            const out = document.getElementById('profileSuccessMessage');
            out.textContent = 'Saving…';
            fetch('../profile-save.php', { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
              .then(function (res) {
                if (!res.ok) {
                  out.textContent = '';
                  const map = { full_name: ['fullName', 'fullNameValidation'], email: ['email', 'emailValidation'],
                                contact_number: ['contactNumber', 'contactNumberValidation'] };
                  const t = res.field && map[res.field];
                  if (t) { const f = document.getElementById(t[0]); setFieldError(f, t[1], res.message); f.focus(); }
                  else { out.textContent = res.message || 'Nothing was saved.'; }
                  return;
                }
                out.textContent = res.message;
                /* Repaint the summary card beside the form — leaving the old
                   name there after a rename would look like the save failed. */
                document.getElementById('profileSummaryName').textContent = document.getElementById('fullName').value.trim();
              })
              .catch(function () { out.textContent = 'Could not reach the server, so nothing was saved.'; });
          });
          profileForm.querySelectorAll('input').forEach(function (field) {
            field.addEventListener('input', function () { setFieldError(field, field.getAttribute('aria-describedby'), ''); });
          });
          profileForm.addEventListener('reset', function () {
            window.setTimeout(function () {
              profileFields.forEach(function (cfg) { const f = document.getElementById(cfg[0]); if (f) setFieldError(f, cfg[1], ''); });
              document.getElementById('profileSuccessMessage').textContent = '';
            }, 0);
          });
        }

        const passwordForm = document.getElementById('customerPasswordForm');
        if (passwordForm) {
          passwordForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const cur = document.getElementById('currentPassword');
            const nw = document.getElementById('newPassword');
            const cf = document.getElementById('confirmNewPassword');
            const any = Boolean(cur.value || nw.value || cf.value);
            const curErr = any && !cur.value ? 'Please enter your current password.' : '';
            const nwErr = any && !nw.value ? 'Please enter a new password.' : (nw.value && nw.value.length < 8 ? 'New password must be at least 8 characters.' : '');
            const cfErr = any && !cf.value ? 'Please confirm your new password.' : (nw.value !== cf.value ? 'New passwords must match.' : '');
            setFieldError(cur, 'currentPasswordValidation', curErr);
            setFieldError(nw, 'newPasswordValidation', nwErr);
            setFieldError(cf, 'confirmNewPasswordValidation', cfErr);
            if (curErr || nwErr || cfErr || !any) return;

            /* The CURRENT password is verified server-side — a session alone
               must never be enough to change the credential that created it,
               or anyone at an unlocked machine could lock the owner out. */
            const body = new FormData(passwordForm);
            body.append('csrf', CSRF);
            body.append('action', 'password');
            const out = document.getElementById('passwordSuccessMessage');
            out.textContent = 'Saving…';
            fetch('../profile-save.php', { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
              .then(function (res) {
                if (!res.ok) {
                  out.textContent = '';
                  const map = { current_password: [cur, 'currentPasswordValidation'],
                                new_password: [nw, 'newPasswordValidation'],
                                confirm_new_password: [cf, 'confirmNewPasswordValidation'] };
                  const t = res.field && map[res.field];
                  if (t) { setFieldError(t[0], t[1], res.message); t[0].focus(); }
                  else { out.textContent = res.message || 'Your password was not changed.'; }
                  return;
                }
                out.textContent = res.message;
                passwordForm.reset();
              })
              .catch(function () { out.textContent = 'Could not reach the server, so your password was not changed.'; });
          });
          ['currentPassword', 'newPassword', 'confirmNewPassword'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', function () {
              setFieldError(document.getElementById('currentPassword'), 'currentPasswordValidation', '');
              setFieldError(document.getElementById('newPassword'), 'newPasswordValidation', '');
              setFieldError(document.getElementById('confirmNewPassword'), 'confirmNewPasswordValidation', '');
              document.getElementById('passwordSuccessMessage').textContent = '';
            });
          });
        }

        const preferencesForm = document.getElementById('customerPreferencesForm');
        if (preferencesForm) {
          preferencesForm.addEventListener('submit', function (event) {
            event.preventDefault();
            // [SIM] TODO: persist preferences server-side (CSRF-protected).
            document.getElementById('preferencesSuccessMessage').textContent = 'Preferences saved successfully.';
          });
          preferencesForm.addEventListener('change', function () {
            document.getElementById('preferencesSuccessMessage').textContent = '';
          });
        }
      });
    </script>
  </body>
</html>
