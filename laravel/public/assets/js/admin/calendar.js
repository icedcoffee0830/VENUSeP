/* Script for resources/views/admin/calendar.blade.php. */
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
          events: adminCalendarPage.calendarEventsJson,
        });

        calendar.render();
      });
    
