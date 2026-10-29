{{-- CALENDAR (was admin/calendar.php) — every booking (calendar_events(), all customers) on FullCalendar. --}}
@extends('layouts.admin')
@section('title', 'Calendar')
@section('active', 'Calendar')
@section('body-class', 'calendar-page')
@push('styles')
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
