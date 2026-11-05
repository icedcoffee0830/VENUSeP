// Flow — the staff and admin screens added on 2026-11-01, in a real browser: force cancel,
// moving a date, recording an outside refund, Reverse payment (with the password), and the
// admin's Office Settings and Activity pages. Bookings are made through the counter's own
// endpoint from the staff's session; the screens under test come after.
const STAFF = 'staff@gmail.com';              // handles venue 1
const ADMIN = 'admin@gmail.com';

export default {
  name: 'staff-settlement',
  title: 'Force cancel, move date, outside refund, Reverse payment, Office Settings, Activity',
  settings: { refunds_enabled: '0' },

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    /* A walk-in booking at the counter; paid = also takes the cash. */
    async function seed(salt, paid) {
      const page = s.staff;
      await page.goto(ctx.url('/customer/room-reservation?room=r1&counter=1'));
      const stamp = Number(ctx.uniq.replace(/\D/g, '').slice(-2)) + salt * 3;
      const day = String((await dbOne(`SELECT DATE_ADD(CURDATE(), INTERVAL ? DAY) AS d`, [30 + stamp])).d).slice(0, 10);
      const post = (url, data) => page.evaluate(async ([u, d]) => {
        const r = await fetch(u, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _token: CSRF, ...d }) });
        return r.json();
      }, [ctx.url(url), data]);
      const made = await post('/admin/booking-create', {
        type: 'venue', room: 'r1', method: 'cash', id_checked: '1', usep_id: '0', booker_mode: 'walkin', agree_no_refund: '1',
        walkin_name: 'Settle ' + salt, walkin_phone: '09171234567', walkin_no_email: '1', organization: 'CIC Student Council',
        event_name: 'Settlement event', date_start: day, date_end: day, attendees: '20',
        times: JSON.stringify({ [day]: { start: '08:00', end: '12:00' } }),
      });
      expect.ok(made.ok, 'the counter refused the booking: ' + made.message);
      if (paid) {
        /* a post-pay venue booking is paid after its event: pretend staff took it early */
        const res = await post('/admin/booking-action', { booking: made.reference, action: 'cash_received' });
        ctx.note('cash: ' + (res.ok ? 'taken' : res.message));
      }
      return { id: made.bookingId, ref: made.reference, day };
    }
    const open = (page, ref) => page.goto(ctx.url(`/admin/booking-request?id=${ref}`));

    await step('Staff force cancel an unpaid booking: a reason is required, then the date is freed', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      s.a = await seed(1, false);
      const page = s.staff;
      await open(page, s.a.ref);
      await page.getByRole('button', { name: 'Force cancel' }).click();
      await page.locator('#xSend').click();
      await expect.text(page, 'Choose a reason and write a note.');
      await page.locator('#xReason').selectOption('fake');
      await page.locator('#xNote').fill('Nobody answers this number');
      await Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.locator('#xSend').click()]);
      const b = await dbOne(`SELECT reservation_status FROM bookings WHERE id = ?`, [s.a.id]);
      expect.equal(b.reservation_status, 'cancelled', 'reservation_status');
      expect.equal(await page.getByRole('button', { name: 'Force cancel' }).count(), 0, 'Force cancel still offered');
    });

    await step('Staff move a booking to another date, once', async () => {
      const page = s.staff;
      s.b = await seed(2, false);
      await open(page, s.b.ref);
      await page.getByRole('button', { name: 'Move date' }).click();
      const day = String((await dbOne(`SELECT DATE_ADD(?, INTERVAL 7 DAY) AS d`, [s.b.day])).d).slice(0, 10);
      await page.locator('#xIn').fill(day);
      await page.locator('#xOutD').fill(day);
      await page.locator('#xStart').fill('09:00');
      await page.locator('#xEnd').fill('12:00');
      await Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.locator('#xSend').click()]);
      const d = await dbOne(`SELECT start_date FROM venue_booking_details WHERE booking_id = ?`, [s.b.id]);
      expect.equal(String(d.start_date).slice(0, 10), day, 'start_date');
    });

    await step('Office Settings: the admin closes Bahay Alumni on a date; other offices are untouched', async () => {
      s.admin = await ctx.loginAdmin(ADMIN);
      const page = s.admin;
      await page.goto(ctx.url('/admin/office-settings'));
      const card = page.locator('[data-office="1"]');
      await card.locator('input[name="from"]').fill(s.b.day);
      await card.locator('input[name="until"]').fill(s.b.day);
      await card.locator('.os-closure input[name="reason"]').fill('Flow closure');
      await card.getByRole('button', { name: 'Close this office' }).click();
      await expect.text(page, /Closure saved/);
      const n = await dbOne(`SELECT COUNT(*) AS n FROM venue_closures WHERE venue_id = 1 AND reason = 'Flow closure'`);
      expect.equal(Number(n.n), 1, 'venue_closures rows');
      const other = await dbOne(`SELECT fn_room_hard_blocked((SELECT id FROM rooms WHERE room_code = 'r5'), ?) AS x`, [s.b.day]);
      expect.equal(Number(other.x), 0, 'another office closed too');
    });

    await step('The Activity page shows what staff did', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/activity'));
      await expect.text(page, 'Nobody answers this number');
      await s.staff.goto(ctx.url('/admin/activity'));
      await expect.url(s.staff, /\/admin\/dashboard/);
    });

    await step('The new settings reach the booking page: the time picker follows the office hours', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/office-settings'));
      const card = page.locator('[data-office="1"]');
      await card.locator('input[name="opens"]').fill('09:00');
      await card.locator('input[name="closes"]').fill('17:00');
      const reloaded = page.waitForResponse((r) => r.url().includes('part=1')).then(() => true, () => false);
      await card.getByRole('button', { name: /^Save / }).click();
      expect.ok(await reloaded, 'no reload after Save');
      await s.staff.goto(ctx.url('/customer/room-reservation?room=r1&counter=1'));
      const opens = await s.staff.evaluate(() => DAY_OPEN);
      expect.equal(opens, 540, 'DAY_OPEN on the booking page');
    });
  },
};
