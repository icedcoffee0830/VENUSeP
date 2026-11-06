// The customer's phone tab bar: on the account pages only, phone width only. The tabs go where
// they say, the current one is marked, nothing sits behind the bar, and the side menu is gone.
// The red dot rule itself is TabBarDotTest; here: the dot reaches the Bookings tab. The receipt
// view needs a real receipt, so venue-booking-to-receipt checks the bar there.

const PHONE = { width: 390, height: 844 };
const ON = [
  ['/customer/booking-history', 'bookings'],
  ['/customer/transaction-history', 'payments'],
  ['/customer/calendar', 'calendar'],
  ['/customer/customer-profile', 'me'],
  ['/customer/faq?in=app', 'me'],
];
const OFF = ['/customer/venues', '/customer/room-reservation?room=r1', '/customer/hostel-reservation?room=h1', '/customer/faq', '/terms'];

/* Scrolled to the very end: where the page's last visible thing stops, against the bar's top. */
const atTheEnd = (page) => page.evaluate(() => {
  window.scrollTo(0, document.documentElement.scrollHeight);
  const barTop = document.querySelector('.ct-bar').getBoundingClientRect().top;
  const kids = [...document.querySelectorAll('.app-main *')].filter((e) => e.getClientRects().length && getComputedStyle(e).position !== 'fixed');
  const end = Math.max(...kids.map((e) => e.getBoundingClientRect().bottom));
  return { barTop, end, sideways: document.documentElement.scrollWidth > innerWidth };
});

