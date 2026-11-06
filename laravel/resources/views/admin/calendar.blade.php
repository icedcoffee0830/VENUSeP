{{-- CALENDAR (was admin/calendar.php) — every booking (calendar_events(), all customers) on FullCalendar. --}}
@extends('layouts.admin')
@section('title', 'Calendar')
@section('active', 'Calendar')
@section('body-class', 'calendar-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/calendar.css') }}" />
@endpush
@section('content')
      <main class="app-main">
        @include('partials.no-halls-note')
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="calendar-heading">
              <div class="calendar-heading-copy">
                <h1>Calendar</h1>
                <p>Schedule and manage events</p>
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
  /* Values from PHP for assets/js/admin/calendar.js */
  var adminCalendarPage = {
    calendarEventsJson: {!! $calendarEventsJson !!},
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/calendar.js') }}"></script>
@endpush
