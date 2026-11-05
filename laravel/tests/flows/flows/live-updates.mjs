// Flow — pages that update without a reload (public/assets/js/live.js).
// A. one staff action shows on other people's open pages through the 30-second check:
//    the customer's Booking History (filters kept), the pay page, Booking Management
//    (typing in the search box survives) and the Dashboard.
// B. the check waits: an open dialog holds the redraw until it closes, a hidden tab asks
//    nothing, and it never runs twice at once.
// The check runs on its 30-second clock and at once when a tab comes back into view; these
// steps use the second way, so a run does not sit waiting.

const STAFF = 'staff@gmail.com';
const ADMIN = 'francishubero@gmail.com';   // Bahay Alumni's admin: the same office as the staff member

const refOf = (b) => `VB-${b.y}-${String(b.id).padStart(3, '0')}`;
const marked = (page) => page.evaluate(() => window.__sameLoad === true);
const mark = (page) => page.evaluate(() => { window.__sameLoad = true; });
const isCheck = (r) => r.url().includes('/live-check');
const isPart = (r) => r.url().includes('part=1');

/* the tab "comes back into view": one check, now */
async function tick(page) {
  const asked = page.waitForResponse(isCheck);
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  return asked;
}

/* the first check of a loaded page only sets the mark */
async function open(ctx, page, url) {
  const first = page.waitForResponse(isCheck);
  await page.goto(ctx.url(url));
  await first;
  await page.waitForTimeout(200);
  await mark(page);
}

export default {
  name: 'live-updates',
  title: 'One staff action reaches open pages without a reload; the check waits for dialogs, hidden tabs and itself',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('Four pages are open before anything changes', async () => {
      s.b = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y, u.email FROM bookings b
          JOIN rooms r ON r.id = b.room_id JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
         WHERE r.venue_id = 1 AND b.reservation_status = 'approved' AND b.payment_status = 'overdue'
           AND b.payment_method = 'gcash' ORDER BY b.id LIMIT 1`);
      expect.ok(s.b, 'no overdue GCash booking with a customer account at venue 1');
      s.ref = refOf(s.b);
      s.staff = await ctx.loginStaff(STAFF);

      s.history = await ctx.loginCustomer(s.b.email);
      await open(ctx, s.history, '/customer/booking-history');
      await s.history.locator('#booking-history-search').fill(s.ref);
      s.pay = await s.history.context().newPage();
      await open(ctx, s.pay, '/customer/pay?booking=' + encodeURIComponent(s.ref));
      await expect.text(s.pay, 'Payment overdue. Please contact the office.');

      s.list = await ctx.loginAdmin(ADMIN);
      await open(ctx, s.list, '/admin/booking-requests');
      await s.list.locator('#brSearch').fill(s.ref);
      await s.list.locator('.br-row', { hasText: 'Payment overdue' }).first().waitFor();
      s.dash = await s.list.context().newPage();
      await open(ctx, s.dash, '/admin/dashboard');
      s.overdue = Number(await s.dash.locator('#statOverdue').textContent());
    });

    await step('Staff extend the deadline, in place', async () => {
      const page = s.staff;
      await page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(s.ref)));
      await page.getByRole('button', { name: 'Extend 48 h' }).click();
      await expect.text(page, 'Awaiting GCash payment', 20_000);
    });

    await step('Booking Management: the row changes, the search keeps its text and focus', async () => {
      const page = s.list;
      await page.locator('#brSearch').focus();
      const part = page.waitForResponse(isPart);
      await tick(page);
      await part;
      await page.locator('.br-row', { hasText: 'Awaiting payment' }).first().waitFor();
      expect.equal(await page.locator('#brSearch').inputValue(), s.ref, 'the search box');
      expect.ok(await page.evaluate(() => document.activeElement.id === 'brSearch'), 'the search box lost the focus');
      expect.ok(await marked(page), 'the page reloaded');
    });

    await step('The Dashboard: the overdue tile goes down', async () => {
      const page = s.dash;
      const part = page.waitForResponse(isPart);
      await tick(page);
      await part;
      await page.waitForFunction((n) => Number(document.getElementById('statOverdue').textContent) === n, s.overdue - 1);
      expect.ok(await marked(page), 'the page reloaded');
    });

    await step('Booking History: Pay now appears, the filter stays', async () => {
      const page = s.history;
      const part = page.waitForResponse(isPart);
      await tick(page);
      await part;
      await expect.visible(page.locator(`tr[data-booking-id="${s.ref}"] a.booking-action-pay`), 'no Pay now after the extension');
      expect.equal(await page.locator('#booking-history-search').inputValue(), s.ref, 'the search box');
      expect.equal(await page.locator('tr[data-booking-row]:not([hidden])').count(), 1, 'rows shown under the filter');
      expect.ok(await marked(page), 'the page reloaded');
    });

    await step('The pay page: the booking opened for paying, so the payment form comes', async () => {
      const page = s.pay;
      await tick(page);
      await expect.visible(page.locator('#payApp .pay-method').first(), 'no payment form after the extension');
    });

    await step('An open dialog holds the redraw; it comes once the dialog closes', async () => {
      const page = s.list;
      await page.evaluate(() => {
        const d = document.createElement('dialog');
        d.id = 'flowDialog';
        document.body.append(d);
        d.showModal();
      });
      const parts = [];
      const seen = (r) => { if (isPart(r)) parts.push(r); };
      page.on('response', seen);
      /* the customer's cash choice is a change for the staff list */
      await s.pay.getByRole('button', { name: /Cash — at the office/ }).click();
      await s.pay.getByRole('button', { name: 'I will pay in cash' }).click();
      await expect.url(s.pay, /sent=cash/, 20_000);
      await tick(page);
      await page.waitForTimeout(500);
      expect.equal(parts.length, 0, 'parts fetched under an open dialog');
      await page.evaluate(() => document.getElementById('flowDialog').close());
      const part = page.waitForResponse(isPart);
      await tick(page);
      await part;
      page.off('response', seen);
      await page.locator('.br-row', { hasText: 'Cash' }).first().waitFor();
    });

    await step('A hidden tab asks nothing; the check never overlaps itself', async () => {
      const page = s.list;
      const checks = [];
      const seen = (r) => { if (isCheck(r)) checks.push(r); };
      page.on('request', seen);
      await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { value: true, configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
      });
      await page.waitForTimeout(500);
      expect.equal(checks.length, 0, 'checks from a hidden tab');
      await page.evaluate(() => {
        delete document.hidden;
        for (let i = 0; i < 5; i++) document.dispatchEvent(new Event('visibilitychange'));
      });
      await page.waitForTimeout(500);
      expect.equal(checks.length, 1, 'checks after five wake-ups at once');
      page.off('request', seen);
    });
  },
};
