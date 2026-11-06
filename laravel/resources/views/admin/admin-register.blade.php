{{-- Standalone auth-style page (no admin shell) — ported from admin/admin-register.php. Admin only (route middleware staff:admin). --}}
<!DOCTYPE html>
<!-- ==================================================================
  ADMIN REGISTRATION — VENUSeP merged system
  ==================================================================
  Ported from the teammate's admin-register.php. Standalone AUTH page:
  NO sidebar/header. Restyled to the team palette; fields + validation
  preserved (venue, full name, email, contact, password + confirm).
  No terms box: the admin makes someone else's account, so agreeing for
  them would mean nothing (Aron 2026-11-05).

  WIRED: posts to register-submit.php, which creates the users + staff rows,
  then returns to the dashboard. The admin stays signed in as themselves —
  they created an account, they did not become it.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content" />
    <title>VENUSeP | Admin Registration</title>
    <link rel="icon" href="{{ asset('logo/Logo Header 3.png') }}" type="image/png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <!-- shared VENUSeP modal: your password, before creating an administrator -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/admin-register.css') }}" />
  </head>
  <body class="admin-register-page">
    <main class="auth-split">
      <!-- the crimson panel: purely decorative, so screen readers skip it -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-grain"></div>
        <svg class="auth-hero-arcs" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g transform="translate(560 300)"><circle r="120"/><circle r="200"/><circle r="280"/><circle r="360"/><circle r="440"/><circle r="520"/></g></svg>
        <img class="auth-hero-logo" src="{{ asset('logo/Logo Header 3.png') }}" alt="" />
        <div class="auth-hero-copy">
          <h2 class="auth-hero-title">Join <br />VENUSeP! <span class="auth-wave">&#128075;</span></h2>
          <p class="auth-hero-sub">Create a staff account for the venue or the hostel office.</p>
        </div>
        <p class="auth-hero-foot">&copy; 2026 VENUSeP &middot; University of Southeastern Philippines</p>
      </aside>
      <section class="auth-panel" aria-labelledby="adminRegisterTitle">
        <div class="auth-card">
          <a class="auth-back" href="{{ route('admin.staff') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Staff Management</a>
          <a class="auth-brand" href="{{ route('admin.admin-login') }}" title="VENUSeP staff"><img src="{{ asset('logo/Logo Header 3.png') }}" alt="VENUSeP" /></a>
          <div class="auth-heading">
            <h1 class="auth-title" id="adminRegisterTitle">Create a staff or admin account</h1>
            <p class="auth-subtitle" id="roleSubtitle">Staff handle one venue's bookings. Choose which venue below.</p>
          </div>

          <!-- WHICH KIND OF ACCOUNT (2026-10-27). An administrator handles every
               venue, so they get no venue; creating one asks for YOUR password
               (register-submit.php re-checks it), and they set up two-step
               verification the first time they sign in. -->
          <div class="role-pick" role="radiogroup" aria-label="Account type">
            <label class="role-option"><input type="radio" name="account_role" value="staff" checked> <span><b>Staff</b> One venue's bookings</span></label>
            @if ($canCreateAdmin)   {{-- the super admin only (DB-DECISIONS 28) --}}
            <label class="role-option"><input type="radio" name="account_role" value="admin"> <span><b>Administrator</b> One group's offices</span></label>
            @endif
          </div>

          <!-- Venue options come from the venues table through includes/venues.php. -->
          <form id="adminRegisterForm" action="" method="post" novalidate data-auth-form="register" data-redirect="{{ route('admin.admin-login') }}" data-success-target="registrationSuccessMessage" data-success-message="Account created successfully. Redirecting to login...">
            <div class="form-group" id="venueGroup">
              <label for="staffVenue" class="form-label">Venue</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-building" aria-hidden="true"></i></span>
                <select id="staffVenue" name="venue_id" aria-describedby="venueValidation" required>
                  <!-- no "Unassigned": every staff account handles one venue (2026-10-27) -->
                  <option value="" disabled selected>Select the venue they will handle</option>
                  @foreach ($venuesById as $venueId => $venueName)
                    <option value="{{ (int) $venueId }}">{{ $venueName }}</option>
                  @endforeach
                </select>
              </div>
              <small class="validation-message" id="venueValidation" aria-live="polite"></small>
            </div>

            @if ($canCreateAdmin)
            <div class="form-group" id="groupGroup" hidden>
              <label for="adminGroup" class="form-label">Group</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                <select id="adminGroup" name="office_group" aria-describedby="groupValidation">
                  <option value="" disabled selected>Select the group they will run</option>
                  <option value="bahay_alumni">Bahay Alumni</option>
                  <option value="cedu">USeP CEDU (USeP Venues and USeP Hostel)</option>
                </select>
              </div>
              <small class="validation-message" id="groupValidation" aria-live="polite"></small>
            </div>
            @endif

            <div class="form-group">
              <label for="adminFullName" class="form-label">Full Name</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span><input id="adminFullName" name="full_name" type="text" autocomplete="name" placeholder="Enter full name" aria-describedby="fullNameValidation" required /></div>
              <small class="validation-message" id="fullNameValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="adminEmail" class="form-label">Email Address</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span><input id="adminEmail" name="email" type="email" autocomplete="email" placeholder="admin@venusep.com" aria-describedby="emailValidation" required /></div>
              <small class="validation-message" id="emailValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="adminContactNumber" class="form-label">Contact Number</label>
              <div class="input-group"><span class="input-group-text"><i class="bi bi-telephone" aria-hidden="true"></i></span><input id="adminContactNumber" name="contact_number" type="tel" autocomplete="tel" placeholder="09XXXXXXXXX" aria-describedby="contactNumberValidation" required /></div>
              <small class="validation-message" id="contactNumberValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="adminPassword" class="form-label">Password</label>
              <div class="input-group password-field"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input id="adminPassword" name="password" type="password" autocomplete="new-password" placeholder="Create password" minlength="8" aria-describedby="passwordValidation" required /><button class="password-toggle" type="button" data-password-toggle="adminPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="passwordValidation" aria-live="polite"></small>
            </div>
            <div class="form-group">
              <label for="adminConfirmPassword" class="form-label">Confirm Password</label>
              <div class="input-group password-field"><span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><input id="adminConfirmPassword" name="confirm_password" type="password" autocomplete="new-password" placeholder="Confirm password" aria-describedby="confirmPasswordValidation" required /><button class="password-toggle" type="button" data-password-toggle="adminConfirmPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="confirmPasswordValidation" aria-live="polite"></small>
            </div>

            <button type="submit" class="btn-auth"><i class="bi bi-person-plus" aria-hidden="true"></i>Create Account</button>
            <small class="success-message" id="registrationSuccessMessage" aria-live="polite"></small>
          </form>
          <div class="auth-alt">
            <span class="auth-alt-text">Already have an account?</span>
            <a class="btn-auth btn-auth-outline" href="{{ route('admin.admin-login') }}">Login</a>
          </div>
        </div>
      </section>
    </main>

    <!-- Client-side validation for fast feedback; register-submit.php repeats every check.
         Replace with real PHP registration + database insert later. -->
    <script>
  /* Values from PHP for assets/js/admin/admin-register.js */
  var adminRegisterPage = {
    csrf: {!! json_encode(csrf_token()) !!},
    registerSubmitUrl: @json(route('root.register-submit')),
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/admin-register.js') }}"></script>
  </body>
</html>
