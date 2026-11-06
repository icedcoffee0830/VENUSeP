@extends('layouts.customer')
{{-- PAY (was customer/pay.php) — one booking's payment step, reached from "Pay now" in Booking History. --}}
@section('title', 'Pay ' . $payB['bookingId'])
@section('active', 'Booking History')
@section('body-class', 'pay-page')
@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
    <!-- Tesseract.js — in-browser OCR for the GCash receipt checker (needs internet) -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
@endpush
@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/pay.css') }}" />
    <!-- [1] PAGE CSS -->
    
@endpush
@section('content')
      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="pay-wrap">
              <nav class="pay-nav" aria-label="Leave this page">
                <a class="pay-back" href="{{ route('customer.booking-history') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My bookings</a>
                <a class="pay-back" href="{{ route('customer.venues') }}"><i class="bi bi-house" aria-hidden="true"></i>Home page</a>
              </nav>
              @fragment('pay-body')
              <div data-live="pay-body" data-pay-code="{{ $payCode }}" data-pay-open="{{ $payOpen ? 1 : 0 }}">
              <h1 class="pay-title">{{ $payOpen ? 'Pay for your booking' : 'Payment' }}</h1>
              <p class="pay-sub">{{ $payB['bookingId'] }} &middot; {{ $payB['roomName'] }}, {{ $payB['venueName'] }}</p>

              @if ($paySent === 'accepted')
                <div class="pay-note is-ok" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>Receipt received and checked</strong>Staff give the final confirmation once they find the payment in GCash.</div></div>
              @elseif ($paySent === 'manual_review')
                <div class="pay-note is-warn" role="status"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><strong>Receipt received — a coordinator will check it</strong>Something did not match automatically, so a person will look at it. Your slot is held while they do; you do not need to resubmit.</div></div>
              @elseif ($paySent === 'limit')
                <div class="pay-note is-bad" role="status"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>That was your last receipt attempt</strong>The receipt could not be accepted automatically, and you have now used all {{ $payAllowed }} attempts. Staff will review your payment and contact you.</div></div>
              @elseif ($paySent === 'cash')
                <div class="pay-note is-ok" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>Noted — you will pay in cash</strong>Pay at {{ $payCash['where'] }} and quote your reference. Staff record it there.{{ $payCashLocked ? ' Changed your mind? Cancel it below to pay by GCash instead.' : '' }}</div></div>
              @elseif ($paySent === 'cash_cancelled')
                <div class="pay-note is-ok" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>Cash payment cancelled</strong>GCash is open again. You can still choose to pay in cash at {{ $payCash['where'] }}.</div></div>
              @endif

              <!-- the booking being paid -->
              <section class="pay-card" aria-label="Booking">
                <div class="pay-grid">
                  <div><div class="pay-k">Reference</div><div class="pay-v">{{ $payB['bookingId'] }}</div></div>
                  <div><div class="pay-k">{{ $payIsHostel ? 'Stay' : 'Event' }}</div><div class="pay-v">{{ $payB['eventName'] }}</div></div>
                  <div><div class="pay-k">{{ $payIsHostel ? 'Check-in' : 'Date' }}</div><div class="pay-v">{{ $payB['eventDate'] }}</div></div>
                  <div><div class="pay-k">Amount due</div><div class="pay-v">{{ $payB['amount'] }}</div>@if ($payB['discountPending'])<div style="font-size:12px;color:#6b675f;margin-top:3px">USeP discount applied once your ID is verified</div>@endif</div>
                  <div><div class="pay-k">Payment</div><div class="pay-v">{{ $payB['paymentStatus'] }}</div></div>
                  @if ($payOpen || in_array($payCode, ['await_event', 'overdue'], true))
                    <div><div class="pay-k">{{ $payCode === 'overdue' ? 'Was due' : 'Pay by' }}</div><div class="pay-v">{{ customer_pay_by_label($payB['payment']) }}</div></div>
                  @endif
                </div>
              </section>

              @if ($payCode === 'under_review')
                <!-- a receipt is in; staff have it -->
                <div class="pay-note is-warn"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><strong>Your receipt is with staff</strong>They match the reference number in the business GCash history, then confirm. You cannot upload another receipt while this one is being reviewed.@if ($payUsed >= $payAllowed) This was your last allowed attempt ({{ $payUsed }} of {{ $payAllowed }}).@endif</div></div>
                @if ($payReceipt)
                  <section class="pay-card" aria-label="Receipt under review">
                    <div class="pay-grid">
                      <div><div class="pay-k">Reference no.</div><div class="pay-v">{{ $payReceipt['reference_number'] }}</div></div>
                      <div><div class="pay-k">Amount read</div><div class="pay-v">{{ $payReceipt['amount_centavos'] !== null ? '₱' . number_format(((int) $payReceipt['amount_centavos']) / 100, 2) : '—' }}</div></div>
                      <div><div class="pay-k">Sent to</div><div class="pay-v">{{ $payReceipt['receiver_number'] ?: '—' }}</div></div>
                    </div>
                  </section>
                @endif

              @elseif (!$payOpen)
                @php [$payNoteHead, $payNoteText] = $payStateNote; @endphp
                <div class="pay-note {{ in_array($payCode, ['confirmed', 'paid_cash'], true) ? 'is-ok' : (in_array($payCode, ['expired', 'overdue'], true) ? 'is-bad' : 'is-info') }}">
                  <i class="bi {{ in_array($payCode, ['confirmed', 'paid_cash'], true) ? 'bi-check-circle' : 'bi-info-circle' }}" aria-hidden="true"></i>
                  <div><strong>{{ $payNoteHead }}</strong>{{ $payNoteText }}</div>
                </div>

              @else
                @if (!empty($payB['payToUse']))
                  <!-- the hostel is pay to use (2026-10-27, DB-DECISIONS #26) -->
                  <div class="pay-note is-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div><strong>Pay before you check in</strong>Pay here by GCash, or in cash at the front desk when you arrive. If it is not paid by the end of {{ $payB['payment']['payByLabel'] }}, the beds are released. You get your bed once the stay is paid.</div></div>
                @endif
                <!-- [4b] the payment itself — drawn by the script below -->
                <div id="payApp"></div>
              @endif
              </div>
              @endfragment
            </div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    <script>
  /* Values from PHP for assets/js/customer/pay-live.js */
  var payPage = {
    paymentSubmitUrl: @json(route('customer.payment-submit')),
    payUrl: @json(route('customer.pay')),
    faqUrl: @json(route('customer.faq')),
  };
</script>
<script src="{{ asset_stamped('assets/js/customer/pay-live.js') }}"></script>
    @if ($payOpen)
    <!-- [5] GCASH CHECKER — the shared engine (OCR + verdict). It calls back into
         gcAccount(), gcExpectedCentavos(), gcBookingRef(), state and render(). -->
    @php include base_path('legacy/includes/gcash-checker.php'); @endphp

    <!-- [6] SCRIPT -->
    <script>
  /* Values from PHP for assets/js/customer/pay.js */
  const PAY = {!! json_encode([
          'ref'       => $payB['bookingId'],
          'centavos'  => (int) round(((float) $payB['amountValue']) * 100),
          'amount'    => $payB['amount'],
          'account'   => $payAccount,                       /* null = no GCash for this venue */
          'method'    => $payCode === 'await_cash' ? 'cash' : 'gcash',
          'cashChosen'=> $payCode === 'await_cash',
          'cashLocked'=> $payCashLocked,                        /* cash chosen -> GCash closed until cancelled */
          'cash'      => $payCash,
          'hostel'    => $payIsHostel,
          'used'      => $payUsed,                          /* GCash receipt uploads so far (5-attempt rule) */
          'allowed'   => $payAllowed,
          'prior'     => $payPrior,                         /* earlier uploads on this booking: no, sha, ref, outcome */
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  const CSRF = @json(csrf_token());
</script>
<script src="{{ asset_stamped('assets/js/customer/pay.js') }}"></script>
    @endif
@endpush
