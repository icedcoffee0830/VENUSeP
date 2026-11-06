// My bookings at the in-between desktop widths: no sideways scroll, on the page or inside the
// table's own box. The table layout starts at 1280px; below that each booking is a card.

const WIDTHS = [1001, 1040, 1080, 1120, 1160, 1200, 1240, 1279, 1280, 1366];

export default {
  name: 'my-bookings-widths',
  title: 'My bookings: no sideways scroll from 1001px to 1280px, and the badges look right',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('A customer with an overdue booking opens My bookings', async () => {
      const c = await dbOne(`SELECT u.email FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
                              WHERE b.payment_status = 'overdue' AND u.totp_secret IS NULL GROUP BY u.email ORDER BY COUNT(*) DESC LIMIT 1`);
      expect.ok(c, 'no customer with an overdue booking');
      s.page = await ctx.loginCustomer(c.email);
      await s.page.goto(ctx.url('/customer/booking-history'));
      await s.page.locator('#booking-history-entries').selectOption('50');
      return s.page;
    });

    await step('No sideways scroll at any width', async () => {
      const page = s.page;
      const bad = [];
      for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        const m = await page.evaluate(() => {
          const box = document.querySelector('.booking-table-scroll');
          const root = document.documentElement;
          return { page: root.scrollWidth - root.clientWidth, table: box.scrollWidth - box.clientWidth };
        });
        if (m.page > 0 || m.table > 0) bad.push(`${width}px: page +${m.page}, table +${m.table}`);
      }
      expect.equal(bad.join(' | ') || 'none', 'none', 'sideways scroll');
      return page;
    }, { timeout: 60_000 });

    await step('The badges stay whole in their cells', async () => {
      const page = s.page;
      const bad = [];
      for (const width of [1001, 1280, 390]) {
        await page.setViewportSize({ width, height: 900 });
        const n = await page.evaluate(() => [...document.querySelectorAll('tr[data-booking-row]:not([hidden]) .booking-badge')].filter((b) => {
          const cell = b.closest('td').getBoundingClientRect(), r = b.getBoundingClientRect();
          return r.width === 0 || r.left < cell.left - 1 || r.right > cell.right + 1;
        }).length);
        if (n) bad.push(`${width}px: ${n} badges outside their cell`);
      }
      expect.equal(bad.join(' | ') || 'none', 'none', 'badges');
      await page.setViewportSize({ width: 1280, height: 900 });
      return page;
    });

    await step('At a laptop width each booking is a card', async () => {
      await s.page.locator('#booking-history-entries').selectOption('10');
      await s.page.setViewportSize({ width: 1100, height: 900 });
      expect.ok(!(await s.page.locator('.booking-history-table thead').isVisible()), 'the table header still shows at 1100px');
      return s.page;
    });

    await step('Badges at desktop width', async () => {
      await s.page.setViewportSize({ width: 1366, height: 900 });
      expect.ok(await s.page.locator('.booking-history-table thead').isVisible(), 'no table at 1366px');
      return s.page;
    });

    await step('Badges at phone width', async () => {
      await s.page.setViewportSize({ width: 390, height: 844 });
      return s.page;
    });
  },
};