export default {
  name: 'phone-tab-bar',
  title: 'Phone tab bar: shows on the right pages, links work, nothing hides behind it',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    const bar = (page) => page.locator('.ct-bar');
    const tab = (page, name) => page.locator(`.ct-bar a[data-tab="${name}"]`);

    await step('A customer with a payment due signs in on a phone', async () => {
      const c = await dbOne(`SELECT u.email FROM notifications n JOIN bookings b ON b.id = n.booking_id JOIN users u ON u.id = n.user_id
                              WHERE u.account_type = 'customer' AND u.totp_secret IS NULL
                                AND b.payment_status IN ('await_gcash', 'await_cash', 'await_pos', 'overdue')
                                AND b.reservation_status NOT IN ('cancelled', 'rejected', 'released') LIMIT 1`);
      expect.ok(c, 'no customer with a due payment and a bell row in the snapshot');
      s.page = await ctx.loginCustomer(c.email);
      await s.page.setViewportSize(PHONE);
      return s.page;
    });

    for (const [path, here] of ON) {
      await step(`${path}: the bar is on, "${here}" is marked, nothing behind it`, async () => {
        const page = s.page;
        await page.goto(ctx.url(path));
        await expect.visible(bar(page), 'no tab bar');
        expect.equal(await page.locator('.ct-bar a[aria-current="page"]').getAttribute('data-tab'), here, 'the marked tab');
        expect.ok(!(await page.locator('#hdBurger').isVisible()), 'the menu button is still there');
        const small = await page.locator('.ct-bar a, nav.app-header a.hd-quick, nav.app-header .nb-bell').evaluateAll((els) =>
          els.filter((e) => { const b = e.getBoundingClientRect(); return b.height < 44 || b.width < 44; }).length);
        expect.equal(small, 0, 'tap targets under 44px');
        const m = await atTheEnd(page);
        expect.ok(m.end <= m.barTop + 1, 'content ends behind the bar: ' + JSON.stringify(m));
        expect.ok(!m.sideways, 'sideways scroll');
      });
    }

    await step('The page title stays Transaction History; the tab says Payments', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/transaction-history'));
      expect.equal((await page.locator('h1').textContent()).trim(), 'Transaction History', 'the title');
      expect.equal((await tab(page, 'payments').textContent()).trim(), 'Payments', 'the tab');
    });

    await step('Each tab goes to its page; Venues is the landing page, with no bar', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/booking-history'));
      for (const [name, re] of [['payments', /transaction-history/], ['calendar', /customer\/calendar/], ['me', /customer-profile/], ['bookings', /booking-history/]]) {
        await tab(page, name).click();
        await expect.url(page, re);
      }
      await tab(page, 'venues').click();
      await expect.url(page, /customer\/venues/);
      expect.equal(await bar(page).count(), 0, 'a bar on the landing page');
    });

    await step('Profile has FAQ and Log Out on a phone; the ? in the header opens the FAQ', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/customer-profile'));
      await expect.visible(page.locator('.account-actions a', { hasText: 'Log Out' }), 'no Log Out');
      await expect.visible(page.locator('.account-actions a', { hasText: 'FAQ' }), 'no FAQ on Profile');
      await page.locator('nav.app-header a.hd-quick').click();
      await expect.url(page, /customer\/faq\?in=app/);
    });

    await step('The Bookings tab has the red dot', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/booking-history'));
      await expect.visible(page.locator('.ct-bar a[data-tab="bookings"] .ct-dot'), 'no red dot');
    });

    await step('A live redraw keeps the bar and the space for it; a modal covers the bar', async () => {
      const page = s.page;
      await page.evaluate(() => Live.refresh());
      await expect.visible(bar(page), 'the bar left after a redraw');
      const m = await atTheEnd(page);
      expect.ok(m.end <= m.barTop + 1, 'after a redraw the content ends behind the bar: ' + JSON.stringify(m));
      await page.evaluate(() => { window.scrollTo(0, 0); venusepAlert('Flow check'); });
      const covered = await page.evaluate(() => {
        const b = document.querySelector('.ct-bar').getBoundingClientRect();
        return !document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2).closest('.ct-bar');
      });
      expect.ok(covered, 'the bar shows through an open modal');
    });

    for (const path of OFF) {
      await step(`${path}: no bar on a phone`, async () => {
        await s.page.goto(ctx.url(path));
        expect.equal(await bar(s.page).count(), 0, 'a bar here');
      });
    }

    await step('Pay page: no bar, the menu button stays', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/booking-history'));
      const link = page.locator('a[href*="/customer/pay"]').first();
      if (!(await link.count())) { ctx.note('no open payment for this customer: pay page not opened'); return; }
      await page.goto(await link.getAttribute('href'));
      expect.equal(await bar(page).count(), 0, 'a bar on the pay page');
      await expect.visible(page.locator('#hdBurger'), 'no menu button on the pay page');
    });

    await step('Desktop: no bar; the sidebar, burger and name chip are as before', async () => {
      const page = s.page;
      await page.setViewportSize({ width: 1280, height: 800 });
      await page.goto(ctx.url('/customer/booking-history'));
      expect.ok(!(await bar(page).isVisible()), 'a bar on desktop');
      await expect.visible(page.locator('aside.sidebar'), 'no sidebar');
      await expect.visible(page.locator('#hdBurger'), 'no burger');
      await expect.visible(page.locator('nav.app-header .hd-user'), 'no name chip');
      expect.ok(!(await page.locator('nav.app-header .hd-logo').isVisible()), 'a second logo on desktop');
      await page.goto(ctx.url('/customer/customer-profile'));
      expect.ok(!(await page.locator('.account-faq').isVisible()), 'the phone FAQ button on desktop');
    });

    await step('Counter mode: staff get no bar, even on the in-app FAQ', async () => {
      const page = await ctx.loginStaff();
      await page.setViewportSize(PHONE);
      await page.goto(ctx.url('/customer/faq?in=app&counter=1'));
      expect.equal(await bar(page).count(), 0, 'a bar in counter mode');
      await page.goto(ctx.url('/customer/venues?counter=1'));
      expect.equal(await bar(page).count(), 0, 'a bar on the counter landing page');
      return page;
    });
  },
};
