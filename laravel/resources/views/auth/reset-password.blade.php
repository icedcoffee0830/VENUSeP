{{-- Ported from customer/reset-password.php --}}
@extends('auth.customer-auth-shell')
@section('page-title', 'Reset password')
@section('hero-title')Choose a new <br />password @endsection
@section('hero-sub', 'Use one you don’t use anywhere else. You’ll log in with it straight after.')
@section('labelledby', 'resetTitle')
@section('card')
          @if ($reset === null)
          <div class="auth-heading">
            <h1 class="auth-title" id="resetTitle">This link can&rsquo;t be used</h1>
            <p class="auth-subtitle">It has expired, was already used, or a newer link was sent after it. Links work once, for 30 minutes.</p>
          </div>
          <a class="btn-auth" href="{{ route('customer.forgot-password') }}">Send a new link</a>
          <div class="auth-alt">
            <span class="auth-alt-text">Remembered your password?</span>
            <a class="btn-auth btn-auth-outline" href="{{ route('customer.customer-login') }}">Back to login</a>
          </div>
          @else
          <div class="auth-heading">
            <h1 class="auth-title" id="resetTitle">Choose a new password</h1>
            <p class="auth-subtitle">For {{ $reset['email'] }}</p>
          </div>
          @if (!empty($rpErrors['form']))<p class="auth-notice" role="alert">{{ $rpErrors['form'] }}</p>@endif
          <form id="resetForm" action="{{ route('customer.reset-password') }}" method="post">
            @csrf
            <input type="email" name="username" value="{{ $reset['email'] }}" autocomplete="username" hidden />
            <div class="form-group">
              <label for="rpNew" class="form-label">New password</label>
              <div class="input-group password-field{{ !empty($rpErrors['new_password']) ? ' is-invalid' : '' }}"><span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span><input id="rpNew" name="new_password" type="password" autocomplete="new-password" placeholder="New password (8+ characters)" minlength="8" aria-describedby="rpNewMsg" required autofocus /><button class="password-toggle" type="button" data-password-toggle="rpNew" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="rpNewMsg" aria-live="polite">{{ $rpErrors['new_password'] ?? '' }}</small>
            </div>
            <div class="form-group">
              <label for="rpConfirm" class="form-label">Confirm new password</label>
              <div class="input-group password-field{{ !empty($rpErrors['confirm_password']) ? ' is-invalid' : '' }}"><span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><input id="rpConfirm" name="confirm_password" type="password" autocomplete="new-password" placeholder="Confirm new password" aria-describedby="rpConfirmMsg" required /><button class="password-toggle" type="button" data-password-toggle="rpConfirm" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
              <small class="validation-message" id="rpConfirmMsg" aria-live="polite">{{ $rpErrors['confirm_password'] ?? '' }}</small>
            </div>
            <button type="submit" class="btn-auth">Change password</button>
          </form>
          <p class="auth-footer-link">Changing it signs this account out everywhere else.</p>
          @endif
@endsection
