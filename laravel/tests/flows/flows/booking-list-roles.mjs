// Booking Management as each role really sees it: every page of the All tab draws, with
// written-off bookings in the list (one such row used to blank the page it was on).

const BAHAY = 1, HOSTEL = 3;
const ROLES = [
  { who: 'the super admin', email: 'admin@gmail.com', login: 'admin', offices: [1, 2, 3] },
  { who: 'the CEDU admin', email: 'cedu.admin@gmail.com', login: 'admin', offices: [2, 3] },
  { who: 'the Bahay admin', email: 'francishubero@gmail.com', login: 'admin', offices: [1] },
  { who: 'hostel staff', email: 'jakemerson@gmail.com', login: 'staff', offices: [3] },
];

export default {
  name: 'booking-list-roles',
  title: 'Booking Management: every role sees its whole list, written-off bookings included',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    const refOf = (b) => `VB-${b.y}-${String(b.id).padStart(3, '0')}`;
    const post = (page, data) => page.evaluate(async ([u, d]) => {
      const res = await fetch(u, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _token: CSRF, ...d }) });
      return res.status;
    }, [ctx.url('/admin/booking-action'), data]);

    await step('The super admin writes off an overdue booking at Bahay Alumni and at the hostel', async () => {
      s.admin = await ctx.loginAdmin('admin@gmail.com');
      for (const venue of [BAHAY, HOSTEL]) {
        const b = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y FROM bookings b JOIN rooms r ON r.id = b.room_id
                                WHERE r.venue_id = ? AND b.payment_status = 'overdue' AND b.reservation_status <> 'cancelled' ORDER BY b.id DESC LIMIT 1`, [venue]);
        expect.ok(b, 'no overdue booking at venue ' + venue);
        await s.admin.goto(ctx.url('/admin/booking-request?id=' + refOf(b)));
        expect.equal(await post(s.admin, { booking: refOf(b), action: 'write_off_request', reason: 'moved_away', note: 'The organizer left the school' }), 200, 'write-off request');
        expect.equal(await post(s.admin, { booking: refOf(b), action: 'write_off_approve' }), 200, 'write-off approval');
        const after = await dbOne(`SELECT payment_status AS p FROM bookings WHERE id = ?`, [b.id]);
        expect.equal(after.p, 'written_off', 'payment_status');
      }
      return s.admin;
    });

    for (const role of ROLES) {
      await step(`${role.who}: the All tab shows every booking, on every page`, async () => {
        const page = role.email === 'admin@gmail.com' ? s.admin
          : role.login === 'admin' ? await ctx.loginAdmin(role.email) : await ctx.loginStaff(role.email);
        await page.goto(ctx.url('/admin/booking-requests'));
        /* staff: their halls only, and no tab to the whole office (DB-DECISIONS 28) */
        expect.equal(await page.getByRole('button', { name: 'Whole office' }).count(), 0, 'Whole office tabs');
        const halls = role.login === 'staff' ? `AND fn_staff_on_room((SELECT id FROM users WHERE email = '${role.email}'), r.id)` : '';
        const n = await dbOne(`SELECT COUNT(*) AS n FROM bookings b JOIN rooms r ON r.id = b.room_id
                                WHERE r.venue_id IN (${role.offices.join(',')}) ${halls}
                                  AND NOT (b.reservation_status = 'completed' AND b.payment_status IN ('confirmed', 'paid_cash'))`);
        const total = Number(n.n);
        expect.ok(total > 0, 'nothing to list for ' + role.who);
        /* the first draw, as the page opened: one bad row used to leave this empty */
        const first = await page.locator('#brRows a.br-row').count();
        const pages = Math.ceil(total / 10);
        const blank = [];
        let seen = 0;
        for (let p = 1; p <= pages; p++) {
          const drawn = await page.evaluate((target) => {
            try { setPage(target); } catch (e) { return -1; }
            return document.querySelectorAll('#brRows a.br-row').length;
          }, p);
          if (drawn <= 0) blank.push(p); else seen += drawn;
        }
        ctx.note(`${role.who}: first draw ${first} rows; ${seen} of ${total} rows drawn on ${pages} pages`);
        expect.ok(first > 0, role.who + ': the list opened empty');
        expect.equal(blank.length ? `blank pages ${blank.join(', ')} of ${pages}` : 'none', 'none', role.who);
        expect.equal(seen, total, role.who + ': rows drawn');
        return page;
      }, { timeout: 120_000 });
    }

    await step('Transaction History: the filter finds the write-offs, each a grey chip inside its cell, staff words wrapping', async () => {
      const page = s.admin;
      await page.setViewportSize({ width: 1280, height: 800 });
      await page.goto(ctx.url('/admin/transaction-history'));
      await page.locator('#payment-status-filter').selectOption('Written off');
      const chip = page.locator('.tabulator-row .vz-chip', { hasText: 'Written off' }).first();
      await chip.waitFor();
      expect.ok((await chip.getAttribute('class')).includes('vz-chip-grey'), 'the chip is not grey');
      await page.locator('#payment-status-filter').selectOption('');
      await page.locator('.vz-ps input').fill('100');
      await page.locator('.vz-ps input').press('Enter');
      await page.waitForFunction(() => document.querySelectorAll('.tabulator-row').length > 10);
      const clipped = await page.evaluate(() => [...document.querySelectorAll('.tabulator-cell .vz-chip')].filter((c) => {
        const cell = c.closest('.tabulator-cell').getBoundingClientRect(), r = c.getBoundingClientRect();
        return r.right > cell.right + 1 || r.bottom > cell.bottom + 1;
      }).map((c) => c.textContent));
      expect.equal(clipped.join(', ') || 'none', 'none', 'chips cut off by their cell');
      return page;
    });
  },
};
