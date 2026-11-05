// Flow — pages that update without a reload (public/assets/js/live.js).
// A. one staff action shows on other people's open pages through the 30-second check:
//    the customer's Booking History (filters kept), the pay page, Booking Management
//    (typing in the search box survives) and the Dashboard.
// B. the check waits: an open dialog holds the redraw until it closes, a hidden tab asks
//    nothing, and it never runs twice at once.
// The check runs on its 30-second clock and at once when a tab comes back into view; these
// steps use the second way, so a run does not sit waiting.

import { createHmac } from 'node:crypto';

const STAFF = 'staff@gmail.com';
const ADMIN = 'francishubero@gmail.com';   // Bahay Alumni's admin: the same office as the staff member

const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/* the code an authenticator app shows for the key on the screen */
function totpNow(base32) {
  let bits = '';
  for (const c of base32.replace(/\s+/g, '').toUpperCase()) bits += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const step = Buffer.alloc(8);
  step.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = createHmac('sha1', key).update(step).digest();
  const o = h[19] & 15;
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}

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

    await step('The customer profile: a new photo reaches the chip, and the two-step panel still works after it', async () => {
      const page = s.history;
      await open(ctx, page, '/customer/customer-profile');
      await page.locator('#avatarInput').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: Buffer.from(PNG_1PX, 'base64') });
      await expect.visible(page.locator('img.hd-avatar'), 'the header chip did not take the photo');
      await expect.visible(page.locator('img#profileAvatar'), 'the summary did not take the photo');
      await expect.visible(page.locator('#avatarRemove'), 'no Remove button after the upload');

      const panel = page.locator('[data-tfa-panel]');
      await panel.locator('[data-tfa-action="begin"]').click();
      await panel.locator('[data-tfa-current]').fill(ctx.password);
      await panel.locator('[data-tfa-prompt-go]').click();
      await panel.locator('[data-tfa-new-code]').waitFor();
      const key = await panel.locator('[data-tfa-key]').textContent();
      await panel.locator('[data-tfa-new-code]').fill(totpNow(key));
      await panel.locator('[data-tfa-new-code]').press('Enter');
      await panel.locator('[data-tfa-codes-list] li').first().waitFor();
      s.recovery = (await panel.locator('[data-tfa-codes-list] li').first().textContent()).trim();
      await panel.locator('[data-tfa-saved]').check();
      await panel.locator('[data-tfa-saved-submit]').click();
      await expect.visible(page.locator('[data-tfa-panel] .tfa-chip-on'), 'the panel does not say On');
      expect.ok(await marked(page), 'the page reloaded');
    });

    await step('The redrawn two-step panel turns off in place; the photo goes; the eye button still works', async () => {
      const page = s.history;
      const panel = page.locator('[data-tfa-panel]');
      await panel.locator('[data-tfa-action="disable"]').click();
      await panel.locator('[data-tfa-current]').fill(s.recovery);
      await panel.locator('[data-tfa-prompt-go]').click();
      await expect.visible(page.locator('[data-tfa-panel] .tfa-chip-off'), 'the panel does not say Off');
      await page.locator('#avatarRemove').click();
      await page.locator('#avatarRemove').waitFor({ state: 'detached' });
      await expect.visible(page.locator('span.hd-avatar'), 'the chip kept the removed photo');
      const eye = page.locator('[data-profile-password-toggle]').first();
      await eye.click();
      expect.equal(await eye.getAttribute('aria-pressed'), 'true', 'the password eye button');
      expect.ok(await marked(page), 'the page reloaded');
    });

    await step('Admin: the profile name reaches the chip; the venue form stays open after Save', async () => {
      const page = s.list;
      await open(ctx, page, '/admin/profile');
      await page.locator('#profile-first').fill('Renamed');
      await page.getByRole('button', { name: 'Save changes' }).click();
      await page.locator('[data-live="hd-user"]', { hasText: 'Renamed' }).waitFor();
      expect.ok(await marked(page), 'the page reloaded');

      await open(ctx, page, '/admin/venue-form?id=1');
      await page.locator('#venueDesc').fill('Flow test: saved in place');
      await page.getByRole('button', { name: 'Save Changes' }).click();
      await page.locator('.vz-modal .vz-ok').click();
      expect.equal((await dbOne('SELECT description FROM venues WHERE id = 1')).description, 'Flow test: saved in place', 'venues.description');
      expect.ok(page.url().includes('/admin/venue-form?id=1') && await marked(page), 'the venue form left or reloaded');
    });

    await step('A table keeps its page when the rows are replaced', async () => {
      const page = s.list;
      await page.goto(ctx.url('/admin/customer-management'));
      await page.locator('.tabulator-row').first().waitFor();
      const after = await page.evaluate(async () => {
        const t = Tabulator.findTable('#customers-table')[0];
        await t.setPageSize(3);
        await t.setPage(2);
        await t.replaceData(t.getData());
        return t.getPage();
      });
      expect.equal(after, 2, 'the table page after replaceData');
    });
  },
};
