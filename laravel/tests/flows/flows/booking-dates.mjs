// The date pickers on the two booking pages: what the customer sees while picking.

const CUSTOMER = 'nikkaarena@gmail.com';
const HOSTEL_ROOM = 'h1';
const VENUE_ROOM = 'r5';

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (n) => { const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() + n); return d; };
const WINDOW = 183;

/* Page the open picker forward as far as it goes; the last month must stop the arrow. */
async function toLastMonth(page) {
  const next = page.locator('.cal-pop .cal-nav').last();
  for (let i = 0; i < 12 && await next.isEnabled(); i++) await next.click();
  return next;
}
const pickable = (page, day) => page.locator(`.cal-pop button[onclick*="calPick('${day}')"]:not([disabled])`);

export default {
  name: 'booking-dates',
  title: 'Date pickers: month label readable, the 183-day window, too-long warning while picking',

  async run(ctx) {
    const { step, expect } = ctx;
    const s = ctx.state;

    await step('The customer signs in', async () => {
      s.page = await ctx.loginCustomer(CUSTOMER);
      return s.page;
    });

    await step('Hostel date picker: the month shows, dark on the white popup', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/hostel-reservation?room=' + HOSTEL_ROOM));
      await page.locator(`button[onclick*="openCal('checkIn')"]`).click();
      const label = page.locator('.cal-pop .cal-nav').first().locator('xpath=following-sibling::span');
      expect.match(await label.textContent(), /^[A-Z][a-z]+ \d{4}$/, 'month label');
      const [r, g, b] = (await label.evaluate((el) => getComputedStyle(el).color)).match(/\d+/g).map(Number);
      expect.ok(r + g + b < 200, `the month label is too light to read on white: rgb(${r}, ${g}, ${b})`);
      return page;
    });

    await step('Hostel picker: the calendar stops at the window, later days are greyed out', async () => {
      const page = s.page;
      const lastDay = iso(addDays(WINDOW)), lastIn = iso(addDays(WINDOW - 1)), after = iso(addDays(WINDOW + 1));
      expect.ok(await (await toLastMonth(page)).isDisabled(), 'the next-month arrow goes on past the window');
      await expect.text(page, 'Bookable up to');
      expect.equal(await pickable(page, lastIn).count(), 1, 'the last check-in is not pickable');
      expect.equal(await pickable(page, lastDay).count(), 0, 'check-in on the last day leaves no night inside the window');
      await pickable(page, lastIn).click();
      await page.locator(`button[onclick*="openCal('checkOut')"]`).click();
      expect.equal(await pickable(page, lastDay).count(), 1, 'the last day is not pickable as a check-out');
      expect.equal(await pickable(page, after).count(), 0, 'a check-out past the window is pickable');
      return page;
    });

    await step('Hostel: a stay that is too long warns at once, while picking', async () => {
      const page = s.page;
      /* the window keeps the pickers under the limit; the warning still has to stand on its own */
      await page.evaluate(([a, b]) => { setBooking('checkIn', a); setBooking('checkOut', b); }, [iso(addDays(1)), iso(addDays(WINDOW + 2))]);
      await expect.text(page, 'A stay can be at most 183 nights');
      expect.ok(await page.locator('#hbGo').isDisabled(), 'Continue is open on a stay that is too long');
      return page;
    });

    await step('Venue picker: the calendar stops at the window too', async () => {
      const page = s.page;
      await page.goto(ctx.url('/customer/room-reservation?room=' + VENUE_ROOM));
      await page.locator('#bk-date').click();
      await pickable(page, iso(addDays(0))).click();
      await page.locator('#bk-dateEnd').click();
      expect.ok(await (await toLastMonth(page)).isDisabled(), 'the next-month arrow goes on past the window');
      expect.equal(await pickable(page, iso(addDays(WINDOW + 1))).count(), 0, 'a date past the window is pickable');
      await pickable(page, iso(addDays(WINDOW))).click();
      return page;
    });

    await step('Venue: 184 days warn at once, before any hours are set', async () => {
      await expect.text(s.page, 'A booking can cover at most 183 days');
      return s.page;
    });
  },
};
