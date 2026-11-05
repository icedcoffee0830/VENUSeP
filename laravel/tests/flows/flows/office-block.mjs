// Flow — a customer blocked at one office (DB-DECISIONS 28, migration 17).
// The Bahay Alumni admin blocks a customer at Bahay Alumni with a reason → the customer
// sees the notice with the office's contact info (never the reason) on a Bahay room, and
// nothing on a USeP Venues room → the admin lifts the block with a reason.

const BAHAY_ADMIN = 'francishubero@gmail.com';
const CUSTOMER = 'customer@gmail.com';
const REASON = 'Flow test: left the hall damaged';
const NOTICE = "You can't make new bookings at Bahay Alumni right now. Please contact the office.";

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

    await step('The customer sees the notice and the office contact on a Bahay room, not the reason', async () => {
      const page = s.customer = await ctx.loginCustomer(CUSTOMER);
      await page.goto(ctx.url('/customer/room-reservation?room=r1'));
      await expect.text(page, NOTICE);
      const office = await dbOne(`SELECT contact_phone, contact_email FROM venues WHERE id = 1`);
      await expect.text(page, office.contact_email);
      expect.equal(await page.getByText(REASON).count(), 0, 'the reason is shown to the customer');
      return page;
    });

    await step('Another office is not affected', async () => {
      const page = s.customer;
      await page.goto(ctx.url('/customer/room-reservation?room=r5'));
      await page.waitForLoadState('load');
      expect.equal(await page.getByText("You can't make new bookings at").count(), 0, 'a notice on a USeP Venues room');
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
