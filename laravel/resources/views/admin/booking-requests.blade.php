{{-- BOOKING REQUESTS — the staff queue (was admin/booking-requests.php). Rows come from booking_queue_rows(). --}}
@extends('layouts.admin')
@section('title', 'Booking Requests')
@section('active', 'Booking Management')
@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/booking-requests.css') }}" />
@endpush
@section('content')
      <main class="app-main">
        @include('partials.no-halls-note')
        <div class="app-content">
          <div class="container-fluid">
            <div class="br-page">
              <h1 class="br-title">Booking Requests</h1>
              <p class="br-subtitle" id="brCount"></p>

              <div class="br-toolbar" id="brTabs">
                <span id="brTabBtns" style="display:contents"></span>
                <input id="brSearch" class="br-search" type="text" placeholder="Search name, room, or request no.…" aria-label="Search bookings" autocomplete="off" oninput="setQ(this.value)">
              </div>

              <div class="br-card br-list">
                <div class="br-foot" id="brFoot" hidden>
                  <label class="br-pagesize">Page Size
                    <select id="brPageSize">
                      <option value="10" selected>10</option>
                      <option value="25">25</option>
                      <option value="50">50</option>
                      <option value="100">100</option>
                    </select>
                  </label>
                  <span class="br-shown" id="brShown"></span>
                  <div class="br-pages" id="brPages"></div>
                </div>
                <div class="br-hd">
                  <span>Request · Customer</span>
                  <span class="hide-md">Room</span>
                  <span class="hide-md">Event date(s)</span>
                  <span class="hide-md">Reservation</span>
                  <span>Payment</span>
                  <span class="hide-md">Next action</span>
                  <span></span>
                </div>
                <div id="brRows"></div>
              </div>
            </div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    @fragment('br-rows')<script type="application/json" data-live="br-rows">{!! json_encode($brRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endfragment
    <script>
  /* Values from PHP for assets/js/admin/booking-requests.js */
  var bookingRequestsPage = {
    bookingRequestUrl: @json(route('admin.booking-request')),
    reservationBadges: {!! json_encode(status_badge_maps()['res'], JSON_UNESCAPED_UNICODE) !!},
    paymentBadges: {!! json_encode(status_badge_maps()['pay'], JSON_UNESCAPED_UNICODE) !!},
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/booking-requests.js') }}"></script>
@endpush
