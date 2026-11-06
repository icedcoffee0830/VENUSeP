{{-- CUSTOMER CALENDAR (was customer/calendar.php) — same UI as the admin calendar,
     showing THIS customer's own bookings. Read-only. Data: Customer\CalendarController. --}}
@extends('layouts.customer')
@section('title', 'My Calendar')
@section('active', 'Calendar')
@section('body-class', 'calendar-page')

@push('styles')
    {{-- the lines of this page's old [0] SHELL block that differ from the customer shell --}}
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/calendar-shell.css') }}" />

    <!-- ============================================================
         [1] PAGE CSS — the CALENDAR DESIGN, verbatim from the mockup.
         Aron likes this look — do not change it.
         ============================================================ -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/calendar.css') }}" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/calendar.css') }}" />
@endpush

@section('content')
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="calendar-heading">
              <div class="calendar-heading-copy">
                <h1>My Calendar</h1>
                <p>Your venue bookings at a glance</p>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="calendar-shell">
              <div id="calendar"></div>
            </div>
          </div>
        </div>
      </main>
@endsection

@push('scripts')
    <!-- FullCalendar library (the only third-party script this page needs) -->
    <script
      src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"
      crossorigin="anonymous"
    ></script>

    <!-- ==========================================================
         [6] PAGE SCRIPT — builds the FullCalendar month view.
         ========================================================== -->
    <script>
  /* Values from PHP for assets/js/customer/calendar.js */
  var customerCalendarPage = {
    calendarEventsJson: {!! $calendarEventsJson !!},
  };
</script>
<script src="{{ asset_stamped('assets/js/customer/calendar.js') }}"></script>
@endpush
