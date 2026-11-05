// Flow — a customer blocked at one office (DB-DECISIONS 28, migration 17).
// The Bahay Alumni admin blocks a customer at Bahay Alumni with a reason → the customer
// sees the notice with the office's contact info (never the reason) on a Bahay room, and
// nothing on a USeP Venues room → the admin lifts the block with a reason.

const BAHAY_ADMIN = 'francishubero@gmail.com';
const CUSTOMER = 'customer@gmail.com';
const REASON = 'Flow test: left the hall damaged';
const NOTICE = "You can't book at Bahay Alumni right now. Please contact the office.";

/* the shared venusepPrompt/venusepAlert modal (assets/js/confirm-modal.js) */
async function modal(ctx, page, { expect: re, fill, ok } = {}) {
  const box = page.locator('.vz-modal-overlay .vz-modal').last();
  await ctx.expect.visible(box, 'no dialog appeared');
  const text = ((await box.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (re) ctx.expect.match(text, re, 'dialog text');
  if (fill !== undefined) await box.locator('#vzPromptInput').fill(fill);
  await box.locator(ok ? `button[data-vz="ok"]:has-text("${ok}")` : 'button[data-vz="ok"]').click();
  /* gone before the next check, or afterSave() takes this closing dialog for an error */
  await page.locator('.vz-modal-overlay').waitFor({ state: 'detached', timeout: 5_000 }).catch(() => {});
  return text;
}

/* The page reloads after a save; an error comes back as an alert instead: fail with its text. */
async function afterSave(ctx, page, expected, msg) {
  const alert = page.locator('.vz-modal-overlay .vz-modal');
  await expected.or(alert).first().waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
  if (await alert.count()) ctx.fail('the save answered: ' + ((await alert.last().textContent({ timeout: 2_000 }).catch(() => '')) || '').replace(/\s+/g, ' ').trim());
  await ctx.expect.visible(expected, msg);
}

export default {
  name: 'office-block',
  title: 'Blocks — a customer blocked at one office, the notice, lifting it',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('The Bahay Alumni admin blocks the customer at Bahay Alumni', async () => {
      const page = s.admin = await ctx.loginAdmin(BAHAY_ADMIN);
      await page.goto(ctx.url('/admin/customer-management'));
      const row = page.locator('.tabulator-row', { hasText: CUSTOMER });
      await expect.visible(row, 'the customer is not listed for the Bahay Alumni admin');
      expect.equal(await row.locator('.btn-cust-toggle').count(), 0, 'a normal admin was offered the account suspension');
      expect.equal(await row.locator('button', { hasText: 'Block at USeP Venues' }).count(), 0, 'another group\'s office offered');
      await row.locator('button', { hasText: 'Block at Bahay Alumni' }).click();
      await modal(ctx, page, { expect: /cannot make new bookings at Bahay Alumni/, fill: REASON, ok: 'Block' });
      await afterSave(ctx, page, page.locator('.tabulator-row', { hasText: CUSTOMER }).locator('.badge', { hasText: 'Blocked · Bahay Alumni' }), 'no Blocked badge after the reload');
      const b = await dbOne(`SELECT k.reason, k.lifted_at FROM customer_office_blocks k JOIN customers c ON c.id = k.customer_id
                               JOIN users u ON u.id = c.user_id WHERE u.email = ? AND k.venue_id = 1`, [CUSTOMER]);
      expect.ok(b && b.reason === REASON && b.lifted_at === null, 'the block row (reason, still on): ' + JSON.stringify(b));
      return page;
    });

    await step('After sign-in a modal tells the customer once, and Bahay Alumni is gone from the listings', async () => {
      const page = s.customer = await ctx.loginCustomer(CUSTOMER);
      const modal = page.locator('#blockModal');
      await expect.visible(modal, 'no modal after sign-in');
      expect.match(await modal.textContent(), /You can't book at Bahay Alumni right now\. Please contact the office\./, 'the modal');
      const office = await dbOne(`SELECT contact_email FROM venues WHERE id = 1`);
      expect.match(await modal.textContent(), new RegExp(office.contact_email.replace('.', '\\.')), 'the modal has the contact details');
      expect.ok(!(await page.content()).includes(REASON), 'the reason reached the page');
      await modal.getByRole('button', { name: 'OK' }).click();
      expect.ok(!(await modal.isVisible()), 'OK did not close the modal');
      expect.equal(await page.locator('a[href*="room-reservation?room=r1"]').count(), 0, 'a Bahay room card on the landing page');
      expect.equal(await page.locator('a[href$="#venue-bahay-alumni"]').count(), 0, 'a Bahay link in the nav');
      expect.ok(await page.locator('a[href*="room-reservation?room=r5"]').count() > 0, 'USeP Venues went too');
      await page.reload();
      expect.equal(await page.locator('#blockModal').count(), 0, 'the modal came back in the same session');
      /* the strip stays on every load, after the modal is gone */
      const strip = page.locator('[role="alert"]', { hasText: NOTICE });
      await expect.visible(strip, 'no warning strip on the landing page');
      await expect.visible(strip.getByText(office.contact_email), 'no contact details in the strip');
      expect.ok(!(await page.content()).includes(REASON), 'the reason reached the page');
      return page;
    });

    await step('The customer sees the notice and the office contact on a Bahay room, not the reason', async () => {
      const page = s.customer;
      await page.goto(ctx.url('/customer/room-reservation?room=r1'));
      await expect.visible(page.locator('#blockModal'), 'no modal on a direct link');
      await page.locator('#blockModal').getByRole('button', { name: 'OK' }).click();
      /* the banner, not the closed modal: both hold the words */
      const banner = page.locator('[role="alert"]', { hasText: NOTICE });
      await expect.visible(banner, 'no warning on the page after the modal closed');
      const office = await dbOne(`SELECT contact_phone, contact_email FROM venues WHERE id = 1`);
      await expect.visible(banner.getByText(office.contact_email), 'no contact details in the warning');
      expect.equal(await page.getByText(REASON).count(), 0, 'the reason is shown to the customer');
      return page;
    });

    await step('On a phone the modal fits the screen and the page keeps its width', async () => {
      const page = s.customer;
      await page.setViewportSize({ width: 360, height: 700 });
      await page.reload();
      const box = await page.locator('#blockModal').boundingBox();
      expect.ok(box && box.x >= 0 && box.x + box.width <= 360 && box.y >= 0 && box.y + box.height <= 700, 'the modal runs off the screen: ' + JSON.stringify(box));
      return page;
    });

    await step('Esc closes the modal', async () => {
      const page = s.customer;
      await page.keyboard.press('Escape');
      expect.ok(!(await page.locator('#blockModal').isVisible()), 'Esc did not close the modal');
      await page.setViewportSize({ width: 1280, height: 900 });
      return page;
    });

    await step('Another office is not affected', async () => {
      const page = s.customer;
      await page.goto(ctx.url('/customer/room-reservation?room=r5'));
      await page.waitForLoadState('load');
      expect.equal(await page.getByText("You can't book at").count(), 0, 'a notice on a USeP Venues room');
      return page;
    });

    await step('The admin lifts the block with a reason', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/customer-management'));
      await page.locator('.tabulator-row', { hasText: CUSTOMER }).locator('button', { hasText: 'Lift block' }).click();
      await modal(ctx, page, { expect: /They can book at Bahay Alumni again/, fill: 'Flow test: paid for it', ok: 'Lift block' });
      await afterSave(ctx, page, page.locator('.tabulator-row', { hasText: CUSTOMER }).locator('button', { hasText: 'Block at Bahay Alumni' }), 'the Block button is not back');
      const b = await dbOne(`SELECT k.lifted_at, k.lift_reason FROM customer_office_blocks k JOIN customers c ON c.id = k.customer_id
                               JOIN users u ON u.id = c.user_id WHERE u.email = ? AND k.venue_id = 1`, [CUSTOMER]);
      expect.ok(b && b.lifted_at && b.lift_reason === 'Flow test: paid for it', 'the lift (when, reason): ' + JSON.stringify(b));
      return page;
    });
  },
};
