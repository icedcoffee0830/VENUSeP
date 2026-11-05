// The date pickers on the two booking pages: what the customer sees while picking.

const CUSTOMER = 'nikkaarena@gmail.com';
const HOSTEL_ROOM = 'h1';

export default {
  name: 'booking-dates',
  title: 'Date pickers: month label readable',

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
  },
};
