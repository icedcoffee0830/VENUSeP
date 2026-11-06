{{-- BOOKING REQUEST DETAIL (was admin/booking-request.php). The whole view is drawn by the script from DATA (every booking, keyed by reference); ?id= picks one. --}}
@extends('layouts.admin')
@section('title', 'Booking Request Detail')
@section('active', 'Booking Management')
@push('head')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
@endpush
@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/booking-request.css') }}" />
@endpush
@section('content')
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="br-page" id="brqRoot"></div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    {!! $pricingJs !!}
    <!-- Tesseract + the shared GCash engine. The SAME checker the customer uses
         to prove they paid in; here it proves staff paid out. It needs no changes
         for that — it asks the page whose account the money must land in, and
         this page answers "the customer's" (see gcAccount below). -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    {!! $gcashCheckerJs !!}
    @fragment('brq-row')<script type="application/json" data-live="brq-row">{!! json_encode($brqData[request('id')] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endfragment
    <script>
  /* Values from PHP for assets/js/admin/booking-request.js */
  /* Badges for EVERY status the database defines — status_badge_maps() in
  includes/bookings.php reads the lookup tables. These were hand-written
  here and covered three reservation codes out of seven: opening a
  completed, cancelled, rejected or disrupted booking threw on the
  undefined lookup below and left staff a blank page.
  
  Two states worth naming: `await_pos` is HOSTEL ONLY and is the one
  waiting state that waits on another OFFICE rather than the customer,
  and `refund_correction` is NOT a denial — it is a paperwork problem
  handed back with a window to fix it (agreed 2026-09-09: denial is
  final, so a blurry receipt must never be one). */
  const RES = {!! json_encode(status_badge_maps()['res'], JSON_UNESCAPED_UNICODE) !!};
  /* Booking Request detail — one full window per request (mockup).
  Reads ?id=BRQ-xxxx; actions update badges + timeline in-page (demo only).
  Statuses follow the agreed payment-status taxonomy. */
  const PAY = {!! json_encode(status_badge_maps()['pay'], JSON_UNESCAPED_UNICODE) !!};
  {!! payment_policy_js() !!}
  /* EVERY request, from the database, keyed by its real reference. */
  const DATA = {!! json_encode($brqData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  /* PAYMENT TIMING follows the refund switch — the shared rule from
  includes/refund-policy.php (same as every customer page). Rows carry
  startIso/endIso so approve() can compute the pay-by for either policy. */
  const CSRF = {!! json_encode(csrf_token()) !!};
  const IS_ADMIN = {!! json_encode(session('account_type') === 'admin') !!};
  /* The list this booking was opened from (the queue saves it on the row's click): the back
  link names its tab, the page says where you are, and Next opens the following one.
  Opened any other way (a bell, a link): the plain back link, and no Next. */
  const QUEUE_URL = {!! json_encode(route('admin.booking-requests')) !!};
  /* REJECT A RECEIPT (Aron, 2026-10-30): staff pick why from a short list,
  plus a note — required for "Other". The customer reads the result, in
  the same words the server stores (reason — note). */
  const REJECT_REASONS = {!! json_encode(receipt_reject_reasons(), JSON_UNESCAPED_UNICODE) !!};
  var bookingRequestPage = {
    validIdViewUrl: @json(route('root.valid-id-view')),
    documentViewUrl: @json(route('root.document-view')),
    receiptPdfUrl: @json(route('root.receipt-pdf')),
    bookingRequestsUrl: @json(route('admin.booking-requests')),
    hostelPaidCashLabel: {!! json_encode(HOSTEL_PAID_CASH_LABEL) !!},
    bookingActionUrl: {!! json_encode(route('admin.booking-action')) !!},
    bookingEmailsLookupUrl: {!! json_encode(route('admin.booking-emails') . '?booking=') !!},
    bookingEmailsUrl: {!! json_encode(route('admin.booking-emails')) !!},
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/booking-request.js') }}"></script>
@endpush
