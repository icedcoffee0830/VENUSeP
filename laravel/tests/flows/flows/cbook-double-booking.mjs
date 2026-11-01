// cbook — two customers want the same room at the same time, and a hostel room
// is asked for more beds than it has.
// A. mesionaalexa@gmail.com books the USeP Gymnasium 9:00 AM – 1:00 PM on a free date.
// B. aronerfe0830@gmail.com opens the same room and date: 9:00 AM is greyed out.
//    A stale page posting 10:00 – 12:00 (overlap) or 1:30 – 3:00 PM (inside the
//    60-minute cleanup gap) is refused by the server, and nothing is written.
// C. B books 2:00 – 4:00 PM through the page: first with a text file as the "ID"
//    (refused, the reason is shown on the review screen, nothing written), then
//    with a real ID — accepted. The day now holds two slots that do not overlap.
// D. B asks Hostel Room 3 for one bed more than it has: refused, nothing held.

const ROOM = 'r5';                       // USeP Gymnasium, USeP Venues (no lead time)
const HOSTEL_ROOM = 'h3';
const A = 'mesionaalexa@gmail.com';   // customers with no overdue booking (one blocks new bookings)
const B = 'aronerfe0830@gmail.com';

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/* What a stale or tampered page could send: booking-submit straight from the
   signed-in page (its own CSRF token), with a real 1px PNG as the ID. */
function postBooking(page, url, fields) {
  return page.evaluate(async ({ fields, png, url }) => {
    const fd = new FormData();
    fd.append('_token', CSRF);
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    const bytes = Uint8Array.from(atob(png), (c) => c.charCodeAt(0));
    fd.append('id_document', new Blob([bytes], { type: 'image/png' }), 'id.png');
    const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
    return { status: res.status, body: await res.json().catch(() => null) };
  }, { fields, png: PNG_1PX, url });
}

async function openRoomOn(ctx, page, day) {
  await page.goto(ctx.url(`/customer/room-reservation?room=${ROOM}`));
  await ctx.expect.text(page, 'Reserve this room');
  await page.locator('#bk-date').click();
  const btn = page.locator(`.cal-pop button[onclick="calPick('${day}')"]`);
  for (let i = 0; i < 4 && !(await btn.isVisible()); i++) await page.locator('.cal-pop .cal-nav').last().click();
  await btn.click();
}

async function pickTime(page, field, label) {
  await page.locator(field).click();
  await page.locator('.tt-pop').getByRole('button', { name: label, exact: true }).click();
}

