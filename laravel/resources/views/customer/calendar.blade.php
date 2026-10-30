{{-- CUSTOMER CALENDAR (was customer/calendar.php) — same UI as the admin calendar,
     showing THIS customer's own bookings. Read-only. Data: Customer\CalendarController. --}}
@extends('layouts.customer')
@section('title', 'My Calendar')
@section('active', 'Calendar')
@section('body-class', 'calendar-page')

@push('styles')
    {{-- the lines of this page's old [0] SHELL block that differ from the customer shell --}}
    <style>
      :root {
        --venusep-black: #1f1e1e;
        --venusep-cream: #ffffff;
        --venusep-border: #ddd7ce;
        --venusep-text: #050505;
      }
      body {
        color: var(--venusep-text);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      }
      .app-content-header { padding: 1.3rem 0 0; }
      @media (max-width: 767.98px) {
        .user-name { display: none; }
      }
    </style>

    <!-- ============================================================
         [1] PAGE CSS — the CALENDAR DESIGN, verbatim from the mockup.
         Aron likes this look — do not change it.
         ============================================================ -->
    <style>
@include('partials.calendar-css')
    
      /* on the crimson page background (painted by includes/header.php): light text, a card that floats */
      .calendar-heading h1 { color: #fff; }
      .calendar-heading p { color: #e9d0cd; }
      .calendar-shell { box-shadow: 0 18px 44px rgba(10,4,5,.28), 0 2px 6px rgba(10,4,5,.18); border-color: rgba(255,255,255,.18); }
    
      /* the calendar owns its colours through these variables (see "Quick Edit Values" above):
         the page behind the card takes the crimson gradient; today + the active view button go crimson */
      :root {
        --calendar-page-bg: linear-gradient(162deg, #7d1120 0%, #3c0c14 40%, #120809 100%) fixed;
        --calendar-today-bg: #a11626;
        --calendar-today-text: #ffffff;
        --calendar-button-active-bg: #a11626;
        --calendar-button-active-text: #ffffff;
      }
      /* phone: the toolbar stays put and wraps; only the grid scrolls sideways, at a width that
         shows all 7 days (Sun-Sat), so nothing is cut off at the card's edge */
      @media (max-width: 767.98px) {
        #calendar .fc-toolbar.fc-header-toolbar { flex-wrap: wrap; row-gap: 0.65rem; }
        #calendar .fc-toolbar-chunk { min-width: 0; }
        #calendar .fc-toolbar-chunk:last-child { width: 100%; }
        #calendar .fc-view-harness { overflow-x: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
        #calendar .fc-daygrid, #calendar .fc-timegrid { min-width: 700px; }
        #calendar .fc-list { min-width: 0; }
      }
      /* ---- white page (desktop + phone): the card sits on white, no crimson wash ---- */
      body { background: #fff !important; }
      .app-main { background: #fff !important; }
      .calendar-heading h1 { color: #1f1e1e; }
      .calendar-heading p { color: #6e6a64; }
      .calendar-shell { box-shadow: 0 1px 2px rgba(0,0,0,.04); border: 1px solid #e5e5e5; border-radius: 14px; }
      .calendar-page { --calendar-page-bg: #fff; }
      @media (max-width: 767.98px) {
        .container-fluid { padding-inline: 12px; }
        .app-content-header { padding-top: 16px; }
      }
    </style>
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
      document.addEventListener('DOMContentLoaded', () => {
        const calendarEl = document.getElementById('calendar');
        const calendar = new FullCalendar.Calendar(calendarEl, {
          // 1) Calendar start view/date
          initialView: 'dayGridMonth',
          /* No initialDate: the calendar opens on the CURRENT month (it was
             pinned to '2026-09-01' by the mockup, as the staff one was). */

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

          // read-only: a customer only VIEWS their bookings here.

          // 5) Events = this customer's bookings (from the controller).
          events: {!! $calendarEventsJson !!},
        });

        calendar.render();
      });
    </script>
@endpush
