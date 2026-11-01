{{-- Ported from customer/forgot-password.php --}}
@extends('auth.customer-auth-shell')
@section('page-title', 'Forgot password')
@section('hero-title')Forgot your <br />password?@endsection
@section('hero-sub', 'It happens. We’ll email you a link to choose a new one.')
@section('labelledby', 'forgotTitle')
@section('card')
          <div class="auth-heading">
            <h1 class="auth-title" id="forgotTitle">{{ $fpSent ? 'Check your email' : 'Reset your password' }}</h1>
            <p class="auth-subtitle">{{ $fpSent ? '' : 'Enter the email you use for VENUSeP and we’ll send you a link.' }}</p>
          </div>
          @if ($fpSent)
          <p class="auth-notice" role="status">{{ PR_REQUEST_REPLY }}</p>
          <a class="btn-auth" href="{{ route('customer.customer-login') }}">Back to login</a>
          <div class="auth-alt">
            <span class="auth-alt-text">Didn&rsquo;t get it after a few minutes?</span>
            <a class="btn-auth btn-auth-outline" href="{{ route('customer.forgot-password') }}">Send another link</a>
          </div>
          @else
          <form id="forgotForm" action="{{ route('customer.forgot-password') }}" method="post">
            @csrf
            <div class="form-group">
              <label for="fpEmail" class="form-label">Email Address</label>
              <div class="input-group{{ $fpError !== '' ? ' is-invalid' : '' }}"><span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span><input id="fpEmail" name="email" type="email" autocomplete="email" placeholder="customer@example.com" maxlength="190" value="{{ $fpEmail }}" aria-describedby="fpEmailMsg" required autofocus /></div>
              <small class="validation-message" id="fpEmailMsg" aria-live="polite">{{ $fpError }}</small>
            </div>
            <button type="submit" class="btn-auth">Send reset link</button>
          </form>
          <div class="auth-alt">
            <span class="auth-alt-text">Remembered it?</span>
            <a class="btn-auth btn-auth-outline" href="{{ route('customer.customer-login') }}">Back to login</a>
          </div>
          @endif
@endsection
