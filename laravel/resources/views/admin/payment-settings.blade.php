{{-- Payment Settings (was admin/payment-settings.php): the refund switch card + one GCash
     account card per venue. Header + sidebar come from the layout. --}}
@extends('layouts.admin')
@section('title', 'Payment Settings')
@section('active', 'Payment Settings')

@push('head')
    <!-- shared VENUSeP modal: the password re-entry for a GCash account change -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
    {{-- in the head: the refund-switch script below the form needs venusepEye --}}
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/payment-settings.css') }}" />
    <!-- ============================================================
         [1] PAGE CSS — same tokens and the same neutral-surface look
         as Venue Management (hairlines, no filled tint boxes).
         ============================================================ -->
    
@endpush

@section('content')
<!-- ==========================================================
           [4] PAGE CONTENT — one card per venue. Rendered from PHP so
           the cards and the booking pages read the same array.
           ========================================================== -->
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="ps-header">
              <div class="ps-heading">
                <h1>Payment Settings</h1>
                <p>Whether customers can request refunds, and the GCash account each venue's payments go to.</p>
              </div>
            </div>

            <!-- REFUND REQUESTS — one switch per office, the super admin's only (DB-DECISIONS 28).
                 Everything shown here is re-checked by RefundSwitchController. -->
            @fragment('rs-card')
            <section class="rs-card" id="rsCard" aria-labelledby="rsTitle" data-live="rs-card">
              @if ($rsJustSaved)
                <div class="rs-flash" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Saved. Refund requests at {{ $rsJustSaved['name'] }} are now {{ $rsJustSaved['refunds'] ? 'ON' : 'OFF' }}.</div>
              @endif
              <div class="rs-top">
                <div class="rs-title">
                  <h2 id="rsTitle">Refund Requests</h2>
                </div>
                @if ($rsDbOk && !$rsIsAdmin)
                  <span class="rs-lock"><i class="bi bi-lock-fill" aria-hidden="true"></i>Only the super admin can change this</span>
                @endif
              </div>
              @if (!$rsDbOk)
                <p class="rs-state rs-error">The database cannot be reached, so this setting cannot be read or changed right now. Until it is back, customers are shown the no-refund policy.</p>
              @endif
              @foreach ($rsOffices as $o)
                @php $hostel = $o['type'] === 'hostel'; @endphp
                <div class="rs-office">
                  <div>
                    <div class="rs-title">
                      <h3>{{ $o['name'] }}</h3>
                      <span class="rs-pill {{ $o['refunds'] ? 'rs-pill-on' : 'rs-pill-off' }}">{{ $o['refunds'] ? 'ON' : 'OFF' }}</span>
                    </div>
                    <p class="rs-state">
                      @if ($o['refunds'])
                        <strong>Customers may request refunds</strong> on {{ $hostel ? 'stays' : 'bookings' }} made while this is ON.
                      @else
                        <strong>New {{ $hostel ? 'stays' : 'bookings' }} are non-refundable.</strong> Customers confirm this before they book.
                      @endif
                      <br>
                      @switch (office_payment_policy($o))
                        @case ('pay_to_use') Payment: always before the stay (fixed). @break
                        @case ('prepay') Payment: <strong>before the event</strong>, after approval, by the day before. @break
                        @default Payment: <strong>after the event</strong>, due the day after the last booked day; overdue after {{ $o['grace'] }} days, nothing is released.
                      @endswitch
                    </p>
                    <div class="rs-meta">
                      @if ($o['last'])
                        Last changed by <strong>{{ $o['last']['email'] ?? 'a removed account' }}</strong> on {{ date('M j, Y \a\t g:i A', strtotime($o['last']['changed_at'])) }}
                      @else
                        Not changed since each office got its own switch (default: OFF).
                      @endif
                    </div>
                  </div>
                  @if ($rsIsAdmin)
                    <button type="button" class="ps-btn {{ $o['refunds'] ? 'ps-btn-primary' : 'rs-btn-on' }}" data-rs-open
                            data-venue="{{ $o['id'] }}" data-name="{{ $o['name'] }}" data-on="{{ $o['refunds'] ? 1 : 0 }}" data-hostel="{{ $hostel ? 1 : 0 }}"
                            data-stay="{{ $o['stay'] }}" data-open="{{ $o['open'] }}" data-grace="{{ $o['grace'] }}"
                            aria-label="{{ ($o['refunds'] ? 'Turn refunds OFF at ' : 'Turn refunds ON at ') . $o['name'] }}">
                      {{ $o['refunds'] ? 'Turn refunds OFF' : 'Turn refunds ON' }}
                    </button>
                  @endif
                </div>
              @endforeach
              <ul class="rs-rules">
                <li>A change applies to <strong>new bookings at that office only</strong>. Every booking keeps the policy it was made under.</li>
                <li>With refunds OFF, nothing is refundable through VENUSeP, even when the office closes. Staff handle those cases outside the system.</li>
                <li>Changing it needs your password, and every change is recorded in the office's settings history.</li>
              </ul>
            </section>
            @endfragment

            <div class="ps-why">
              Each number below is used <strong>twice</strong>: the booking page tells the customer where to send the money, and the receipt checker verifies it landed there. Both read the same value, so they cannot drift apart — change it here and both move together. <strong>A wrong number does not fail loudly:</strong> the customer pays exactly what they were shown and every receipt is auto-rejected as “receiver mismatch”, which looks like the scanner is broken.
            </div>
          </div>
        </div>

        <div class="app-content">
          <div class="container-fluid">
            <div class="ps-grid" id="psGrid">
