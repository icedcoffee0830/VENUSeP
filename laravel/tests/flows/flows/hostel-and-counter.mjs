// Flow 2 — a hostel stay from request to OR, then two bookings taken at the counter.
// A. customer signs in → hostel listing → room, nights, named guests → valid ID →
//    submit → reference → Booking History.
// B. admin approves (pay to use: payment opens at approval) → records the POS →
//    the customer chooses cash at the front desk in Pay now → admin "Cash
//    received" (System Receipt) → records the OR.
// C. staff@gmail.com opens the counter (dashboard "+ New Booking") → books a
//    Bahay Alumni room for the same customer: search → password → ID seen →
//    approved on the spot, the counter staff on its history.
// D. the admin takes a hostel stay for TONIGHT at the counter (cash first, so it
//    is paid on creation) and checks the guest in.
//
// staff@gmail.com handles venue 1 (Bahay Alumni) only: the hostel (venue 3)
// answers it 403, so the hostel steps run as admin@gmail.com. Refunds stay OFF
// (the snapshot default), so every booking ticks "non-refundable".

const CUSTOMER = 'rafael.lim@gmail.com';      // Rafael Lim — non-USeP, no authenticator
const CUSTOMER_NAME = 'Rafael Lim';
const STAFF = 'staff@gmail.com';              // venue 1 (Bahay Alumni)
const HOSTEL_ROOM = 'h1';                     // Hostel Room 1 — part A
const COUNTER_HOSTEL_ROOM = 'h2';             // Hostel Room 2 — part D (tonight)
const VENUE_ROOM = 'r2';                      // Heritage Function Room, Bahay Alumni — part C

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (n) => { const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() + n); return d; };

/* The page's own date picker: open it, page forward to the month, pick the day. */
async function pickHostelDate(page, field, day) {
  await page.locator(`button[onclick*="openCal('${field}')"]`).click();
  const btn = page.locator(`.cal-pop button[onclick*="calPick('${day}')"]`);
  for (let i = 0; i < 4 && !(await btn.isVisible()); i++) await page.locator('.cal-pop .cal-nav').last().click();
  await btn.click();
}

/* The counter's "who is this for" step (legacy counter-booker.php), account path. */
async function counterWho(ctx, page) {
  const { expect } = ctx;
  await expect.text(page, 'Who is this booking for?');
  await page.locator('#bkSearch').fill('Rafael');
  const hit = page.locator('button', { hasText: CUSTOMER });
  await expect.visible(hit, 'the customer search never listed ' + CUSTOMER);
  await hit.first().click();
  await page.locator('#bkPassword').fill(ctx.password);
  await page.getByRole('button', { name: 'Confirm identity' }).click();
  await expect.text(page, 'Identity confirmed');
  await page.locator('#bkIdChecked').check();
  await page.getByRole('button', { name: /Continue — review the booking/ }).click();
  await expect.text(page, 'Valid ID checked in person by');
}

