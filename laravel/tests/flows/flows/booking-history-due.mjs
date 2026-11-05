// Booking History's "Due payments" tab: awaiting payment + overdue, nothing else.

export default {
  name: 'booking-history-due',
  title: 'Booking History: the Due payments tab lists what the customer still owes',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    const DUE = `b.payment_status IN ('await_gcash', 'await_cash', 'await_pos', 'overdue') AND b.reservation_status <> 'cancelled'`;

    await step('A customer with something due signs in', async () => {
      const c = await dbOne(`SELECT u.email FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
                              WHERE ${DUE} AND u.totp_secret IS NULL GROUP BY u.email ORDER BY COUNT(*) DESC LIMIT 1`);
      expect.ok(c, 'no customer with a due payment in the snapshot');
      s.email = c.email;
      s.page = await ctx.loginCustomer(s.email);
      return s.page;
    });

    await step('The tab shows exactly the due rows', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/booking-history'));
      await page.getByRole('tab', { name: 'Due payments' }).click();
      /* counted after the page load: opening it runs the deadline housekeeping */
      const n = await dbOne(`SELECT COUNT(*) AS n FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
                              WHERE u.email = ? AND ${DUE}`, [s.email]);
      expect.ok(Number(n.n) > 0, 'nothing due any more');
      expect.match(await page.locator('#booking-history-info').textContent(), new RegExp(`of ${n.n} entries$`), 'the count under the table');
      const badges = await page.locator('tr[data-booking-row]:not([hidden])').evaluateAll((rows) => rows.map((r) => r.dataset.due));
      expect.ok(badges.length > 0 && badges.every((d) => d === '1'), 'a row that is not due is showing');
      return page;
    });

    await step('All brings every booking back', async () => {
      const page = s.page;
      await page.getByRole('tab', { name: 'All', exact: true }).click();
      const n = await dbOne(`SELECT COUNT(*) AS n FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id WHERE u.email = ?`, [s.email]);
      expect.match(await page.locator('#booking-history-info').textContent(), new RegExp(`of ${n.n} entries$`), 'the count under the table');
      return page;
    });
  },
};
