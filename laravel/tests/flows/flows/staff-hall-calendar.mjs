// What a staff member of one hall really sees (DB-DECISIONS 28): no halls → the note on every
// page; one hall → the other halls' days are plain "Busy" on the calendar, with nothing to
// click and no customer in the page. Snapshot: r2 (hall 2) has a booking on 2026-10-09.

const ADMIN = 'francishubero@gmail.com';
const STAFF = 'staff@gmail.com';
const NOTE = 'You have no halls yet. Ask your admin to assign you one.';

export default {
  name: 'staff-hall-calendar',
  title: 'Staff of one hall: the no-halls note and the Busy calendar, in a real browser',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    const setHalls = async (ids) => {
      const out = await s.admin.evaluate(async ([u, d]) => {
        const res = await Live.post(u, d);
        return res;
      }, [ctx.url('/admin/staff-save'), { action: 'set_halls', user_id: s.id, hall_ids: JSON.stringify(ids) }]);
      expect.ok(out.ok, 'set_halls refused: ' + JSON.stringify(out));
    };

    await step('The Bahay admin takes every hall from the staff member', async () => {
      s.id = (await dbOne('SELECT id FROM users WHERE email = ?', [STAFF])).id;
      s.admin = await ctx.loginAdmin(ADMIN);
      await s.admin.goto(ctx.url('/admin/staff'));
      await setHalls([]);
      return s.admin;
    });

    await step('With no halls they sign in and every page says why it is empty', async () => {
      const page = s.staff = await ctx.loginStaff(STAFF);
      for (const path of ['/admin/dashboard', '/admin/booking-requests', '/admin/calendar', '/admin/venue-management', '/admin/transaction-history', '/customer/venues?counter=1']) {
        await page.goto(ctx.url(path));
        await expect.visible(page.getByText(NOTE).first(), 'no note on ' + path);
      }
      await page.goto(ctx.url('/admin/calendar'));
      return page;
    });

    await step('Given one hall, the note goes and the other hall shows as Busy', async () => {
      await setHalls([1]);
      const page = s.staff;
      await page.goto(ctx.url('/admin/calendar'));
      expect.equal(await page.getByText(NOTE).count(), 0, 'the note is still there');
      for (let i = 0; i < 240 && !(await page.locator('[data-date="2026-10-09"]').count()); i++) {
        await page.locator('.fc-prev-button').click();
      }
      const day = page.locator('[data-date="2026-10-09"]');
      await expect.visible(day.locator('.fc-event', { hasText: 'Busy' }), 'no Busy entry on the other hall\'s day');
      return page;
    });

    await step('A Busy entry opens nothing and carries no booking', async () => {
      const page = s.staff;
      const before = page.url();
      const busy = page.locator('[data-date="2026-10-09"] .fc-event', { hasText: 'Busy' }).first();
      expect.equal((await busy.innerText()).replace(/\s+/g, ' ').trim().replace(/^\S*\s?(?=Busy)/, ''), 'Busy', 'the entry text');
      expect.equal(await busy.evaluate((e) => e.closest('a')?.getAttribute('href') ?? ''), '', 'the entry links somewhere');
      await busy.click();
      expect.equal(page.url(), before, 'clicking moved the page');
      const b = await dbOne(`SELECT c.full_name, r.name AS room FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN rooms r ON r.id = b.room_id WHERE b.id = 33`);
      const html = await page.content();
      expect.ok(!html.includes(b.full_name), 'the customer is in the page');
      expect.ok(!html.includes(b.room), 'the room is in the page');
      return page;
    });
  },
};
