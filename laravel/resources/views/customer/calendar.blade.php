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
      /*
        Calendar page UI CSS
        Move this whole block to the shared CSS file when your group merges pages.
      */

      /* 1) Quick Edit Values */
      :root {
        --calendar-font: 'Inter', sans-serif;
        --calendar-page-bg: #ffffff;
        --calendar-bg: #ffffff;
        --calendar-border: #dedbd6;
        --calendar-text: #1f1e1e;
        --calendar-muted: #5f6873;
        --calendar-hover: #ebebeb;
        --calendar-today-bg: #1f1e1e;
        --calendar-today-text: #ffffff;
        --calendar-button-bg: #ffffff;
        --calendar-button-text: #1f1e1e;
        --calendar-button-active-bg: #1f1e1e;
        --calendar-button-active-text: #ffffff;
      }

      /* 2) Page Layout */
      .calendar-page {
        font-family: var(--calendar-font);
      }

      body.calendar-page,
      .calendar-page .app-main,
      .calendar-page .app-content,
      .calendar-page .app-content-header {
        background: var(--calendar-page-bg) !important;
      }

      .calendar-page .app-content-header {
        padding-top: 1.25rem;
      }

      .calendar-shell,
      #calendar {
        background: var(--calendar-bg);
      }

      .calendar-shell {
        border: 1px solid var(--calendar-border);
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(16, 24, 40, 0.14);
        padding: 1.25rem;
      }

      /* 3) Page Heading */
      .calendar-heading {
        align-items: flex-start;
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        width: 100%;
      }

      .calendar-heading-copy {
        min-width: 0;
      }

      .calendar-heading h1 {
        color: var(--calendar-text);
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: 0;
        line-height: 1;
        margin: 0;
      }

      .calendar-heading p {
        color: var(--calendar-muted);
        font-size: 0.875rem;
        margin: 0.2rem 0 1.6rem;
      }

      /* 5) Calendar Toolbar */
      #calendar .fc-toolbar.fc-header-toolbar {
        align-items: center;
        margin-bottom: 1.15rem;
      }

      #calendar .fc-toolbar-chunk:first-child {
        align-items: center;
        display: flex;
        gap: 0.65rem;
      }

      #calendar .fc-toolbar-chunk:last-child {
        display: flex;
      }

      #calendar .fc-toolbar-title {
        color: var(--calendar-text);
        font-size: 1.125rem;
        font-weight: 700;
        line-height: 1;
      }

      /* 6) Calendar Buttons */
      #calendar .fc-button-primary {
        align-items: center;
        background: var(--calendar-button-bg);
        border: 1px solid var(--calendar-border);
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08);
        color: var(--calendar-button-text);
        display: inline-flex;
        font-size: 0.8125rem;
        font-weight: 700;
        height: 2rem;
        justify-content: center;
        line-height: 1;
        padding: 0 0.85rem;
        transition:
          background-color 0.18s ease,
          border-color 0.18s ease,
          color 0.18s ease,
          box-shadow 0.18s ease;
      }

      #calendar .fc-button-group {
        gap: 0.55rem;
        width: 100%;
      }

      #calendar .fc-button-group > .fc-button {
        flex: 1 1 0;
        border-radius: 8px;
      }

      #calendar .fc-prev-button,
      #calendar .fc-next-button {
        padding: 0;
        width: 2rem;
      }

      #calendar .fc-button-primary:hover,
      #calendar .fc-button-primary:focus,
      #calendar .fc-button-primary:active {
        background: var(--calendar-hover);
        border-color: #cfcac2;
        color: var(--calendar-text);
      }

      #calendar .fc-button-primary.fc-button-active {
        background: var(--calendar-button-active-bg);
        border-color: var(--calendar-button-active-bg);
        color: var(--calendar-button-active-text);
      }

      /* 7) Month Grid */
      #calendar .fc-scrollgrid,
      #calendar .fc-scrollgrid-section > td,
      #calendar .fc-theme-standard td,
      #calendar .fc-theme-standard th {
        border-color: var(--calendar-border);
      }

      #calendar .fc-scrollgrid-section-header > th,
      #calendar .fc-col-header-cell {
        border: 0;
      }

      #calendar .fc-col-header {
        margin-bottom: 0.45rem;
      }

      #calendar .fc-col-header-cell,
      #calendar .fc-daygrid-day {
        background: var(--calendar-bg);
      }

      #calendar .fc-col-header-cell {
        padding-bottom: 0.45rem;
      }

      #calendar .fc-col-header-cell-cushion,
      #calendar .fc-timegrid-slot-label,
      #calendar .fc-list-day-text,
      #calendar .fc-list-day-side-text {
        color: var(--calendar-muted);
        font-size: 0.75rem;
        font-weight: 500;
        text-decoration: none;
      }

      #calendar .fc-daygrid-day {
        transition: background-color 0.18s ease;
      }

      #calendar .fc-daygrid-day:hover {
        background: var(--calendar-hover);
      }

      #calendar .fc-daygrid-day-frame {
        min-height: 100px;
        padding: 0.35rem 0.45rem;
      }

      #calendar .fc-daygrid-day-number {
        color: var(--calendar-text);
        font-size: 0.9375rem;
        font-weight: 700;
        line-height: 1;
        padding: 0.25rem 0 0.45rem;
        text-decoration: none;
      }

      #calendar .fc-day-other .fc-daygrid-day-number {
        color: #697586;
        font-weight: 500;
      }

      #calendar .fc-day-today {
        background: var(--calendar-bg);
      }

      #calendar .fc-day-today .fc-daygrid-day-number {
        align-items: center;
        background: var(--calendar-today-bg);
        border-radius: 50%;
        color: var(--calendar-today-text);
        display: inline-flex;
        height: 1.75rem;
        justify-content: center;
        margin-top: 0.1rem;
        padding: 0;
        width: 1.75rem;
      }

      /* 8) Week, Day, and List Views */
      #calendar .fc-timegrid-slot,
      #calendar .fc-timegrid-axis,
      #calendar .fc-timegrid-col,
      #calendar .fc-list,
      #calendar .fc-list-table td,
      #calendar .fc-list-day-cushion {
        background: var(--calendar-bg);
      }

      #calendar .fc-timegrid-col:hover,
      #calendar .fc-list-event:hover td {
        background: var(--calendar-hover);
      }

      /* 9) Event Pills */
      #calendar .fc-event {
        border: 0;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.75rem;
        font-weight: 400;
        line-height: 1.15;
        margin: 0 0.35rem 0.25rem;
        padding: 0.2rem 0.35rem;
      }

      #calendar .fc-daygrid-event-dot {
        display: none;
      }

      #calendar .fc-event-title {
        white-space: normal;
      }

      /* 10) Mobile Adjustments */
      @media (max-width: 767.98px) {
        .calendar-shell {
          padding: 0.85rem;
        }

        .calendar-heading {
          flex-direction: column;
        }

        #calendar .fc-toolbar.fc-header-toolbar {
          align-items: flex-start;
          gap: 0.75rem;
        }

        #calendar .fc-daygrid-day-frame {
          min-height: 86px;
          padding-inline: 0.25rem;
        }
      }
    
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
