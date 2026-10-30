// cbook — the USeP discount, from the customer's claim to the snapshot on the booking
// (DB-DECISIONS #2/#3: the claim is not the discount; staff grant it at approval,
// and the % is snapshotted onto the booking, so a later rate change never rewrites it).
// A. a customer books the Heritage Function Room claiming affiliation:
//    the review shows the provisional 20% price, the booking stores the FULL price.
// B. staff approve WITH the discount: 20% and the discounted total are stored, and
//    equal what the customer was shown.
// C. the admin changes the rate to 25%: the approved booking keeps 20% and its total;
//    My Bookings still shows the snapshot.

const ROOM = 'r2';                            // Heritage Function Room, Bahay Alumni (staff@gmail.com's venue)
const CUSTOMER = 'nikkaarena@gmail.com';     // no overdue booking (one blocks new bookings); the claim is a checkbox
const STAFF = 'staff@gmail.com';

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const peso = (n) => '₱' + Number(n).toLocaleString('en-PH');

export default {
  name: 'cbook-discount',
  title: 'USeP discount: claimed at booking, granted at approval, snapshotted against a later rate change',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('A USeP customer books the Heritage Function Room claiming affiliation', async () => {
      s.cust = await ctx.loginCustomer(CUSTOMER);
      const page = s.cust;
      const room = await dbOne(`SELECT r.id, e.fee_per_day FROM rooms r JOIN event_room_details e ON e.room_id = r.id WHERE r.room_code = ?`, [ROOM]);
      s.fee = Number(room.fee_per_day);
      s.pct = Number((await dbOne(`SELECT setting_value AS v FROM system_settings WHERE setting_key = 'discount_percent'`)).v);
      const d = new Date(); d.setDate(d.getDate() + 21);
      for (let i = 0; i < 60 && !s.day; i++, d.setDate(d.getDate() + 1)) {
        const day = iso(d);
        const busy = await dbOne(`SELECT
            (SELECT COUNT(*) FROM venue_booking_slots WHERE room_id = ? AND slot_date = ? AND released_at IS NULL) +
            (SELECT COUNT(*) FROM maintenance_windows WHERE room_id = ? AND from_date <= ? AND (until_date IS NULL OR until_date >= ?)) AS n`,
          [room.id, day, room.id, day, day]);
        if (Number(busy.n) === 0) s.day = day;
      }
      expect.ok(s.day, 'no free date in the next 60 days');
      s.expected = s.fee - Math.round(s.fee * s.pct) / 100;
      ctx.note(`date ${s.day}, fee ${peso(s.fee)}, ${s.pct}% → ${peso(s.expected)}`);

      await page.goto(ctx.url(`/customer/room-reservation?room=${ROOM}`));
      await page.locator('#bk-eventName').fill('Discount test ' + ctx.uniq);
      await page.locator('#bk-date').click();
      const btn = page.locator(`.cal-pop button[onclick="calPick('${s.day}')"]`);
      for (let i = 0; i < 4 && !(await btn.isVisible()); i++) await page.locator('.cal-pop .cal-nav').last().click();
      await btn.click();
      await page.locator('#bk-start').click();
      await page.locator('.tt-pop').getByRole('button', { name: '8:00 AM', exact: true }).click();
      await page.locator('#bk-end').click();
      await page.locator('.tt-pop').getByRole('button', { name: '5:00 PM', exact: true }).click();
      await page.locator('#bk-attendees').fill('20');
      await expect.text(page, 'This slot is available');
      await page.getByRole('button', { name: /Continue — review/ }).click();
      await page.getByText('I am USeP-affiliated', { exact: true }).click();
      await expect.text(page, peso(s.expected));                      // the provisional USeP price
      await page.locator('#idFileInput').setInputFiles(await ctx.fixtures.idImage('Juan Miguel Dela Cruz'));
      await page.locator('#agreeNoRefund').check();
      await page.getByRole('button', { name: 'Submit booking request' }).click();
      await expect.text(page, 'Request submitted — awaiting approval', 30_000);
      const refCell = page.locator('div', { hasText: /^Reference$/ }).locator('xpath=following-sibling::div[1]').first();
      s.ref = (await refCell.textContent())?.trim();
      s.bookingId = Number(s.ref.split('-')[2]);
      const b = await dbOne(`SELECT is_usep_affiliated, affiliation_verified, room_price, discount_percent, discount_amount, total_amount FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.is_usep_affiliated, 1, 'is_usep_affiliated (the claim)');
      expect.equal(b.affiliation_verified, 0, 'affiliation_verified before staff decide');
      expect.equal(Number(b.room_price), s.fee, 'room_price');
      expect.equal(Number(b.discount_percent), 0, 'discount_percent before approval');
      expect.equal(Number(b.total_amount), s.fee, 'total_amount before approval (full price)');
      return page;
    });

    await step('Staff approve with the discount: the stored total is what the customer was shown', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      const page = s.staff;
      await page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(s.ref)));
      await page.getByRole('button', { name: `Approve with ${s.pct}% discount` }).click();
      await expect.text(page, /ID \+ reservation approved|Reservation approved/, 20_000);
      const b = await dbOne(`SELECT reservation_status, affiliation_verified, discount_percent, discount_amount, total_amount FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.reservation_status, 'approved', 'reservation_status');
      expect.equal(b.affiliation_verified, 1, 'affiliation_verified');
      expect.equal(Number(b.discount_percent), s.pct, 'discount_percent snapshot');
      expect.equal(Number(b.discount_amount), s.fee - s.expected, 'discount_amount');
      expect.equal(Number(b.total_amount), s.expected, 'total_amount');
      return page;
    });

    await step('The admin changes the rate: the approved booking keeps its snapshot', async () => {
      const page = await ctx.loginAdmin('admin@gmail.com');
      s.newPct = s.pct === 25 ? 30 : 25;
      await page.goto(ctx.url('/admin/venue-management'));
      await page.locator('#dcOpen').click();
      await page.locator('#dcRate').fill(String(s.newPct));
      await page.locator('#dcReason').fill('Flow discount snapshot test ' + ctx.uniq);
      const reloaded = page.waitForEvent('load').then(() => 'reloaded', () => 'timeout');
      await page.locator('#dcForm').getByRole('button', { name: 'Save', exact: true }).click();
      expect.equal(await reloaded, 'reloaded', 'the rate save');
      const set = await dbOne(`SELECT setting_value AS v FROM system_settings WHERE setting_key = 'discount_percent'`);
      expect.equal(Number(set.v), s.newPct, 'live discount_percent');
      const b = await dbOne(`SELECT discount_percent, total_amount FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(Number(b.discount_percent), s.pct, 'the booking\'s discount_percent after the rate change');
      expect.equal(Number(b.total_amount), s.expected, 'the booking\'s total after the rate change');
      return page;
    });

    await step('My Bookings still shows the snapshot total', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      const row = page.locator(`tr[data-booking-id="${s.ref}"]`);
      await expect.visible(row, `${s.ref} is not in My Bookings`);
      expect.equal(Number(await row.getAttribute('data-amount')), s.expected, 'data-amount in My Bookings');
      return page;
    });
  },
};
