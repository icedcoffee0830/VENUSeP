<?php
/* ==================================================================
   CUSTOMER CALENDAR — same UI as admin/calendar.php, showing THIS
   customer's own bookings. Read-only (a customer only views).
   [SIM] each booking below becomes one event on its date, colored by
   status. Replace with a DB query ("WHERE customer_id = <session>").
   ================================================================== */
$eventColors = [
  'Approved'  => ['#d9eddc', '#16a34a'],
  'Pending'   => ['#fdf1d7', '#f59e0b'],
  'Completed' => ['#d7d7d7', '#1f1e1e'],
  'Rejected'  => ['#fbd5db', '#ff0000'],
  'Cancelled' => ['#fbd5db', '#ff0000'],
];
$customerBookingEvents = [
  // [ event name, date (ISO), status ]
  ['Intercollege Basketball Finals', '2026-06-28', 'Completed'],
  ['Research Documentary Screening', '2026-06-20', 'Completed'],
  ['Leadership Recognition Night',   '2026-06-12', 'Approved'],
  ['Undergraduate Thesis Defense',   '2026-05-30', 'Completed'],
  ['Organization Planning Session',  '2026-05-22', 'Cancelled'],
  ['Digital Literacy Workshop',      '2026-05-15', 'Rejected'],
  ['Alumni Chapter Reunion',         '2026-04-26', 'Completed'],
  ['Graduation Fellowship',          '2026-04-18', 'Cancelled'],
  ['Academic Recognition Ceremony',  '2026-03-28', 'Completed'],
  ['Campus Wellness Fair',           '2026-03-14', 'Rejected'],
  ['Community Volleyball Clinic',    '2026-02-21', 'Completed'],
  ['Licensure Review Session',       '2026-02-08', 'Approved'],
  ['Intramural Basketball',          '2026-08-25', 'Approved'],
  ['Thesis Presentation',            '2026-08-28', 'Pending'],
  ['Student Organization Assembly',  '2026-09-02', 'Approved'],
  ['Capstone Project Meeting',       '2026-09-05', 'Approved'],
  ['Research Consultation',          '2026-09-08', 'Pending'],
  ['Skills Workshop',                '2026-09-12', 'Cancelled'],
  ['Family Reunion',                 '2026-09-15', 'Approved'],
  ['Graduation Dinner',              '2026-09-18', 'Pending'],
  ['Recognition Program',            '2026-09-22', 'Approved'],
  ['Wellness Camp',                  '2026-09-25', 'Cancelled'],
  ['Volleyball Clinic',              '2026-09-28', 'Approved'],
  ['Board Exam Review',              '2026-10-02', 'Pending'],
];
$calendarEvents = [];
foreach ($customerBookingEvents as $ev) {
  $c = $eventColors[$ev[2]] ?? $eventColors['Completed'];
  $calendarEvents[] = ['title' => $ev[0], 'start' => $ev[1], 'backgroundColor' => $c[0], 'textColor' => $c[1]];
}
$calendarEventsJson = json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
?>
<!DOCTYPE html>
<!-- ==================================================================
  CALENDAR — VENUSeP merged system (ported from the calendar mockup)
  ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump to a section:

    [0] SHELL CSS     team header + sidebar layout (same on every page)
    [1] PAGE CSS      the CALENDAR DESIGN — copied verbatim from the
                      mockup (Asset/adminlte/.../pages/calendar.html).
                      DO NOT restyle: the look is intentional.
    [2] HEADER BAR    team top bar   (shared include)
    [3] SIDEBAR       team dark menu (shared include)
    [4] PAGE CONTENT  heading + sort dropdown + the calendar shell
    [6] PAGE SCRIPT   FullCalendar setup + demo events

  (No [5]: the stock AdminLTE library scripts were dropped in this port —
  the team shell needs no AdminLTE JS. Only FullCalendar loads here.)

  [SIM] marks simulation-only pieces (fake events / demo click actions)
  that exist so the mockup works on its own — delete or replace them
  when the real database is connected.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | My Calendar</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- ============================================================
         [0] SHELL CSS — the TEAM header bar + sidebar + layout
         (same block every ported page uses so this page needs no
         AdminLTE stylesheet). The calendar's own look lives in [1].
         ============================================================ -->
    <style>
      :root {
        --venusep-black: #1f1e1e;
        --venusep-cream: #ffffff;
        --venusep-border: #ddd7ce;
        --venusep-text: #050505;
        --venusep-sidebar-width: 235px;
        --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body {
        background: #fff;
        color: var(--venusep-text);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        overflow-x: hidden;
      }
      /* (header + sidebar styles live in ../includes/ — the shared includes) */
      /* main content area sits right of the sidebar, below the header */
      .app-main {
        margin-left: var(--venusep-sidebar-width);
        padding-top: var(--venusep-header-height);
        min-height: 100vh;
        background: #fff;
      }
      .container-fluid { width: 100%; padding-inline: 30px; } /* uniform content inset — 30px sides on every admin page */
      .app-content-header { padding: 1.3rem 0 0; }
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
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

      /* 4) Header Sort UI */
      .calendar-sort-bar {
        align-items: center;
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
        margin-left: auto;
      }

      .calendar-sort-label {
        color: var(--calendar-muted);
        font-size: 0.8125rem;
        font-weight: 600;
      }

      .calendar-sort-dropdown {
        position: relative;
        width: 10rem;
      }

      .calendar-sort-trigger {
        background-color: var(--calendar-button-bg);
        background-image:
          linear-gradient(45deg, transparent 50%, var(--calendar-button-text) 50%),
          linear-gradient(135deg, var(--calendar-button-text) 50%, transparent 50%);
        background-position:
          calc(100% - 16px) 50%,
          calc(100% - 11px) 50%;
        background-repeat: no-repeat;
        background-size:
          5px 5px,
          5px 5px;
        border: 1px solid var(--calendar-border);
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08);
        color: var(--calendar-button-text);
        cursor: pointer;
        display: flex;
        align-items: center;
        font-size: 0.8125rem;
        font-weight: 700;
        height: 2rem;
        min-width: 0;
        padding: 0 2.1rem 0 0.85rem;
        list-style: none;
        width: 10rem;
        transition:
          background-color 0.18s ease,
          border-color 0.18s ease,
          box-shadow 0.18s ease;
      }

      .calendar-sort-trigger::-webkit-details-marker {
        display: none;
      }

      .calendar-sort-trigger:hover,
      .calendar-sort-trigger:focus {
        background-color: var(--calendar-hover);
        border-color: #cfcac2;
        outline: 0;
      }

      .calendar-sort-menu {
        background: #ffffff;
        border: 1px solid var(--calendar-border);
        border-radius: 8px;
        box-shadow: 0 6px 18px rgba(16, 24, 40, 0.14);
        left: 0;
        list-style: none;
        margin: 0.35rem 0 0;
        overflow: hidden;
        padding: 0;
        position: absolute;
        right: 0;
        top: 100%;
        z-index: 20;
      }

      .calendar-sort-option {
        color: var(--calendar-text);
        cursor: pointer;
        font-size: 0.8125rem;
        padding: 0.45rem 0.75rem;
      }

      .calendar-sort-option:hover {
        background: var(--calendar-hover);
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

        .calendar-sort-bar {
          align-items: stretch;
          width: 100%;
        }

        .calendar-sort-dropdown,
        .calendar-sort-trigger {
          width: 100%;
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
  </head>
  <body class="calendar-page">
    <div class="app-wrapper">
      <!-- ==========================================================
           [2] HEADER BAR — the ONE shared top bar, included from
           ../includes/header.php. Edit it THERE and every page updates.
           ========================================================== -->
      <?php $portal = 'customer'; include __DIR__ . '/../includes/header.php'; ?>

      <!-- ==========================================================
           [3] SIDEBAR — the ONE shared sidebar, included from
           ../includes/sidebar.php ($active = the highlighted item).
           ========================================================== -->
      <?php $active = 'Calendar'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <!-- ==========================================================
           [4] PAGE CONTENT — heading + sort dropdown + the calendar
           shell. Markup is verbatim from the mockup (do not restyle).
           ========================================================== -->
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="calendar-heading">
              <div class="calendar-heading-copy">
                <h1>My Calendar</h1>
                <p>Your venue bookings at a glance</p>
              </div>
              <!-- Calendar sorting UI only. Functionality can be added later. -->
              <div class="calendar-sort-bar">
                <details class="calendar-sort-dropdown">
                  <summary class="calendar-sort-trigger">Date</summary>
                  <ul class="calendar-sort-menu" aria-label="Calendar sort options">
                    <li class="calendar-sort-option">Date</li>
                    <li class="calendar-sort-option">Event name</li>
                    <li class="calendar-sort-option">Status</li>
                    <li class="calendar-sort-option">Venue</li>
                  </ul>
                </details>
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
    </div>

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
          initialDate: '2026-09-01',

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

          // 5) Events = this customer's bookings (from the PHP block above).
          events: <?php echo $calendarEventsJson; ?>,
        });

        calendar.render();
      });
    </script>
  </body>
</html>