export default {
  name: 'cbook-double-booking',
  title: 'Two customers, one room and time: overlap and cleanup gap refused, wrong ID refused, a hostel room over capacity refused',

  async run(ctx) {
    const { step, expect, db, dbOne } = ctx;
    const s = ctx.state;
    s.post = (page, fields) => postBooking(page, ctx.url('/customer/booking-submit'), fields);

    await step('Customer A signs in and finds a free date for the USeP Gymnasium', async () => {
      s.a = await ctx.loginCustomer(A);
      const room = await dbOne(`SELECT r.id FROM rooms r WHERE r.room_code = ?`, [ROOM]);
      s.roomId = Number(room.id);
      const d = new Date(); d.setDate(d.getDate() + 14);
      for (let i = 0; i < 60 && !s.day; i++, d.setDate(d.getDate() + 1)) {
        const day = iso(d);
        const busy = await dbOne(`SELECT
            (SELECT COUNT(*) FROM venue_booking_slots WHERE room_id = ? AND slot_date = ? AND released_at IS NULL) +
            (SELECT COUNT(*) FROM maintenance_windows WHERE room_id = ? AND from_date <= ? AND (until_date IS NULL OR until_date >= ?)) AS n`,
          [s.roomId, day, s.roomId, day, day]);
        if (Number(busy.n) === 0) s.day = day;
      }
      expect.ok(s.day, 'no free date in the next 60 days');
      ctx.note('date ' + s.day);
      return s.a;
    });

    await step('Customer A books 9:00 AM – 1:00 PM', async () => {
      const page = s.a;
      await openRoomOn(ctx, page, s.day);
      await page.locator('#bk-eventName').fill('Double-booking test A ' + ctx.uniq);
      await pickTime(page, '#bk-start', '9:00 AM');
      await pickTime(page, '#bk-end', '1:00 PM');
      await page.locator('#bk-attendees').fill('40');
      await expect.text(page, 'This slot is available');
      await page.getByRole('button', { name: /Continue — review/ }).click();
      await page.getByText('Not affiliated', { exact: true }).click();
      await page.locator('#idFileInput').setInputFiles(await ctx.fixtures.idImage('Brent Cajipoe'));
      await page.locator('#agreeNoRefund').check();
      await page.getByRole('button', { name: 'Submit booking request' }).click();
      await expect.text(page, 'Request submitted — awaiting approval', 30_000);
      const slots = await db(`SELECT start_time, end_time FROM venue_booking_slots WHERE room_id = ? AND slot_date = ? AND released_at IS NULL`, [s.roomId, s.day]);
      expect.equal(slots.length, 1, 'slots held that day');
      expect.equal(slots[0].start_time, '09:00:00', 'A start');
      expect.equal(slots[0].end_time, '13:00:00', 'A end');
    });

    await step('Customer B opens the same room and date: 9:00 AM is greyed out', async () => {
      s.b = await ctx.loginCustomer(B);
      const page = s.b;
      await openRoomOn(ctx, page, s.day);
      await page.locator('#bk-start').click();
      const nine = page.locator('.tt-pop').getByRole('button', { name: '9:00 AM', exact: true });
      expect.ok(await nine.isDisabled(), '9:00 AM can still be picked on a date A holds from 9:00');
      const twoPm = page.locator('.tt-pop').getByRole('button', { name: '2:00 PM', exact: true });
      expect.ok(await twoPm.isEnabled(), '2:00 PM (after the cleanup gap) is not offered');
      await twoPm.click();                                   // closes the list
      return page;
    });

    await step('A stale page posting an overlap, or a time inside the cleanup gap, is refused', async () => {
      const page = s.b;
      const base = { type: 'venue', room: ROOM, event_name: 'Double-booking test B', date_start: s.day, date_end: s.day,
                     attendees: '40', affiliated: '0', method: 'gcash', agree_no_refund: '1' };
      const before = await dbOne(`SELECT COUNT(*) AS n FROM bookings`);
      for (const [start, end] of [['10:00', '12:00'], ['13:30', '15:00']]) {
        const r = await s.post(page, { ...base, times: JSON.stringify({ [s.day]: { start, end } }) });
        expect.equal(r.status, 409, `HTTP status for ${start}–${end}`);
        expect.ok(r.body && r.body.ok === false, `reply for ${start}–${end}: ` + JSON.stringify(r.body));
        ctx.note(`${start}–${end}: ${r.body.message}`);
      }
      const after = await dbOne(`SELECT COUNT(*) AS n FROM bookings`);
      expect.equal(after.n, before.n, 'bookings rows after the refused posts');
    });

    await step('B books 2:00 – 4:00 PM: a text file as the ID is refused and the reason shown', async () => {
      const page = s.b;
      await page.locator('#bk-eventName').fill('Double-booking test B ' + ctx.uniq);
      await pickTime(page, '#bk-start', '2:00 PM');
      await pickTime(page, '#bk-end', '4:00 PM');
      await page.locator('#bk-attendees').fill('30');
      await expect.text(page, 'This slot is available');
      await page.getByRole('button', { name: /Continue — review/ }).click();
      await page.getByText('Not affiliated', { exact: true }).click();
      await page.locator('#idFileInput').setInputFiles({ name: 'my-id.txt', mimeType: 'text/plain', buffer: Buffer.from('not an id') });
      await page.locator('#agreeNoRefund').check();
      const before = await dbOne(`SELECT COUNT(*) AS n FROM bookings`);
      await page.getByRole('button', { name: 'Submit booking request' }).click();
      const alert = page.getByRole('alert');
      await expect.visible(alert, 'no refusal shown on the review screen');
      expect.match(await alert.textContent(), /Upload a JPG, PNG, WEBP or PDF/, 'refusal text');
      const after = await dbOne(`SELECT COUNT(*) AS n FROM bookings`);
      expect.equal(after.n, before.n, 'bookings rows after the refused ID');
    });

    await step('…then with a real ID it is accepted, and the day holds two slots that do not overlap', async () => {
      const page = s.b;
      await page.getByRole('button', { name: 'Remove' }).click();
      await page.locator('#idFileInput').setInputFiles(await ctx.fixtures.idImage('Rafael Lim'));
      await page.getByRole('button', { name: 'Submit booking request' }).click();
      await expect.text(page, 'Request submitted — awaiting approval', 30_000);
      const slots = await db(`SELECT start_time, end_time FROM venue_booking_slots WHERE room_id = ? AND slot_date = ? AND released_at IS NULL ORDER BY start_time`, [s.roomId, s.day]);
      expect.equal(slots.map((x) => x.start_time + '-' + x.end_time).join(', '), '09:00:00-13:00:00, 14:00:00-16:00:00', 'slots held that day');
      const doc = await dbOne(`SELECT COUNT(*) AS n FROM booking_documents d JOIN venue_booking_slots s ON s.booking_id = d.booking_id
          WHERE s.room_id = ? AND s.slot_date = ? AND s.start_time = '14:00:00'`, [s.roomId, s.day]);
      expect.equal(doc.n, 1, 'B\'s ID stored once');
    });

    await step('A hostel room asked for one bed more than it has is refused, nothing held', async () => {
      const page = s.b;
      const room = await dbOne(`SELECT r.id, (SELECT COUNT(*) FROM hostel_beds b WHERE b.room_id = r.id AND b.is_active = 1) AS beds
          FROM rooms r WHERE r.room_code = ?`, [HOSTEL_ROOM]);
      const d = new Date(); d.setDate(d.getDate() + 2);
      const checkIn = iso(d); d.setDate(d.getDate() + 1);
      const guests = Array.from({ length: Number(room.beds) + 1 }, (_, i) => ({ name: 'Guest ' + (i + 1), gender: 'male' }));
      const before = await dbOne(`SELECT COUNT(*) AS n FROM bookings`);
      const r = await s.post(page, { type: 'hostel', room: HOSTEL_ROOM, check_in: checkIn, check_out: iso(d),
        occupants: JSON.stringify(guests), affiliated: '0', agree_no_refund: '1' });
      expect.equal(r.status, 409, 'HTTP status');
      expect.match(r.body?.message || '', /not enough free beds/, 'reply message');
      const after = await dbOne(`SELECT COUNT(*) AS n FROM bookings`);
      expect.equal(after.n, before.n, 'bookings rows (the half-made stay must be removed)');
      ctx.note(`${guests.length} guests for ${room.beds} beds: ${r.body.message}`);
    });
  },
};
