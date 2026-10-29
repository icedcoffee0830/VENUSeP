// Flow 1 — a new customer books an event room and ends up with a System Receipt.
// register → sign in → venues → room page (date, hours, attendees) → valid ID →
// submit → My Bookings → staff approve → customer pays by GCash (receipt upload,
// in-browser OCR) → staff confirm → customer receipt page + PDF → admin sees it.
//
// Pre-pay needs refunds ON (DB-DECISIONS #18): with refunds OFF (the snapshot's
// default) payment only opens after the event, so it could not be paid today.
// The room is at Bahay Alumni (venue 1) because staff@gmail.com handles venue 1.

const ROOM_CODE = 'r1';                       // Alumni Grand Ballroom, Bahay Alumni
const STAFF = 'staff@gmail.com';

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export default {
  name: 'venue-booking-to-receipt',
  title: 'Venue booking → approval → GCash payment → System Receipt',
  settings: { refunds_enabled: '1' },

  async run(ctx) {
    const { step, expect, db, dbOne } = ctx;
    const s = ctx.state;
    s.email = `flow.${ctx.uniq.replace(/\D/g, '')}@example.com`;
    s.name = 'Flow Tester';
    s.eventName = 'Flow test colloquium ' + ctx.uniq;

    await step('A new customer registers', async () => {
      const page = await ctx.newPage('new customer');
      await page.goto(ctx.url('/customer/customer-register'));
      await page.locator('#fullName').fill(s.name);
      await page.locator('#email').fill(s.email);
      await page.locator('#contactNumber').fill('09171112233');
      await page.locator('#password').fill(ctx.password);
      await page.locator('#confirmPassword').fill(ctx.password);
      await page.locator('#terms').check();
      await page.getByRole('button', { name: 'Create Account' }).click();
      await expect.text(page, 'Account created successfully');
      await expect.url(page, /\/customer\/customer-login/);
      const u = await dbOne(`SELECT u.account_type, c.full_name, c.phone FROM users u JOIN customers c ON c.user_id = u.id WHERE u.email = ?`, [s.email]);
      expect.ok(u, 'no users/customers row for the new account');
      expect.equal(u.account_type, 'customer', 'account type');
      expect.equal(u.full_name, s.name, 'customer name');
      return page;
    });

    await step('The customer signs in', async () => {
      s.cust = await ctx.loginCustomer(s.email);
      await expect.url(s.cust, /\/customer\/venues/);
      return s.cust;
    });

    await step('Opens the Alumni Grand Ballroom from the venues page', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/venues'));
      const card = page.locator(`a.lp-card[href*="room-reservation?room=${ROOM_CODE}"]`).first();
      await expect.visible(card, 'no card for the Alumni Grand Ballroom on /customer/venues');
      await card.click();
      await expect.url(page, new RegExp(`room-reservation\\?room=${ROOM_CODE}`));
      await expect.text(page, 'Reserve this room');
    });

    await step('Fills in event, a free future date, hours and attendees', async () => {
      const page = s.cust;
      const room = await dbOne(`SELECT id, attendee_capacity FROM rooms WHERE room_code = ?`, [ROOM_CODE]);
      // the first day from two weeks out with nothing held in this room and no maintenance
      const d = new Date(); d.setDate(d.getDate() + 14);
      for (let i = 0; i < 60; i++, d.setDate(d.getDate() + 1)) {
        const day = iso(d);
        const busy = await dbOne(`SELECT
            (SELECT COUNT(*) FROM venue_booking_slots WHERE room_id = ? AND slot_date = ? AND released_at IS NULL) +
            (SELECT COUNT(*) FROM maintenance_windows WHERE room_id = ? AND from_date <= ? AND (until_date IS NULL OR until_date >= ?)) AS n`,
          [room.id, day, room.id, day, day]);
        if (Number(busy.n) === 0) { s.date = day; break; }
      }
      expect.ok(s.date, 'no free date found in the next 60 days');
      ctx.note(`date ${s.date}, 9:00 AM – 1:00 PM`);

      await page.locator('#bk-eventName').fill(s.eventName);
      await page.locator('#bk-date').click();
      const day = page.locator(`.cal-pop button[onclick="calPick('${s.date}')"]`);
      for (let i = 0; i < 4 && !(await day.isVisible()); i++) await page.locator('.cal-pop .cal-nav').last().click();
      await day.click();
      await page.locator('#bk-start').click();
      await page.locator('.tt-pop').getByRole('button', { name: '9:00 AM', exact: true }).click();
      await page.locator('#bk-end').click();
      await page.locator('.tt-pop').getByRole('button', { name: '1:00 PM', exact: true }).click();
      await page.locator('#bk-attendees').fill(String(Math.min(50, Number(room.attendee_capacity))));
      await expect.text(page, 'This slot is available');
      await page.getByRole('button', { name: /Continue — review/ }).click();
      await expect.text(page, 'Valid ID');
    });

    await step('Attaches a valid ID and submits the request', async () => {
      const page = s.cust;
      await page.getByText('Not affiliated', { exact: true }).click();
      await page.locator('#idFileInput').setInputFiles(await ctx.fixtures.idImage(s.name));
      await expect.text(page, 'Reviewed by staff together with your reservation');
      await page.getByRole('button', { name: 'Submit booking request' }).click();
      await expect.text(page, 'Request submitted — awaiting approval', 30_000);
      const refCell = page.locator('div', { hasText: /^Reference$/ }).locator('xpath=following-sibling::div[1]').first();
      s.ref = (await refCell.textContent())?.trim();
      expect.match(s.ref, /^VB-\d{4}-\d+$/, 'reference on the confirmation screen');
      s.bookingId = Number(s.ref.split('-')[2]);
      ctx.note('reference ' + s.ref);
      const b = await dbOne(`SELECT b.reservation_status, b.payment_status, b.refunds_allowed, u.email
          FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id WHERE b.id = ?`, [s.bookingId]);
      expect.ok(b, 'no bookings row for ' + s.ref);
      expect.equal(b.email, s.email, 'booking owner');
      expect.equal(b.reservation_status, 'pending', 'reservation_status');
      expect.equal(b.payment_status, 'locked', 'payment_status');
      expect.equal(b.refunds_allowed, 1, 'refunds_allowed snapshot');
      const doc = await dbOne(`SELECT COUNT(*) AS n FROM booking_documents WHERE booking_id = ?`, [s.bookingId]);
      expect.ok(Number(doc.n) >= 1, 'the valid ID was not stored (no booking_documents row)');
    });

    await step('The booking is in My Bookings as Pending', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      const row = page.locator(`tr[data-booking-id="${s.ref}"]`);
      await expect.visible(row, `${s.ref} is not listed in Booking History`);
      expect.match(await row.getAttribute('data-status'), /Pending/i, 'booking status in the list');
    });

    await step('Staff sign in and approve the ID and reservation', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      const page = s.staff;
      await page.goto(ctx.url('/admin/booking-requests'));
      await page.locator('#brSearch').fill(s.ref);
      const row = page.locator('a.br-row', { hasText: s.ref });
      await expect.visible(row, `${s.ref} is not in the staff Booking Requests list`);
      await row.click();
      await expect.url(page, /\/admin\/booking-request\?id=/);
      await page.getByRole('button', { name: /Approve ID & reservation/ }).click();
      await expect.text(page, /ID \+ reservation approved|Reservation approved/, 20_000);
      const b = await dbOne(`SELECT reservation_status, payment_status FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.reservation_status, 'approved', 'reservation_status');
      expect.equal(b.payment_status, 'await_gcash', 'payment_status after approval (pre-pay)');
    });

    await step('The customer pays by GCash and uploads the receipt', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      await page.locator(`tr[data-booking-id="${s.ref}"] a.booking-action-pay`).click();
      await expect.url(page, /\/customer\/pay\?booking=/);
      const b = await dbOne(`SELECT total_amount FROM bookings WHERE id = ?`, [s.bookingId]);
      const acct = await dbOne(`SELECT g.account_name, g.mobile_number FROM v_gcash_active_accounts g
          JOIN rooms r ON r.venue_id = g.venue_id WHERE r.id = (SELECT room_id FROM bookings WHERE id = ?)`, [s.bookingId]);
      expect.ok(acct, 'no active GCash account for this venue');
      const ref13 = String(1000000000000 + (Date.now() % 9000000000000));
      const receipt = await ctx.fixtures.gcashReceipt({ amount: b.total_amount, name: acct.account_name, number: acct.mobile_number, ref: ref13 });
      await page.locator('label.pay-agree input[type="checkbox"]').check();
      await page.locator('#gcFile').setInputFiles(receipt);
      // the OCR runs in the browser (Tesseract from a CDN); it can take a while
      const verdict = page.getByText(/Receipt looks right|A coordinator will check this receipt|This receipt cannot be accepted/).first();
      await expect.visible(verdict, 'the receipt check never finished', 120_000);
      s.browserVerdict = (await verdict.textContent()).trim();
      ctx.note('in-browser verdict: ' + s.browserVerdict);
      expect.ok(!/cannot be accepted/.test(s.browserVerdict), 'the browser refused the receipt: ' + s.browserVerdict);
      await page.getByRole('button', { name: 'Submit receipt' }).click();
      await expect.url(page, /sent=(accepted|manual_review)/, 30_000);
      const after = await dbOne(`SELECT b.payment_status, r.verdict FROM bookings b
          LEFT JOIN gcash_receipts r ON r.booking_id = b.id WHERE b.id = ? ORDER BY r.id DESC LIMIT 1`, [s.bookingId]);
      expect.equal(after.payment_status, 'under_review', 'payment_status after the upload');
      ctx.note('server verdict: ' + after.verdict);
    }, { timeout: 180_000 });

    await step('Staff confirm the payment', async () => {
      const page = s.staff;
      await page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(s.ref)));
      await page.getByRole('button', { name: 'Confirm payment' }).click();
      await expect.text(page, 'Payment confirmed', 20_000);
      const b = await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.payment_status, 'confirmed', 'payment_status');
      const r = await dbOne(`SELECT COUNT(*) AS n FROM system_receipts WHERE booking_id = ?`, [s.bookingId]);
      expect.ok(Number(r.n) === 1, 'expected one system_receipts row, found ' + r.n);
    });

    await step('The customer opens the System Receipt', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      const row = page.locator(`tr[data-booking-id="${s.ref}"]`);
      expect.match(await row.getAttribute('data-payment-status'), /Paid/i, 'payment status in My Bookings');
      await row.locator('a.booking-action-receipt').click();
      await expect.url(page, /\/customer\/receipt\?booking=/);
      await expect.visible(page.getByRole('heading', { name: 'System Receipt' }), 'no "System Receipt" heading');
      await expect.text(page, s.ref);
    });

    await step('The receipt PDF downloads', async () => {
      const page = s.cust;
      const link = page.getByRole('link', { name: 'Download PDF' });
      await expect.visible(link, 'no Download PDF link');
      const res = await page.context().request.get(new URL(await link.getAttribute('href'), page.url()).href);
      expect.equal(res.status(), 200, 'PDF status');
      expect.match(res.headers()['content-type'] || '', /^application\/pdf/, 'PDF content-type');
      const body = await res.body();
      expect.equal(body.subarray(0, 4).toString('latin1'), '%PDF', 'PDF magic bytes');
      ctx.note(`PDF ${body.length} bytes`);
      return page;
    });

    await step('An admin signs in (with the authenticator code) and finds the paid booking', async () => {
      const page = await ctx.loginAdmin('admin@gmail.com');
      await page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(s.ref)));
      await expect.text(page, s.ref);
      await expect.text(page, /Payment confirmed/);
      return page;
    });
  },
};