@foreach ($psCards as $venue => $acct)
@php $isHostel = in_array($venue, $psHostelVenues, true); @endphp
              <div class="ps-card" data-venue="{{ $venue }}">
                <div class="ps-venue">
                  <h2>{{ $venue }}</h2>
                  <span class="ps-kind">{{ $isHostel ? 'Staff account' : 'Business account' }}</span>
                </div>
                <p class="ps-note">{{ $acct['note'] }}</p>

                <div class="ps-kv">
                  <div class="k">GCash account name</div>
                  <div class="v" data-f="name">{{ $acct['name'] }}</div>
                </div>
                <div class="ps-kv">
                  <div class="k">GCash number</div>
                  <div class="v mono" data-f="number">{{ gcFormatNumber($acct['number']) }}</div>
                </div>

                <div class="ps-foot">
                  @if ($psCanEdit)
                    <button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>
                  @else
                    <span class="rs-lock"><i class="bi bi-lock-fill" aria-hidden="true"></i>Only the office's admin can change this</span>
                  @endif
                </div>
              </div>
@endforeach
            </div>

          </div>
        </div>
      </main>
@endsection

@push('body-end')
    <script>
  /* Values from PHP for assets/js/admin/payment-settings.js and payment-settings-gcash.js */
  var paymentSettingsPage = {
    csrf: @json($rsCsrf),
    rsLockSeconds: {{ (int) $rsLockSeconds }},
    refundSwitchUrl: @json(route('admin.refund-switch')),
    refundsSavedUrl: @json(route('admin.payment-settings', ['refunds' => 'saved'])),
    adminLoginUrl: @json(route('admin.admin-login')),
    gcashAccountSaveUrl: @json(route('admin.gcash-account-save')),
  };
</script>
@if ($rsDbOk && $rsIsAdmin)
<!-- ============================================================
         [5] REFUND MODAL — the warning + password re-entry. Rendered for
         the admin only; staff never get the form.
         ============================================================ -->
    <div class="rs-backdrop" id="rsModal" role="dialog" aria-modal="true" aria-labelledby="rsModalTitle" hidden>
      <div class="rs-modal">
        <div class="rs-modal-head">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <div>
            <h3 id="rsModalTitle"></h3>
            <p>Read this before you confirm.</p>
          </div>
        </div>
        <form id="rsForm" novalidate>
          <div class="rs-modal-body">
            <ul class="rs-list" id="rsModalList"></ul>
            <label class="rs-pw-label" for="rsPassword">Enter your admin password to confirm</label>
            <span class="vz-pw"><input class="ps-control" type="password" id="rsPassword" autocomplete="current-password" /></span>
            <div class="rs-msg" id="rsMsg" role="alert" hidden></div>
          </div>
          <div class="rs-modal-foot">
            <button type="button" class="ps-btn" id="rsCancel">Cancel</button>
            <button type="submit" class="ps-btn ps-btn-primary" id="rsConfirm"></button>
          </div>
        </form>
      </div>
    </div>

    <!-- Open refund requests are counted from the `refunds` table (they were
         in the database; the OFF warning counts them from there. -->

<!-- ============================================================
         [7] REFUND SCRIPT — opens the right warning, posts to
         admin/refund-switch.php, runs the lockout countdown. The server
         decides everything; this only reports what it says.
         ============================================================ -->
<script src="{{ asset_stamped('assets/js/admin/payment-settings.js') }}"></script>
@endif
@endpush

@push('scripts')
<!-- ============================================================
         [6] PAGE SCRIPT — edit in place. Save posts to gcash-account-save.php
         only; nothing persists and no booking page is affected until
         includes/payment-settings.php is backed by a database.
         ============================================================ -->
    <script>
  /* Values from PHP for assets/js/admin/payment-settings-gcash.js */
  /* The checker compares DIGITS, never the pretty form — so a number is
  valid on its digits alone. 11 digits starting 09 is the PH mobile
  format GCash uses; anything else would silently fail every receipt. */
  const PS_CSRF = @json($rsCsrf);
</script>
<script src="{{ asset_stamped('assets/js/admin/payment-settings-gcash.js') }}"></script>
@endpush
