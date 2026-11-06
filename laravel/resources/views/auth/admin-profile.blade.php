{{-- Ported from admin/venusep_profile.php — the signed-in staff member's own account. --}}
@extends('layouts.admin')
@section('title', 'Profile Settings')
@section('active', 'Settings')
@push('head')
  <link crossorigin="anonymous" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" rel="stylesheet"/>
  <!-- shared VENUSeP modal (the "Remove your photo?" confirm) -->
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}"/>
  <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
  <link href="{{ asset_stamped('assets/css/two-factor.css') }}" rel="stylesheet"/>
@endpush
@push('styles')
<link rel="stylesheet" href="{{ asset_stamped('assets/css/auth/admin-profile.css') }}" />
@endpush
@section('content')
  <main class="app-main">
    <div class="app-content profile-content">
      <div class="container-fluid">
        <div class="page-heading">
          <h1>Settings</h1>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
              <li class="breadcrumb-item">Dashboard</li>
              <li aria-current="page" class="breadcrumb-item active">Settings</li>
            </ol>
          </nav>
        </div>
        <section class="profile-layout">
          <aside class="profile-card">
            @if ($vpPhoto !== '')
              <img class="profile-avatar profile-avatar-img" id="vpAvatar" src="{{ asset($vpPhoto) }}" alt="Your profile picture">
            @else
              <div aria-hidden="true" class="profile-avatar" id="vpAvatar">{{ $vpInitials }}</div>
            @endif
            <h2 id="vpCardName">{{ $vpName ?: 'Staff' }}</h2>
            <p>{{ $vpRoleLine }}</p>
            @if ($vpEmail !== '')<p class="profile-card-email">{{ $vpEmail }}</p>@endif
            <!-- the picture this person chose to show — profile-save.php action=photo
                 (stored under assets/, never an ID or a receipt) -->
            <div class="profile-photo-actions">
              <input type="file" id="vpPhotoInput" accept="image/png,image/jpeg,image/webp" hidden>
              <button type="button" class="profile-photo-btn" onclick="document.getElementById('vpPhotoInput').click()">{!! $vpPhoto !== '' ? 'Change photo' : 'Add photo' !!}</button>
              <button type="button" class="profile-photo-btn" id="vpPhotoRemove"{!! $vpPhoto !== '' ? '' : ' hidden' !!}>Remove</button>
            </div>
            <small class="profile-photo-hint" id="vpPhotoHint" style="display:block;color:#6b675f;font-size:.78rem">PNG, JPG or WebP, up to 7 MB.</small>
            <div class="profile-photo-msg" id="vpPhotoMsg" aria-live="polite"></div>
          </aside>
          <article class="settings-panel">
            <div class="settings-panel-header">
            <h2>Settings</h2>
            </div>
          <form class="settings-form" id="vpForm" novalidate>
            <div class="form-field">
              <label class="form-label" for="profile-first">First name</label>
              <input class="form-control" id="profile-first" type="text" value="{{ $vpFirst }}"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-last">Last name</label>
              <input class="form-control" id="profile-last" type="text" value="{{ $vpLast }}"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-email">Email</label>
              <input class="form-control" id="profile-email" type="email" value="{{ $vpEmail }}" data-original="{{ strtolower($vpEmail) }}"/>
            </div>
            {{-- Shown only while the email is being changed: a new email needs the current password (profile-save). --}}
            <div class="form-field" id="profile-email-pw-field" hidden>
              <label class="form-label" for="profile-email-pw">Current password <span style="font-weight:400">(to change your email)</span></label>
              <input class="form-control" id="profile-email-pw" type="password" autocomplete="current-password"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-number">Number</label>
              <input class="form-control" id="profile-number" type="text" value="{{ $vpPhone }}" placeholder="09XX XXX XXXX"/>
            </div>
            <!-- Role, position and employee number are what the organisation says about
                 you, not what you say about you: shown read-only. The role comes from the
                 account (DB-DECISIONS 28), the employee number from the database (migration 18). -->
            <div class="form-field">
              <label class="form-label" for="profile-role">Role</label>
              <input class="form-control" id="profile-role" type="text" value="{{ $vpAccess }}" readonly/>
            </div>
            @if ($vpRole !== '')
            <div class="form-field">
              <label class="form-label" for="profile-position">Position</label>
              <input class="form-control" id="profile-position" type="text" value="{{ $vpRole }}" readonly/>
            </div>
            @endif
            <div class="form-field">
              <label class="form-label" for="profile-emp">Employee no.</label>
              <input class="form-control" id="profile-emp" type="text" value="{{ $vpEmp ?: '—' }}" readonly/>
            </div>
            <div class="form-field form-field-full">
              <div id="vpMessage" role="status" aria-live="polite" style="font-size:.85rem;min-height:1.2em"></div>
            </div>
            <div class="form-actions">
              <button class="btn btn-primary-dark" type="submit">Save changes</button>
              <button class="btn btn-outline-dark" type="reset">Cancel</button>
            </div>
          </form>
          {{-- Change password — profile-save action=password (current password required; the
               same one again is refused). Staff whose password the admin typed are held on this
               page until they set their own (EnforceTwoFactor, Aron 2026-10-31). --}}
          <div class="settings-panel-header" id="vpPassword" style="border-top:1px solid var(--venusep-border)">
            <h2>Change password</h2>
          </div>
          <form class="settings-form" id="vpPwForm" novalidate>
            @if ($vpMustChange)
            <div class="form-field form-field-full" role="alert" style="padding:.7rem .85rem;border-radius:8px;background:#fcf6f4;border:1px solid #efe0db;color:#4a4440;font-size:.85rem;line-height:1.5">
              <strong>Set your own password to continue.</strong> An administrator chose the password you signed in with. Replace it with one only you know.
            </div>
            @endif
            <div class="form-field form-field-full">
              <label class="form-label" for="pw-current">{{ $vpMustChange ? 'Password you were given' : 'Current password' }}</label>
              <div class="pw-field"><input class="form-control" id="pw-current" name="current_password" type="password" autocomplete="current-password" required{{ $vpMustChange ? ' autofocus' : '' }}/><button class="pw-eye" type="button" data-password-toggle="pw-current" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
            </div>
            <div class="form-field">
              <label class="form-label" for="pw-new">New password</label>
              <div class="pw-field"><input class="form-control" id="pw-new" name="new_password" type="password" autocomplete="new-password" minlength="8" required/><button class="pw-eye" type="button" data-password-toggle="pw-new" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
            </div>
            <div class="form-field">
              <label class="form-label" for="pw-confirm">Confirm new password</label>
              <div class="pw-field"><input class="form-control" id="pw-confirm" name="confirm_new_password" type="password" autocomplete="new-password" minlength="8" required/><button class="pw-eye" type="button" data-password-toggle="pw-confirm" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
            </div>
            <div class="form-field form-field-full">
              <div id="vpPwMessage" role="status" aria-live="polite" style="font-size:.85rem;min-height:1.2em"></div>
            </div>
            <div class="form-actions">
              <button class="btn btn-primary-dark" type="submit">Change password</button>
            </div>
          </form>
          <script>
  /* Values from PHP for assets/js/auth/admin-profile.js */
  var adminProfilePage = {
    csrf: @json($vpCsrf),
    profileSaveUrl: @json(route('root.profile-save')),
    vpMustChange: @json($vpMustChange),
    dashboardUrl: @json(route('admin.dashboard')),
  };
</script>
<script src="{{ asset_stamped('assets/js/auth/admin-profile.js') }}"></script>
        </article>

        <!-- TWO-STEP VERIFICATION (DB-DECISIONS #20). Admins only for now (staff come later).
             Driven by assets/js/two-factor.js
             against profile-save.php, which refuses to turn it off for an admin. -->
        @if ($vpTfaShow)
        @fragment('tfa-panel')
        <section class="dm-card tfa-panel" aria-labelledby="tfaTitle" data-live="tfa-panel" data-tfa-panel data-endpoint="{{ route('root.profile-save') }}" data-csrf="{{ $vpCsrf }}">
          <div class="dm-top">
            <div>
              <div class="dm-title">
                <h2 id="tfaTitle">Two-step verification</h2>
                @if ($vpTfa !== null && $vpTfa['enabled'])<span class="dm-pill tfa-pill-on">ON</span>@endif
              </div>
              <p class="dm-state">Required for admin accounts. You enter a code from your phone each time you sign in.</p>
            </div>
            @if ($vpTfa !== null && $vpTfa['enabled'])
            <div class="tfa-actions" data-tfa-actions>
              <button class="dm-btn" type="button" data-tfa-action="move" data-tfa-ask="Current code from your phone" data-tfa-go="Continue"><i class="bi bi-phone" aria-hidden="true"></i>Move to a new phone</button>
              <button class="dm-btn{!! $vpTfaLow ? ' tfa-btn-primary' : '' !!}" type="button" data-tfa-action="codes" data-tfa-ask="Current code from your phone" data-tfa-go="Make new codes"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>New recovery codes</button>
            </div>
            @endif
          </div>
          @if ($vpTfa === null)
          <p class="dm-state dm-error">Two-step verification settings cannot be loaded right now. Reload the page to try again.</p>
          @elseif (!$vpTfa['enabled'])
          <p class="dm-state dm-error">Two-step verification is not set up on this account yet. Sign out, then sign in again to set it up.</p>
          @else
          @if ($vpTfaLow)
          <p class="tfa-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{!! (int) $vpTfa['codes_left'] === 1 ? 'Only 1 recovery code left.' : 'Only ' . (int) $vpTfa['codes_left'] . ' recovery codes left.' !!} Make new ones now. If you lose your phone with none left, nobody can reset your account from the app.</span></p>
          @endif

          @php(tfa_view_panel_steps(true, $vpEmail, ['btn' => 'dm-btn', 'primary' => 'dm-btn tfa-btn-primary', 'input' => 'tfa-code-input']))
          <div class="dm-meta">
            <span>Set up <strong>{{ date('j M Y', strtotime((string) $vpTfa['enabled_at'])) }}</strong></span>
            <span><strong>{!! (int) $vpTfa['codes_left'] !!}</strong> of {!! TFA_RECOVERY_CODE_COUNT !!} recovery codes left</span>
          </div>
          @endif
        </section>
        @endfragment
        @endif

        </section>
      </div>
    </div>
  </main>
@endsection
@push('scripts')
<script crossorigin="anonymous" src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js"></script>
<script crossorigin="anonymous" src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script crossorigin="anonymous" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="{{ asset_stamped('assets/js/auth/admin-profile-sidebar.js') }}"></script>
    <script src="{{ asset_stamped('assets/js/vendor/qrcode-generator-1.4.4.js') }}"></script>
    <script src="{{ asset_stamped('assets/js/two-factor.js') }}"></script>
@endpush
