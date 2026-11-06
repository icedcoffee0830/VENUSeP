{{-- Ported from customer/customer-profile.php — the session customer's own record; saves to profile-save. --}}
@extends('layouts.customer')
@section('title', 'My Profile')
@section('active', 'Profile')
@section('body-class', 'customer-profile-page')
@push('head')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/two-factor.css') }}" />
@endpush
@push('styles')
    <!-- [0] the lines of this page's old shell CSS that differ from the layout's -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/auth/customer-profile.css') }}" />

    <!-- [1] PAGE CSS — profile page, self-contained in the team palette -->
    
@endpush
@section('content')
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
                  @fragment('cp-avatar')
                  <div data-live="cp-avatar" style="display:contents">
@if ($customerProfile['photo'] !== '')
                  <img class="profile-avatar" id="profileAvatar" src="{{ asset($customerProfile['photo']) }}"
                       alt="{{ $customerProfile['name'] }}" style="object-fit:cover">
@else
                  @php
                    /* first two words' initials — the SAME rule as the booking-page
                       header (initials() there), so "Juan Miguel Dela Cruz" -> "JM".
                       Derived, so the avatar can never drift from the name. */
                    $cpParts = preg_split('/\s+/', trim($customerProfile['name']));
                    $cpIni = '';
                    foreach (array_slice($cpParts, 0, 2) as $cpW) $cpIni .= substr($cpW, 0, 1);
                  @endphp
                  <div class="profile-avatar" id="profileAvatar" aria-label="{{ $customerProfile['name'] }} initials">{{ strtoupper($cpIni) }}</div>
@endif
                  </div>
                  @endfragment
                  <h2 id="profileSummaryName">{{ $customerProfile['name'] }}</h2>
                  <p class="profile-role">Customer</p>
                  <span class="profile-status"><i class="bi bi-check-circle" aria-hidden="true"></i>Active</span>
                  <dl class="profile-meta">
                    <div><dt>Email</dt><dd>{{ $customerProfile['email'] }}</dd></div>
                    <div><dt>Contact Number</dt><dd>{{ $customerProfile['phone'] }}</dd></div>
                    <div><dt>Address</dt><dd>{{ $customerProfile['address'] }}</dd></div>
                    <div><dt>Member Since</dt><dd>{{ $customerProfile['memberSince'] }}</dd></div>
                  </dl>
                  <!-- Profile picture only. Sensitive documents go to booking_documents,
                       outside the web root, behind document-view.php. -->
                  <input type="file" id="avatarInput" accept="image/png,image/jpeg,image/webp" hidden>
                  <button class="btn-profile" type="button" onclick="document.getElementById('avatarInput').click()"><i class="bi bi-camera" aria-hidden="true"></i>Change Photo</button>
                  @fragment('cp-avatar-remove')
                  <div data-live="cp-avatar-remove" style="display:contents">
@if ($customerProfile['photo'] !== '')
                  <button class="btn-profile" type="button" id="avatarRemove"><i class="bi bi-trash" aria-hidden="true"></i>Remove</button>
@endif
                  </div>
                  @endfragment
                  <small class="signin-note" id="avatarHint" style="display:block">PNG, JPG or WebP, up to 7 MB.</small>
                  <div class="profile-validation" id="avatarMessage" aria-live="polite"></div>
                </aside>

                <div class="profile-details">
                  <section class="profile-section" aria-labelledby="personalInformationTitle">
                    <div class="profile-section-header"><h2 id="personalInformationTitle">Personal Information</h2><p>Keep your contact details up to date.</p></div>
                    <form id="customerProfileForm" action="" method="post" novalidate>
                      <div class="profile-form-grid">
                        <div class="profile-form-group"><label for="fullName">Full Name</label><input class="form-control" id="fullName" name="full_name" type="text" autocomplete="name" value="{{ $customerProfile['name'] }}" aria-describedby="fullNameValidation" required><small class="profile-validation" id="fullNameValidation" aria-live="polite"></small></div>
                        <div class="profile-form-group"><label for="email">Email Address</label><input class="form-control" id="email" name="email" type="email" autocomplete="email" value="{{ $customerProfile['email'] }}" data-original="{{ strtolower($customerProfile['email']) }}" aria-describedby="emailValidation" required><small class="profile-validation" id="emailValidation" aria-live="polite"></small></div>
                        {{-- Shown only while the email is being changed: a new email needs the current password (profile-save). --}}
                        @fragment('cp-email-password')
                        <div data-live="cp-email-password" style="display:contents">
                        @if ($cpHasPassword)
                        <div class="profile-form-group" id="emailPasswordGroup" hidden><label for="emailPassword">Current Password <span style="font-weight:400;color:#6e6a64">(to change your email)</span></label><input class="form-control" id="emailPassword" name="current_password" type="password" autocomplete="current-password" aria-describedby="emailPasswordValidation"><small class="profile-validation" id="emailPasswordValidation" aria-live="polite"></small></div>
                        @elseif (!$cpReauthOk)
                        <div class="profile-form-group" id="emailPasswordGroup" hidden><p class="signin-note" role="status">To change your email, first confirm it&rsquo;s you with Google (Account Security, below).</p></div>
                        @endif
                        </div>
                        @endfragment
                        <div class="profile-form-group"><label for="contactNumber">Contact Number</label><input class="form-control" id="contactNumber" name="contact_number" type="tel" autocomplete="tel" value="{{ $customerProfile['phone'] }}" aria-describedby="contactNumberValidation" required><small class="profile-validation" id="contactNumberValidation" aria-live="polite"></small></div>
                        <div class="profile-form-group"><label for="universityId">USeP ID number <span style="font-weight:400;color:#6e6a64">(optional)</span></label><input class="form-control" id="universityId" name="university_id_no" type="text" value="{{ $customerProfile['universityId'] }}" placeholder="e.g. 2023-00412"><small class="profile-validation">Staff check your uploaded ID against this when applying the USeP discount.</small></div>
                        <div class="profile-form-group full-width"><label for="address">Address</label><input class="form-control" id="address" name="address" type="text" autocomplete="street-address" value="{{ $customerProfile['address'] }}" required></div>
                      </div>
                      <div class="profile-actions"><button class="btn-profile btn-profile-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i>Save Changes</button><button class="btn-profile" type="reset">Cancel</button></div>
                      <small class="profile-success" id="profileSuccessMessage" aria-live="polite"></small>
                    </form>
                  </section>

                  <section class="profile-section" aria-labelledby="accountSecurityTitle">
                    <div class="profile-section-header"><h2 id="accountSecurityTitle">Account Security</h2><p>{!! $cpHasPassword
                        ? 'Use a strong password that you do not reuse elsewhere.'
                        : 'You sign in with Google. Add a password to also sign in with your email.' !!}</p></div>
                    @fragment('cp-security')
                    <div data-live="cp-security">
                    @if ($cpNotice !== '')<p class="signin-notice" role="status">{{ $cpNotice }}</p>@endif
                    <ul class="signin-methods" aria-label="Ways you can sign in">
                      <li><i class="bi bi-key" aria-hidden="true"></i><span class="signin-name">Email and password</span><span class="signin-state{!! $cpHasPassword ? ' is-on' : '' !!}">{!! $cpHasPassword ? 'Set' : 'Not set' !!}</span></li>
                      <li>{!! file_get_contents(public_path('assets/img/google-g.svg')) !!}<span class="signin-name">Google</span><span class="signin-state{!! $cpHasGoogle ? ' is-on' : '' !!}">{!! $cpHasGoogle ? 'Connected' : 'Not connected' !!}</span></li>
                    </ul>
                    @if (!$cpHasPassword && !$cpReauthOk)
                    <!-- SET A FIRST PASSWORD, step 1: a fresh trip to the linked Google account
                         is the proof (there is no current password to ask for). -->
                    <p class="signin-note">To set a password, first confirm it&rsquo;s you with Google. You then have 10 minutes to choose one.</p>
                    <div class="profile-actions" style="margin-top:10px"><a class="btn-profile btn-profile-primary" href="{{ route('customer.google-start', ['purpose' => 'reauth']) }}"><i class="bi bi-shield-check" aria-hidden="true"></i>Confirm with Google</a></div>
                    @else
                    <form id="customerPasswordForm" action="" method="post" novalidate>
                      <div class="profile-form-grid">
                        @if ($cpHasPassword)
                        <div class="profile-form-group full-width"><label for="currentPassword">Current Password</label><div class="input-group"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input class="form-control" id="currentPassword" name="current_password" type="password" autocomplete="current-password" aria-describedby="currentPasswordValidation"><button class="profile-password-toggle" type="button" data-profile-password-toggle="currentPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small class="profile-validation" id="currentPasswordValidation" aria-live="polite"></small></div>
                        @endif
                        <div class="profile-form-group"><label for="newPassword">New Password</label><div class="input-group"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input class="form-control" id="newPassword" name="new_password" type="password" autocomplete="new-password" minlength="8" aria-describedby="newPasswordValidation"><button class="profile-password-toggle" type="button" data-profile-password-toggle="newPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small class="profile-validation" id="newPasswordValidation" aria-live="polite"></small></div>
                        <div class="profile-form-group"><label for="confirmNewPassword">Confirm New Password</label><div class="input-group"><span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><input class="form-control" id="confirmNewPassword" name="confirm_new_password" type="password" autocomplete="new-password" aria-describedby="confirmNewPasswordValidation"><button class="profile-password-toggle" type="button" data-profile-password-toggle="confirmNewPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small class="profile-validation" id="confirmNewPasswordValidation" aria-live="polite"></small></div>
                      </div>
                      <div class="profile-actions"><button class="btn-profile btn-profile-primary" type="submit"><i class="bi bi-shield-check" aria-hidden="true"></i>{!! $cpHasPassword ? 'Update Password' : 'Set Password' !!}</button></div>
                      <small class="profile-success" id="passwordSuccessMessage" aria-live="polite"></small>
                    </form>
                    @endif

                    @if ($cpHasGoogle || $cpGoogleOn)
                    <!-- GOOGLE (#23). Connecting and disconnecting both re-ask the password
                         (profile-save.php); connecting then goes to Google and back. -->
                    <div class="signin-google" data-google-panel>
                      <h3>Sign in with Google</h3>
                      @if ($cpHasGoogle && $cpHasPassword)
                      <p class="signin-note">Disconnecting leaves you signing in with your email and password only.</p>
                      <div class="profile-actions" style="margin-top:10px"><button class="btn-profile btn-profile-danger" type="button" data-google-action="google_unlink" data-google-go="Disconnect Google">{!! file_get_contents(public_path('assets/img/google-g.svg')) !!}Disconnect Google</button></div>
                      @elseif ($cpHasGoogle)
                      <p class="signin-note">Google is your only way to sign in, so it cannot be disconnected until you set a password.</p>
                      @else
                      <p class="signin-note">Connect your Google account to sign in with one click. Any Google account you own works; it does not have to use this email.</p>
                      <div class="profile-actions" style="margin-top:10px"><button class="btn-profile" type="button" data-google-action="google_link_begin" data-google-go="Continue to Google">{!! file_get_contents(public_path('assets/img/google-g.svg')) !!}Connect Google</button></div>
                      @endif
                      <div class="google-confirm" hidden>
                        <div class="profile-form-group"><label for="googlePassword">Confirm with your password</label><div class="input-group"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input class="form-control" id="googlePassword" type="password" autocomplete="current-password" aria-describedby="googlePasswordMessage"><button class="profile-password-toggle" type="button" data-profile-password-toggle="googlePassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small class="profile-validation" id="googlePasswordMessage" aria-live="polite"></small></div>
                        <div class="profile-actions"><button class="btn-profile btn-profile-primary" type="button" data-google-go-button>Continue</button><button class="btn-profile" type="button" data-google-cancel>Cancel</button></div>
                      </div>
                    </div>
                    @endif
                    </div>
                    @endfragment
                  </section>

                  <!-- TWO-STEP VERIFICATION (DB-DECISIONS #20). Optional for customers. Driven by
                       assets/js/two-factor.js against profile-save.php; every step is re-checked there. -->
                  @php($cpTfaOn = $cpTfa !== null && $cpTfa['enabled'])
                  @php($cpTfaLow = $cpTfaOn && $cpTfa['codes_left'] <= TFA_CODES_LOW)
                  @fragment('tfa-panel')
                  <section class="profile-section tfa-panel" aria-labelledby="twoStepTitle" data-live="tfa-panel" data-tfa-panel data-endpoint="{{ route('root.profile-save') }}" data-csrf="{{ $cpCsrf }}">
                    <div class="profile-section-header">
                      <div class="tfa-head">
                        <h2 id="twoStepTitle">Two-step verification</h2>
                        @if ($cpTfa !== null)
                        <span class="tfa-chip {!! $cpTfaOn ? 'tfa-chip-on' : 'tfa-chip-off' !!}">{!! $cpTfaOn ? '<i class="bi bi-check2" aria-hidden="true"></i>On' : 'Off' !!}</span>
                        @endif
                      </div>
                      <p>{!! $cpTfaOn
                          ? 'You enter a code from your phone each time you sign in.'
                          : 'Ask for a 6-digit code from your phone after your password, so a stolen password alone cannot open your account.' !!}</p>
                    </div>
                    @if ($cpTfa === null)
                    <p class="tfa-message is-bad">Two-step verification settings cannot be loaded right now. Reload the page to try again.</p>
                    @else
                    @if ($cpTfaOn)
                    <ul class="tfa-facts">
                      <li><i class="bi bi-calendar-check" aria-hidden="true"></i>Turned on {{ date('j M Y', strtotime((string) $cpTfa['enabled_at'])) }}</li>
                      <li><i class="bi bi-life-preserver" aria-hidden="true"></i><span><strong>{!! (int) $cpTfa['codes_left'] !!}</strong> of {!! TFA_RECOVERY_CODE_COUNT !!} recovery codes left</span></li>
                    </ul>
                    @if ($cpTfaLow)
                    <p class="tfa-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{!! (int) $cpTfa['codes_left'] === 1 ? 'Only 1 recovery code left.' : 'Only ' . (int) $cpTfa['codes_left'] . ' recovery codes left.' !!} Make new ones now, so losing your phone does not lock you out.</span></p>
                    @endif
                    @endif
                    <div class="profile-actions"{!! $cpTfaOn ? '' : ' style="margin-top:0"' !!} data-tfa-actions>
                      @if ($cpTfaOn)
                      <button class="btn-profile" type="button" data-tfa-action="move" data-tfa-ask="Current code or recovery code" data-tfa-go="Continue"><i class="bi bi-phone" aria-hidden="true"></i>Move to a new phone</button>
                      <button class="btn-profile{!! $cpTfaLow ? ' btn-profile-primary' : '' !!}" type="button" data-tfa-action="codes" data-tfa-ask="Current code or recovery code" data-tfa-go="Make new codes"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>New recovery codes</button>
                      <button class="btn-profile btn-profile-danger" type="button" data-tfa-action="disable" data-tfa-ask="Current code or recovery code" data-tfa-go="Turn off">Turn off</button>
                      @elseif (!$cpHasPassword)
                      <!-- turning it on confirms with the password, which a Google-only account does not have yet (#23) -->
                      <p class="signin-note">Set a password first (Account Security, above), then you can turn this on.</p>
                      @else
                      <button class="btn-profile btn-profile-primary" type="button" data-tfa-action="begin" data-tfa-ask="Your password" data-tfa-go="Continue"><i class="bi bi-shield-lock" aria-hidden="true"></i>Turn on</button>
                      @endif
                    </div>

                    @php(tfa_view_panel_steps($cpTfaOn, $customerProfile['email'], ['btn' => 'btn-profile', 'primary' => 'btn-profile btn-profile-primary', 'input' => 'form-control tfa-code-input']))
                    @endif
                  </section>
                  @endfragment

                  <section class="profile-section" aria-labelledby="accountActionsTitle">
                    <div class="profile-section-header"><h2 id="accountActionsTitle">Account Actions</h2><p>Manage access to your VENUSeP account.</p></div>
                    <div class="account-actions"><a class="btn-profile account-faq" href="{{ route('customer.faq', ['in' => 'app']) }}"><i class="bi bi-question-circle" aria-hidden="true"></i>FAQ</a><a class="btn-profile" href="{{ route('customer.logout') }}"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Log Out</a><!-- [SIM] deactivate not built --><button class="btn-profile btn-profile-danger" type="button" disabled title="Account deactivation is under development"><i class="bi bi-person-x" aria-hidden="true"></i>Deactivate Account</button></div>
                  </section>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    <!-- [6] PAGE SCRIPT — validation + show/hide password (front-end only) -->
    <script>
  /* Values from PHP for assets/js/auth/customer-profile.js */
  var customerProfilePage = {
    csrf: @json($cpCsrf),
    profileSaveUrl: @json(route('root.profile-save')),
  };
</script>
<script src="{{ asset_stamped('assets/js/auth/customer-profile.js') }}"></script>
    <script src="{{ asset_stamped('assets/js/vendor/qrcode-generator-1.4.4.js') }}"></script>
    <script src="{{ asset_stamped('assets/js/two-factor.js') }}"></script>
@endpush