export default {
  name: 'hostel-and-counter',
  title: 'Hostel stay → approval → POS / cash / OR, then counter bookings (venue + hostel tonight)',

  async run(ctx) {
    const { step, expect, dbOne, db } = ctx;
    const s = ctx.state;

    /* ================= A. the customer books a hostel stay ================= */

    await step('The customer signs in', async () => {
      s.cust = await ctx.loginCustomer(CUSTOMER);
      await expect.url(s.cust, /\/customer\/venues/);
      const c = await dbOne(`SELECT c.id FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = ?`, [CUSTOMER]);
      s.customerId = Number(c.id);
      return s.cust;
    });

    await step('Opens Hostel Room 1 from the hostel listing', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/venues'));
      await expect.visible(page.locator('#hostel-listings'), 'no hostel section on /customer/venues');
      const card = page.locator(`#hostel-listings a.lp-card[href*="hostel-reservation?room=${HOSTEL_ROOM}"]`);
      await expect.visible(card, 'no card for Hostel Room 1 in the hostel listing');
      await card.click();
      await expect.url(page, new RegExp(`hostel-reservation\\?room=${HOSTEL_ROOM}`));
      await expect.text(page, 'Reserve a bed');
    });

    await step('Picks two nights a few days out and names two guests', async () => {
      const page = s.cust;
      const room = await dbOne(`SELECT id FROM rooms WHERE room_code = ?`, [HOSTEL_ROOM]);
      const maxDays = Number((await dbOne(`SELECT setting_value AS v FROM system_settings WHERE setting_key = 'hostel_advance_booking_max_days'`))?.v ?? 7);
      // the first check-in from 3 days out (inside the advance window) with 2 beds free on both nights
      for (let off = 3; off + 2 <= Math.max(maxDays, 3) + 2 && !s.checkIn; off++) {
        const n1 = iso(addDays(off)), n2 = iso(addDays(off + 1));
        const r = await dbOne(`SELECT
            (SELECT COUNT(*) FROM hostel_beds WHERE room_id = ?) -
            (SELECT COUNT(DISTINCT bed_id) FROM v_active_hostel_bed_nights WHERE room_id = ? AND night_date IN (?, ?)) AS free,
            (SELECT COUNT(*) FROM maintenance_windows WHERE room_id = ? AND blocks_booking = 1 AND from_date <= ? AND (until_date IS NULL OR until_date >= ?)) AS closed`,
          [room.id, room.id, n1, n2, room.id, n2, n1]);
        if (Number(r.free) >= 2 && Number(r.closed) === 0) { s.checkIn = n1; s.checkOut = iso(addDays(off + 2)); }
      }
      expect.ok(s.checkIn, 'no two free nights in the advance-booking window');
      ctx.note(`stay ${s.checkIn} → ${s.checkOut}`);

      await pickHostelDate(page, 'checkIn', s.checkIn);
      await pickHostelDate(page, 'checkOut', s.checkOut);
      await page.locator('button[onclick^="setBeds("]').last().click();      // + : two beds
      await expect.visible(page.locator('.bed-name').nth(1), 'the + button did not add a second bed');
      await page.locator('.bed-name').nth(0).fill('Rafael Lim');
      await page.locator('.bed-name').nth(1).fill('Paolo Lim');
      await page.locator('.bed-row').nth(1).getByRole('button', { name: 'Male', exact: true }).click();
      await page.locator('.bed-row').nth(0).getByRole('button', { name: 'Male', exact: true }).click();
      await expect.text(page, '2 nights · 2 beds');
      const go = page.locator('#hbGo');
      expect.ok(await go.isEnabled(), 'Continue is still disabled: ' + (await page.locator('#hbHint').textContent()));
      await go.click();
      await expect.text(page, 'Review your booking');
    });

    await step('Attaches a valid ID, accepts non-refundable and submits', async () => {
      const page = s.cust;
      await page.getByText('Not affiliated', { exact: true }).click();
      await page.locator('#idFile').setInputFiles(await ctx.fixtures.idImage(CUSTOMER_NAME));
      await expect.visible(page.getByRole('button', { name: 'Remove' }), 'the ID file was not taken');
      await page.locator('#agreeNoRefund').check();
      await page.getByRole('button', { name: 'Submit booking request' }).click();
      await expect.text(page, 'Submitted — awaiting approval', 30_000);
      const refCell = page.locator('div', { hasText: /^Reference$/ }).locator('xpath=following-sibling::div[1]').first();
      s.ref = (await refCell.textContent())?.trim();
      expect.match(s.ref, /^VB-\d{4}-\d+$/, 'reference on the confirmation screen');
      s.bookingId = Number(s.ref.split('-')[2]);
      ctx.note('reference ' + s.ref);
      const b = await dbOne(`SELECT b.booking_type, b.customer_id, b.reservation_status, b.payment_status, b.pay_to_use, b.total_amount,
            h.check_in_date, h.check_out_date
          FROM bookings b JOIN hostel_booking_details h ON h.booking_id = b.id WHERE b.id = ?`, [s.bookingId]);
      expect.ok(b, 'no bookings + hostel_booking_details row for ' + s.ref);
      expect.equal(b.booking_type, 'hostel', 'booking_type');
      expect.equal(b.customer_id, s.customerId, 'booking owner');
      expect.equal(b.reservation_status, 'pending', 'reservation_status');
      expect.equal(b.payment_status, 'locked', 'payment_status');
      expect.equal(b.pay_to_use, 1, 'pay_to_use');
      expect.equal(b.check_in_date, s.checkIn, 'check-in date');
      expect.equal(b.check_out_date, s.checkOut, 'check-out date');
      expect.equal(Number(b.total_amount), 2 * 2 * 350, 'total (2 beds × 2 nights × ₱350)');
      const occ = await dbOne(`SELECT COUNT(*) AS n FROM hostel_occupants WHERE booking_id = ?`, [s.bookingId]);
      expect.equal(occ.n, 2, 'hostel_occupants rows');
      const nights = await dbOne(`SELECT COUNT(*) AS n FROM v_active_hostel_bed_nights WHERE booking_id = ?`, [s.bookingId]);
      expect.equal(nights.n, 4, 'bed-nights held (2 beds × 2 nights)');
      const doc = await dbOne(`SELECT COUNT(*) AS n FROM booking_documents WHERE booking_id = ?`, [s.bookingId]);
      expect.ok(Number(doc.n) >= 1, 'the valid ID was not stored (no booking_documents row)');
    });

    await step('The stay is in Booking History as Pending', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      const row = page.locator(`tr[data-booking-id="${s.ref}"]`);
      await expect.visible(row, `${s.ref} is not listed in Booking History`);
      expect.match(await row.getAttribute('data-status'), /Pending/i, 'booking status in the list');
    });

    /* ================= B. the hostel steps on the staff side ================= */

    await step('An admin signs in and approves the ID and the stay', async () => {
      s.admin = await ctx.loginAdmin('admin@gmail.com');
      const page = s.admin;
      await page.goto(ctx.url('/admin/booking-requests'));
      await page.locator('#brSearch').fill(s.ref);
      const row = page.locator('a.br-row', { hasText: s.ref });
      await expect.visible(row, `${s.ref} is not in the admin Booking Requests list`);
      await row.click();
      await expect.url(page, /\/admin\/booking-request\?id=/);
      await expect.text(page, 'Guests · one per bed');
      await page.getByRole('button', { name: /Approve ID & reservation/ }).click();
      await expect.text(page, /Payment due before check-in/, 20_000);
      const b = await dbOne(`SELECT reservation_status, payment_status FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.reservation_status, 'approved', 'reservation_status');
      expect.equal(b.payment_status, 'await_gcash', 'payment_status after approval (pay to use: payment opens)');
    });

    await step('Records the POS from CEDU (paperwork, no status change)', async () => {
      const page = s.admin;
      s.pos = 'POS-' + ctx.uniq.replace(/\D/g, '').slice(-8);
      await page.locator('#posNo').fill(s.pos);
      await page.getByRole('button', { name: 'Record POS', exact: true }).click();
      await expect.text(page, s.pos, 20_000);
      const h = await dbOne(`SELECT h.pos_number, b.payment_status FROM hostel_booking_details h JOIN bookings b ON b.id = h.booking_id WHERE h.booking_id = ?`, [s.bookingId]);
      expect.equal(h.pos_number, s.pos, 'hostel_booking_details.pos_number');
      expect.equal(h.payment_status, 'await_gcash', 'payment_status after the POS');
    });

    await step('The customer chooses to pay cash at the front desk', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      const pay = page.locator(`tr[data-booking-id="${s.ref}"] a.booking-action-pay`);
      await expect.visible(pay, 'no Pay now on the approved stay');
      await pay.click();
      await expect.url(page, /\/customer\/pay\?booking=/);
      await page.getByRole('button', { name: /Cash — hostel front desk/ }).click();
      await page.getByRole('button', { name: 'I will pay in cash' }).click();
      await expect.url(page, /sent=cash/, 20_000);
      await expect.text(page, 'Noted — you will pay in cash');
      const b = await dbOne(`SELECT payment_status, payment_method FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.payment_status, 'await_cash', 'payment_status');
      expect.equal(b.payment_method, 'cash', 'payment_method');
    });

    await step('The admin takes the cash ("Cash received")', async () => {
      const page = s.admin;
      await page.reload();
      await page.getByRole('button', { name: /^Cash received/ }).first().click();
      const ok = page.locator('.vz-modal [data-vz="ok"]');
      await expect.visible(ok, 'no "Cash received?" confirmation');
      await ok.click();
      await expect.text(page, /Check the guest in/, 20_000);
      const b = await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.payment_status, 'paid_cash', 'payment_status');
      const r = await dbOne(`SELECT COUNT(*) AS n FROM system_receipts WHERE booking_id = ?`, [s.bookingId]);
      expect.equal(r.n, 1, 'system_receipts rows');
    });

    await step('Records the Official Receipt from the cashier', async () => {
      const page = s.admin;
      s.or = 'OR-' + ctx.uniq.replace(/\D/g, '').slice(-7);
      await page.locator('#orNo').fill(s.or);
      await page.getByRole('button', { name: 'Record OR', exact: true }).click();
      await expect.text(page, s.or, 20_000);
      const h = await dbOne(`SELECT official_receipt_no, checked_in FROM hostel_booking_details WHERE booking_id = ?`, [s.bookingId]);
      expect.equal(h.official_receipt_no, s.or, 'official_receipt_no');
      expect.equal(h.checked_in, 0, 'checked_in (the stay has not started)');
    });

    await step('The customer sees the stay as paid', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      const row = page.locator(`tr[data-booking-id="${s.ref}"]`);
      await expect.visible(row, `${s.ref} is not listed in Booking History`);
      expect.match(await row.getAttribute('data-payment-status'), /Paid/i, 'payment status in My Bookings');
    });

    /* ================= C. staff take a venue booking at the counter ================= */

    await step('Staff open the counter from the dashboard ("+ New Booking")', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      const page = s.staff;
      await page.getByRole('link', { name: '+ New Booking' }).click();
      await expect.url(page, /\/customer\/venues\?counter=1/);
      const card = page.locator(`a.lp-card[href*="room-reservation?room=${VENUE_ROOM}"]`).first();
      await expect.visible(card, 'no card for the Heritage Function Room in counter mode');
      expect.match(await card.getAttribute('href'), /counter=1/, 'room link keeps counter mode');
      await card.click();
      await expect.url(page, new RegExp(`room-reservation\\?room=${VENUE_ROOM}&counter=1`));
      await expect.text(page, 'Counter booking');
    });

    await step('Fills in event, a free future date, hours and attendees', async () => {
      const page = s.staff;
      const room = await dbOne(`SELECT r.id, e.attendee_capacity FROM rooms r JOIN event_room_details e ON e.room_id = r.id WHERE r.room_code = ?`, [VENUE_ROOM]);
      s.venueRoomId = Number(room.id);
      const d = addDays(14);
      for (let i = 0; i < 60 && !s.venueDate; i++, d.setDate(d.getDate() + 1)) {
        const day = iso(d);
        const busy = await dbOne(`SELECT
            (SELECT COUNT(*) FROM venue_booking_slots WHERE room_id = ? AND slot_date = ? AND released_at IS NULL) +
            (SELECT COUNT(*) FROM maintenance_windows WHERE room_id = ? AND from_date <= ? AND (until_date IS NULL OR until_date >= ?)) AS n`,
          [room.id, day, room.id, day, day]);
        if (Number(busy.n) === 0) s.venueDate = day;
      }
      expect.ok(s.venueDate, 'no free date found in the next 60 days');
      s.eventName = 'Counter flow meeting ' + ctx.uniq;
      ctx.note(`venue date ${s.venueDate}, 9:00 AM – 12:00 PM`);
      await page.locator('#bk-eventName').fill(s.eventName);
      await page.locator('#bk-date').click();
      const day = page.locator(`.cal-pop button[onclick="calPick('${s.venueDate}')"]`);
      for (let i = 0; i < 4 && !(await day.isVisible()); i++) await page.locator('.cal-pop .cal-nav').last().click();
      await day.click();
      await page.locator('#bk-start').click();
      await page.locator('.tt-pop').getByRole('button', { name: '9:00 AM', exact: true }).click();
      await page.locator('#bk-end').click();
      await page.locator('.tt-pop').getByRole('button', { name: '12:00 PM', exact: true }).click();
      await page.locator('#bk-attendees').fill(String(Math.min(20, Number(room.attendee_capacity))));
      await expect.text(page, 'This slot is available');
      await page.getByRole('button', { name: /Continue — review/ }).click();
    });

    await step('Finds the customer and has them confirm with their password', async () => {
      await counterWho(ctx, s.staff);
    });

    await step('Creates the booking (no ID upload) — approved, counter staff on record', async () => {
      const page = s.staff;
      await page.locator('#agreeNoRefund').check();
      await page.getByRole('button', { name: /Create booking — approved/ }).click();
      await expect.text(page, 'Booking created and approved', 30_000);
      const refCell = page.locator('div', { hasText: /^Reference$/ }).locator('xpath=following-sibling::div[1]').first();
      s.venueRef = (await refCell.textContent())?.trim();
      expect.match(s.venueRef, /^VB-\d{4}-\d+$/, 'reference on the counter done screen');
      s.venueBookingId = Number(s.venueRef.split('-')[2]);
      ctx.note('counter venue reference ' + s.venueRef);
      const staffUser = await dbOne(`SELECT id FROM users WHERE email = ?`, [STAFF]);
      const b = await dbOne(`SELECT customer_id, room_id, booking_type, reservation_status, payment_status, updated_by_user_id FROM bookings WHERE id = ?`, [s.venueBookingId]);
      expect.ok(b, 'no bookings row for ' + s.venueRef);
      expect.equal(b.customer_id, s.customerId, 'booked for the customer');
      expect.equal(b.room_id, s.venueRoomId, 'room');
      expect.equal(b.booking_type, 'venue', 'booking_type');
      expect.equal(b.reservation_status, 'approved', 'reservation_status');
      expect.equal(b.payment_status, 'await_event', 'payment_status (refunds OFF: a venue booking post-pays)');
      expect.equal(b.updated_by_user_id, staffUser.id, 'updated_by_user_id (the counter staff)');
      const tl = await db(`SELECT action_code FROM booking_timeline WHERE booking_id = ? AND performed_by_user_id = ? ORDER BY id`, [s.venueBookingId, staffUser.id]);
      const codes = tl.map((r) => r.action_code);
      for (const c of ['counter_created', 'counter_id_checked', 'counter_identity_confirmed']) {
        expect.ok(codes.includes(c), `booking_timeline has no ${c} by ${STAFF} (has: ${codes.join(', ')})`);
      }
      const doc = await dbOne(`SELECT COUNT(*) AS n FROM booking_documents WHERE booking_id = ?`, [s.venueBookingId]);
      expect.equal(doc.n, 0, 'booking_documents rows (counter: no ID upload)');
    });

    await step('The counter booking opens in Booking Requests', async () => {
      const page = s.staff;
      await page.getByRole('link', { name: /Open the booking/ }).click();
      await expect.url(page, /\/admin\/booking-request\?id=/);
      await expect.text(page, s.venueRef);
      await expect.text(page, s.eventName);
      return page;
    });

    /* ================= D. a hostel stay for tonight at the counter ================= */

    await step('The admin opens the counter and picks Hostel Room 2', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/dashboard'));
      await page.getByRole('link', { name: '+ New Booking' }).click();
      await expect.url(page, /\/customer\/venues\?counter=1/);
      const card = page.locator(`#hostel-listings a.lp-card[href*="hostel-reservation?room=${COUNTER_HOSTEL_ROOM}"]`);
      await expect.visible(card, 'no card for Hostel Room 2 in counter mode');
      await card.click();
      await expect.url(page, new RegExp(`hostel-reservation\\?room=${COUNTER_HOSTEL_ROOM}&counter=1`));
      await expect.text(page, 'Counter booking');
    });

    await step('Tonight, one bed, one named guest', async () => {
      const page = s.admin;
      s.tonight = iso(addDays(0));
      s.tomorrow = iso(addDays(1));
      await pickHostelDate(page, 'checkIn', s.tonight);
      await pickHostelDate(page, 'checkOut', s.tomorrow);
      await page.locator('.bed-name').first().fill(CUSTOMER_NAME);
      await page.locator('.bed-row').first().getByRole('button', { name: 'Male', exact: true }).click();
      await expect.text(page, '1 night · 1 bed');
      await page.locator('#hbGo').click();
    });

    await step('Finds the customer and has them confirm with their password', async () => {
      await counterWho(ctx, s.admin);
    });

    await step('Takes the cash and books the stay — paid on creation', async () => {
      const page = s.admin;
      await page.locator('#agreeNoRefund').check();
      await page.getByRole('button', { name: /Cash received — book it/ }).click();
      const ok = page.locator('.vz-modal [data-vz="ok"]');
      await expect.visible(ok, 'no "Cash received?" confirmation');
      await ok.click();
      await expect.text(page, 'Stay booked and paid', 30_000);
      const refCell = page.locator('div', { hasText: /^Reference$/ }).locator('xpath=following-sibling::div[1]').first();
      s.stayRef = (await refCell.textContent())?.trim();
      expect.match(s.stayRef, /^VB-\d{4}-\d+$/, 'reference on the counter done screen');
      s.stayId = Number(s.stayRef.split('-')[2]);
      ctx.note('counter hostel reference ' + s.stayRef);
      const adminUser = await dbOne(`SELECT id FROM users WHERE email = 'admin@gmail.com'`);
      const b = await dbOne(`SELECT b.customer_id, b.booking_type, b.reservation_status, b.payment_status, b.payment_method,
            h.check_in_date, h.check_out_date FROM bookings b JOIN hostel_booking_details h ON h.booking_id = b.id WHERE b.id = ?`, [s.stayId]);
      expect.ok(b, 'no hostel booking row for ' + s.stayRef);
      expect.equal(b.customer_id, s.customerId, 'booked for the customer');
      expect.equal(b.reservation_status, 'approved', 'reservation_status');
      expect.equal(b.payment_status, 'paid_cash', 'payment_status');
      expect.equal(b.check_in_date, s.tonight, 'check-in date (tonight)');
      const tl = await dbOne(`SELECT COUNT(*) AS n FROM booking_timeline WHERE booking_id = ? AND action_code = 'counter_created' AND performed_by_user_id = ?`, [s.stayId, adminUser.id]);
      expect.equal(tl.n, 1, 'counter_created timeline row by the admin');
      const r = await dbOne(`SELECT COUNT(*) AS n FROM system_receipts WHERE booking_id = ?`, [s.stayId]);
      expect.equal(r.n, 1, 'system_receipts rows');
    });

    await step('Opens the stay and checks the guest in', async () => {
      const page = s.admin;
      await page.getByRole('link', { name: 'Open the booking' }).click();
      await expect.url(page, /\/admin\/booking-request\?id=/);
      await page.getByRole('button', { name: 'Check the guest in' }).click();
      await expect.text(page, /Guest checked in/, 20_000);
      const h = await dbOne(`SELECT checked_in FROM hostel_booking_details WHERE booking_id = ?`, [s.stayId]);
      expect.equal(h.checked_in, 1, 'checked_in');
      return page;
    });

    await step('Both counter bookings are in the customer\'s Booking History', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      for (const ref of [s.venueRef, s.stayRef]) {
        await expect.visible(page.locator(`tr[data-booking-id="${ref}"]`), `${ref} is not listed in the customer's Booking History`);
      }
      return page;
    });
  },
};
