@extends('layouts.customer')
{{-- REFUND REQUEST (was customer/refund-request.php) — the customer's only entry into the refund chain. --}}
@section('title', 'Request a Refund')
{{-- $active stays on Booking History: this page is a step inside that flow, not a menu item of its own. --}}
@section('active', 'Booking History')
@section('body-class', 'refund-request-page')
@push('styles')
    <!-- [1] PAGE CSS — same palette as booking-history.php -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/refund-request.css') }}" />
@endpush
@section('content')
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="rr-page">
              <a class="rr-back" href="{{ route('customer.booking-history') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Booking History</a>

              @if ($refusal !== null)
                <!-- [4a] REFUSAL — bad or absent reference, or a booking that is
                     not eligible. Same check the history page used to decide
                     whether to offer the link, re-run here because ?booking= can
                     be typed by hand. -->
                <h1>Request a refund</h1>
                <p class="rr-lede">This booking cannot be refunded through the online form.</p>
                <div class="rr-panel rr-panel-bad">
                  <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                  <h2>Refund not available</h2>
                  <p>{{ $refusal }}</p>
                  <a class="rr-btn" href="{{ route('customer.booking-history') }}">Back to Booking History</a>
                </div>

              @else
                <h1>Request a refund</h1>
                <p class="rr-lede">Tell us why you are requesting a refund and attach the required receipts. USeP staff review every request and make the final decision &mdash; submitting this form does not guarantee a refund.</p>

                <p class="rr-error" id="rr-error" role="alert"></p>
                <!-- Shown when staff sent the request back. Not a denial: the
                     claim is untouched, only the paperwork needs fixing. -->
                <div class="rr-warn" id="rr-fix" hidden style="margin-bottom:18px">
                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                  <p><strong>Staff sent this back for correction.</strong> <span id="rr-fix-note"></span> Fix it and submit again &mdash; your booking is unaffected and your place in the queue is kept.</p>
                </div>

                <form id="rr-form" novalidate>
                  <div class="rr-grid">
                    <!-- [4b] THE FORM -->
                    <div class="rr-card">
                      <div class="rr-card-head">
                        <h2>Your refund request</h2>
                        <p>Booking {{ $booking['bookingId'] }} &middot; {{ $booking['eventName'] }}</p>
                      </div>
                      <div class="rr-card-body">
                        <div class="rr-cols">
                          <div class="rr-col">

                          <div class="rr-section">
                            <label class="rr-label rr-req" for="rr-reason">Reason for the refund</label>
                            <select class="rr-control" id="rr-reason" name="reason">
                              <option value="">Select a reason&hellip;</option>
                              @foreach ($refundReasons as $rrKey => $reason)
                                <option value="{{ $rrKey }}">{{ $reason }}</option>
                              @endforeach
                            </select>
                          </div>
  
                          <div class="rr-section">
                            <label class="rr-label rr-req" for="rr-details">Tell us what happened</label>
                            <textarea class="rr-control" id="rr-details" name="details" placeholder="Explain briefly why you are requesting this refund. Staff read this before deciding."></textarea>
                            <p class="rr-hint" id="rr-count">At least 20 characters.</p>
                          </div>

                          @if ($booking['method'] === 'GCash')
                            <!-- WHERE THE MONEY GOES. Asked HERE, at refund time, on
                                 purpose: staff cannot read a full sender number out of
                                 GCash (incoming senders are masked), so without this
                                 the system genuinely does not know where to pay. The
                                 fraud risk of letting a customer nominate a destination
                                 is handled where it actually lives — every refund is
                                 reviewed by a staff member who holds this customer's
                                 verified ID, and the admin panel flags it loudly when
                                 this differs from the number on file.
                                 Cash bookings never see this: a cash refund is handed
                                 over at the counter (same channel back). -->
                            <div class="rr-section">
                              <label class="rr-label rr-req" for="rr-gcash">GCash number to refund to</label>
                              <input class="rr-control" id="rr-gcash" type="tel" inputmode="numeric"
                                     autocomplete="tel" value="{{ $customerContact['phone'] }}"
                                     placeholder="09XX XXX XXXX" />
                              <p class="rr-hint">This must be the GCash account you <strong>paid from</strong>. We have filled in your registered number &mdash; change it only if you paid from a different account.</p>
                            </div>
                          @endif
                          </div>
                          <div class="rr-col">
                          <div class="rr-section">
                            <span class="rr-label">Documents</span>
                            <div class="rr-docs">
                              @foreach ($requiredDocs as $doc)
                                <div class="rr-doc" data-filled="0" data-pending="0" data-optional="{{ $doc['optional'] ? '1' : '0' }}" data-label="{{ $doc['label'] }}">
                                  <i class="bi bi-paperclip" aria-hidden="true"></i>
                                  <div class="rr-doc-txt">
                                    <b>{{ $doc['label'] }}{{ $doc['optional'] ? '' : ' *' }}</b>
                                    <small>{{ $doc['hint'] }}</small>
                                    @if ($doc['optional'])
                                      <label class="rr-later">
                                        <input type="checkbox" class="rr-nohave" id="rr-nohave-{{ $doc['key'] }}" />
                                        <span>I do not have this yet &mdash; I understand my refund cannot be paid until I provide it.</span>
                                      </label>
                                    @endif
                                  </div>
                                  <input type="file" class="rr-file" accept="image/*,application/pdf"
                                         data-key="{{ $doc['key'] }}"
                                         aria-label="{{ $doc['label'] }}" />
                                </div>
                              @endforeach
                            </div>
                            <p class="rr-hint">Items marked * are needed before your request can be filed. The Official Receipt is needed before the refund can be <strong>paid</strong> &mdash; send it later if you do not have it yet. Photos or PDFs, up to 7 MB each.</p>
                          </div>
  
                          <div class="rr-section">
                            <div class="rr-warn rr-warn-calm">
                              <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                              <p><strong>Your booking is not cancelled by this request.</strong> It stays yours the whole time staff are reviewing, and you can <strong>withdraw the request at any point</strong> before they decide. If the refund is <strong>denied</strong>, your booking is untouched. If it is <strong>approved</strong>, the booking closes and the date is released only once your refund has actually been paid.</p>
                            </div>
                          </div>
  
                          <div class="rr-section">
                            <label class="rr-check">
                              <input type="checkbox" id="rr-confirm" />
                              <span>I understand that USeP staff make the final decision, that a denied request is final and I would need to book again, and that my refund cannot be paid until the Official Receipt has been provided.</span>
                            </label>
                          </div>

                          </div>
                        </div>
                      </div>
                      <div class="rr-actions">
                        <a class="rr-btn" href="{{ route('customer.booking-history') }}">Never mind</a>
                        <button type="submit" class="rr-btn rr-btn-primary">Submit refund request</button>
                      </div>
                    </div>

                    <!-- [4c] BOOKING SUMMARY — read-only. The amount is shown, never
                         typed: a customer-entered figure would be a partial refund
                         request, which is a decision nobody has made yet. -->
                    <aside class="rr-card rr-aside">
                      <div class="rr-card-head"><h2>Booking being refunded</h2></div>
                      <div class="rr-card-body">
                        <div class="rr-kv"><span>Reference</span><strong>{{ $booking['bookingId'] }}</strong></div>
                        <div class="rr-kv"><span>Venue</span><strong>{{ $booking['venue'] }}</strong></div>
                        <div class="rr-kv"><span>Event</span><strong>{{ $booking['eventName'] }}</strong></div>
                        <div class="rr-kv"><span>Event date</span><strong>{{ $booking['eventDate'] }}</strong></div>
                        <div class="rr-kv"><span>Booked on</span><strong>{{ $booking['bookingDate'] }}</strong></div>
                        <div class="rr-kv"><span>Paid by</span><strong>{{ $booking['method'] }}</strong></div>
                        <div class="rr-total">
                          <span>Amount to be refunded</span>
                          <b>{{ $booking['amount'] }}</b>
                          <small>The full amount paid. Staff confirm the final figure when they review your request.</small>
                        </div>
                      </div>
                    </aside>
                  </div>
                </form>

                <!-- [4d] CONFIRMATION — swapped in on submit. -->
                <div class="rr-panel rr-panel-good" id="rr-done" hidden>
                  <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                  <h2>Refund request submitted</h2>
                  <p>Your request for booking {{ $booking['bookingId'] }} is now with USeP staff. <strong>Your booking has not been cancelled</strong> &mdash; it stays yours while the request is reviewed, and you can withdraw it from Booking History at any point before a decision is made.</p>
                  <p id="rr-done-or" hidden>You still owe the <strong>Official Receipt</strong>. Staff cannot pay the refund until they have it, so send it as soon as you can.</p>
                  <a class="rr-btn rr-btn-primary" href="{{ route('customer.booking-history') }}">Back to Booking History</a>
                </div>

                <!-- [4e] ALREADY OPEN — a customer who has filed can still reach
                     this URL by typing it or using the back button. Filing twice
                     would create a duplicate request. The schema now refuses a
                     second OPEN request per booking (refunds.open_booking_id,
                     2026-09-16), but this page still has to say so politely.
                     Withdrawing from Booking History clears this. -->
                <div class="rr-panel rr-panel-info" id="rr-already" hidden>
                  <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                  <h2>You already have a refund request open</h2>
                  <p>A refund request for booking {{ $booking['bookingId'] }} is already with USeP staff, so you cannot file a second one. If you want to change or cancel it, withdraw the existing request from your Booking History.</p>
                  <a class="rr-btn rr-btn-primary" href="{{ route('customer.booking-history') }}">Go to Booking History</a>
                </div>
              @endif

                <!-- [4f] CLOSED — staff already decided. A denial is final and a
                     completed refund is done, so neither can be filed against
                     again. Shown instead of the form. -->
                <div class="rr-panel rr-panel-info" id="rr-closed" hidden>
                  <i class="bi bi-archive-fill" aria-hidden="true"></i>
                  <h2 id="rr-closed-title">This refund request is closed</h2>
                  <p id="rr-closed-body"></p>
                  <!-- Proof of payout, shown only when the refund actually completed.
                       The receipt IMAGE is not carried in browser storage — that is
                       the database's job; this tile stands in for it, the same way
                       the ID image is a placeholder on the admin side. -->
                  <div id="rr-proof" hidden>
                    <div style="text-align:left;max-width:420px;margin:0 auto 16px;padding:13px 14px;background:#faf9f7;border:1px solid #f0ece4;border-radius:10px">
                      <div class="rr-kv"><span>GCash reference</span><strong id="rr-proof-ref">&mdash;</strong></div>
                      <div class="rr-kv"><span>Amount refunded</span><strong id="rr-proof-amt">&mdash;</strong></div>
                      <div class="rr-kv"><span>Sent to</span><strong id="rr-proof-to">&mdash;</strong></div>
                    </div>
                    <div id="rr-proof-tile" style="max-width:420px;margin:0 auto 18px;padding:30px 14px;border:1px dashed #d7d2c8;border-radius:10px;background:#fbfaf8;color:#6e6a64;font-size:12px">GCash refund receipt &mdash; image</div>
                  </div>
                  <a class="rr-btn rr-btn-primary" href="{{ route('customer.booking-history') }}">Back to Booking History</a>
                </div>
            </div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    <!-- The refund's CURRENT state, from the `refunds` table. This used to come
         from includes/refund-store.php — browser storage shared between the two
         mockups, which meant a request filed here was invisible to staff on any
         other machine. It is a row now, so the server simply knows. -->
    <script>
  /* Values from PHP for assets/js/customer/refund-request.js */
  const CSRF        = @json(csrf_token());
  const RR_EXISTING = {!! json_encode($rrExisting, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
  const RR_RESUBMIT = {{ $rrResubmit ? 'true' : 'false' }};
  var refundRequestPage = {
    refundSubmitUrl: @json(route('customer.refund-submit')),
  };
</script>
<script src="{{ asset_stamped('assets/js/customer/refund-request.js') }}"></script>
@endpush
