@extends('layouts.customer')
{{-- BOOKING HISTORY (was customer/booking-history.php). Bookings come from legacy customer-bookings.php, scoped to the session customer. --}}
@section('title', 'Booking History')
@section('active', 'Booking History')
@section('body-class', 'booking-history-page')
@push('head')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
@endpush
@push('styles')
    <!-- [1] PAGE CSS — booking history, self-contained in the team palette -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/booking-history.css') }}" />
@endpush
@section('content')
      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="booking-history-container">
              <header class="booking-history-header">
                <h1>Booking History</h1>
                <p>Review your previous venue reservations and their final status.</p>
              </header>

              <nav class="booking-status-tabs" aria-label="Filter bookings by status" role="tablist">
                <button class="booking-status-tab active" type="button" role="tab" aria-selected="true" data-booking-status="">All</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="due">Due payments</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="Approved">Approved</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="Completed">Completed</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="Rejected">Rejected</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="Cancelled">Cancelled</button>
              </nav>

              <section class="booking-history-filters" aria-label="Booking history filters">
                <div class="booking-filter-field">
                  <label for="booking-history-search">Search</label>
                  <input id="booking-history-search" class="form-control" type="search" placeholder="Booking ID, venue, or event...">
                </div>
                <div class="booking-filter-field">
                  <label for="booking-history-venue">Venue</label>
                  <select id="booking-history-venue" class="form-select">
                    <option value="">All Venues</option>
                    @foreach ($bookingHistoryVenues as $venue)
                      <option value="{{ $venue }}">{{ $venue }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="booking-filter-field">
                  <label for="booking-history-date-from">Date From</label>
                  <input id="booking-history-date-from" class="form-control" type="date">
                </div>
                <div class="booking-filter-field">
                  <label for="booking-history-date-to">Date To</label>
                  <input id="booking-history-date-to" class="form-control" type="date">
                </div>
                <button id="booking-history-reset" class="booking-toolbar-button booking-reset-button" type="button">
                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reset
                </button>
              </section>

              <section class="booking-history-panel" aria-labelledby="previousBookingsTitle">
                <div class="booking-history-panel-header">
                  <h2 id="previousBookingsTitle">Previous Bookings</h2>
                </div>
                <div class="booking-table-footer">
                  <label class="booking-entries-control" for="booking-history-entries">
                    Show
                    <select id="booking-history-entries" class="booking-entries-select">
                      <option value="10" selected>10</option>
                      <option value="25">25</option>
                      <option value="50">50</option>
                    </select>
                    entries
                  </label>
                  <p id="booking-history-info" class="booking-table-info" aria-live="polite"></p>
                  <nav id="booking-history-pagination" class="booking-pagination" aria-label="Booking history pages"></nav>
                </div>
                <div class="booking-table-scroll">
                  <table id="bookingHistoryTable" class="booking-history-table">
                    <thead>
                      <tr>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="booking-id">Booking ID</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="venue">Venue</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="event-name">Event Name</button></th>
                        <th aria-sort="descending"><button class="sortable-heading" type="button" data-sort="event-date" data-direction="desc">Event Date</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="booking-date">Booking Date</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="amount">Amount</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="payment-status">Payment Status</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="status">Booking Status</button></th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    @fragment('bh-rows')
                    <tbody data-live="bh-rows">
                      @foreach ($bookingHistory as $booking)
                        <tr
                          data-booking-row
                          data-refund-status="{{ $booking['refundStatus'] }}"
                          data-booking-id="{{ $booking['bookingId'] }}"
                          data-venue="{{ $booking['venue'] }}"
                          data-event-name="{{ $booking['eventName'] }}"
                          data-event-date="{{ $booking['eventDateIso'] }}"
                          data-booking-date="{{ $booking['bookingDateIso'] }}"
                          data-amount="{{ $booking['amountValue'] }}"
                          data-payment-status="{{ $booking['paymentStatus'] }}"
                          data-status="{{ $booking['bookingStatus'] }}"
                          data-due="{{ $booking['reservationCode'] !== \App\Support\Status::CANCELLED && in_array($booking['paymentCode'], \App\Support\Status::PAYMENT_DUE, true) ? '1' : '' }}"
                        >
                          <td class="booking-id" data-label="Booking"><strong>{{ $booking['bookingId'] }}</strong></td>
                          <td data-label="Venue">{{ $booking['venue'] }}</td>
                          <td data-label="Event">{{ $booking['eventName'] }}</td>
                          <td data-label="Event date">{{ $booking['eventDate'] }}</td>
                          <td data-label="Booked on">{{ $booking['bookingDate'] }}</td>
                          <td class="booking-amount" data-label="Amount">{{ $booking['amount'] }}@if ($booking['discountPending'])<span class="booking-amount-note">USeP discount applied once your ID is verified</span>@endif</td>
                          <td data-label="Payment">
                            <span class="booking-badge {{ bh_badge($booking['paymentStatus']) }}">{{ $booking['paymentStatus'] }}</span>
                            {{-- post-pay bookings say WHEN — the date comes from the shared rule (legacy refund-policy.php) --}}
                            @if ($booking['paymentStatus'] === 'Payment pending')
                              <span class="booking-pay-note">opens {{ $booking['payment']['opensLabel'] }}</span>
                            @elseif ($booking['paymentStatus'] === 'Payment due')
                              <span class="booking-pay-note">pay by {{ customer_pay_by_label($booking['payment']) }}</span>
                            @elseif ($booking['paymentStatus'] === 'Overdue')
                              <span class="booking-pay-note is-overdue">was due {{ customer_pay_by_label($booking['payment']) }}</span>
                            @endif
                          </td>
                          <td data-label="Status"><span class="booking-badge {{ bh_badge($booking['bookingStatus']) }}">{{ $booking['bookingStatus'] }}</span>
                            @if (!empty($booking['expiryNote']))<span class="booking-status-note">{{ $booking['expiryNote'] }}</span>@endif</td>
                          <td data-label="Actions" class="booking-actions-cell">
                            <div class="booking-actions">
                              {{-- Payment is open (or a receipt is with staff): the way back to
                                   the payment step, customer.pay. Overdue: closed until staff extend
                                   the deadline (that puts it back to await_*). --}}
                              @if (in_array($booking['paymentCode'], \App\Support\Status::PAY_OPEN, true))
                                <a class="booking-action booking-action-pay" href="{{ route('customer.pay', ['booking' => $booking['bookingId']]) }}">
                                  <i class="bi bi-wallet2" aria-hidden="true"></i>Pay now
                                </a>
                              @elseif ($booking['paymentCode'] === 'under_review')
                                <a class="booking-action" href="{{ route('customer.pay', ['booking' => $booking['bookingId']]) }}">
                                  <i class="bi bi-hourglass-split" aria-hidden="true"></i>Payment
                                </a>
                              @elseif ($booking['paymentCode'] === 'overdue')
                                <p class="booking-overdue-help"><strong>Payment overdue. Please contact the office.</strong>@isset($bhOverdue[$booking['id']])<br>{{ $bhOverdue[$booking['id']] }}@endisset</p>
                              @endif
                              <!-- the booking and its payment, in a modal over this page — same as
                                   Transaction History's View (includes/booking-details-modal.php) -->
                              <button type="button" class="booking-action" onclick="openBookingDetails('{{ rawurlencode($booking['bookingId']) }}')"><i class="bi bi-eye" aria-hidden="true"></i>View</button>
                              @if (isset($bhReceipts[(int) ($booking['id'] ?? 0)]))
                                <a class="booking-action booking-action-receipt" href="{{ route('customer.receipt', ['booking' => $booking['bookingId']]) }}">
                                  <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Receipt
                                </a>
                              @endif
                              @if ($booking['reservationCode'] === 'pending')
                                <button type="button" class="booking-action" onclick="bookingCancel('{{ $booking['bookingId'] }}')"><i class="bi bi-x-circle" aria-hidden="true"></i>Cancel request</button>
                              @elseif ($booking['reservationCode'] === 'rejected' && $booking['eventDateIso'] > date('Y-m-d'))
                                <label class="booking-action" style="cursor:pointer"><i class="bi bi-upload" aria-hidden="true"></i>Send a new ID<input type="file" hidden accept="image/*,application/pdf" onchange="bookingFix('{{ $booking['bookingId'] }}', this)"></label>
                              @endif
                              {{-- also on a row whose refund is open or returned: the script
                                   below turns it into "Withdraw request" / "Fix and resubmit" --}}
                              @if ($booking['refundable'] || in_array($booking['refundStatus'], ['requested', 'under_review', 'returned_for_correction'], true))
                                <a class="booking-action booking-action-refund" href="{{ route('customer.refund-request', ['booking' => $booking['bookingId']]) }}">
                                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Request Refund
                                </a>
                              @endif
                            </div>
                          </td>
                        </tr>
                      @endforeach
                      <tr id="booking-history-empty" class="booking-empty-state" hidden>
                        <td colspan="9">{{ $emptyBookingMessage }}</td>
                      </tr>
                    </tbody>
                    @endfragment
                  </table>
                </div>
              </section>
            </div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
    <script>
  /* Values from PHP for assets/js/customer/booking-history.js */
  var bookingHistoryPage = {
    bookingCancelUrl: {!! json_encode(route('customer.booking-cancel')) !!},
    bookingFixUrl: {!! json_encode(route('customer.booking-fix')) !!},
    refundSubmitUrl: @json(route('customer.refund-submit')),
    refundRequestUrl: @json(route('customer.refund-request')),
  };
</script>
<script src="{{ asset_stamped('assets/js/customer/booking-history.js') }}"></script>
    @php include base_path('legacy/includes/booking-details-modal.php'); /* "View" */ @endphp
@endpush
