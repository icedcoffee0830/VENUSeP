{{-- CALENDAR (was admin/calendar.php) — every booking (calendar_events(), all customers) on FullCalendar. --}}
@extends('layouts.admin')
@section('title', 'Calendar')
@section('active', 'Calendar')
@section('body-class', 'calendar-page')
@push('styles')
    <style>
@include('partials.calendar-css')
    </style>
@endpush
@section('content')
      <main class="app-main">
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
      document.addEventListener('DOMContentLoaded', () => {
        const calendarEl = document.getElementById('calendar');

        const calendar = new FullCalendar.Calendar(calendarEl, {
          // 1) Calendar start view/date
          initialView: 'dayGridMonth',
          /* No initialDate: the calendar opens on the CURRENT month. It was
             pinned to '2026-02-01' by the mockup, so staff arrived in a month
             that was months behind whatever they were working on. */

          // 2) Calendar toolbar buttons
          headerToolbar: {
            start: 'prev,next title',
            center: '',
            end: 'today,dayGridMonth,timeGridWeek,timeGridDay,listWeek',
          },

          // 3) Button labels
          buttonText: {
            today: 'Today',
            month: 'Month',
            week: 'Week',
            day: 'Day',
            list: 'List',
          },

          // 4) Calendar behavior
          fixedWeekCount: true,
          height: 'auto',
          editable: false,
          dayMaxEvents: 2,

          /* READ-ONLY, same as the customer calendar. Every event here is a
             real booking, so dragging one moved a booking's dates on screen
             and nowhere else — the calendar disagreed with the queue, the
             ledger and the customer's own copy until the next refresh put it
             back. The mockup's prompt()-to-add and confirm()-to-delete went
             with it for the same reason: neither wrote anything, and a
             "deleted" booking that reappears on reload is worse than no
             delete at all. Bookings are changed in Booking Management, where
             the change is recorded and the customer is told. */

          /* Every booking in the system, from includes/bookings.php — the same
             rows the queue and the customer calendars read. This array was
             still AdminLTE's stock demo ("Team Standup", "Sprint Review",
             "Client Call - Acme Corp"): the admin calendar had never shown a
             VENUSeP booking at all. Colour carries the reservation status;
             multi-day bookings span their days. */
          events: {!! $calendarEventsJson !!},
        });

        calendar.render();
      });
    </script>
@endpush
