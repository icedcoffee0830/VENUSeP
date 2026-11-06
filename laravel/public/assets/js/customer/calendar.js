/* Script for resources/views/customer/calendar.blade.php. */
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
          events: customerCalendarPage.calendarEventsJson,
        });

        calendar.render();
      });
    
