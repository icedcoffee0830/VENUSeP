// Flow — roles and offices on Staff Management (DB-DECISIONS 28, migration 17).
// The CEDU admin sees only its offices' staff and ticks a Hostel staff member for both
// CEDU offices → the super admin hands the super admin over to the CEDU admin → the old
// one is signed out and cannot sign in → the new super admin has Activity.

const SUPER = 'admin@gmail.com';
const CEDU_ADMIN = 'cedu.admin@gmail.com';
const HOSTEL_STAFF = 'jakemerson@gmail.com';   // USeP Hostel
const BAHAY_STAFF = 'staff@gmail.com';          // Bahay Alumni

/* the shared venusepAlert/venusepConfirm/venusepPrompt modal (assets/js/confirm-modal.js) */
async function modal(ctx, page, { expect: re, fill, ok } = {}) {
  const box = page.locator('.vz-modal-overlay .vz-modal').last();
  await ctx.expect.visible(box, 'no dialog appeared');
  const text = ((await box.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (re) ctx.expect.match(text, re, 'dialog text');
  if (fill !== undefined) await box.locator('#vzPromptInput').fill(fill);
  const el = await box.elementHandle();
  await box.locator(ok ? `button[data-vz="ok"]:has-text("${ok}")` : 'button[data-vz="ok"]').click();
  await el.waitForElementState('hidden').catch(() => {});
  return text;
}

export default {
  name: 'roles-accounts',
  title: 'Roles — a CEDU admin\'s staff, both CEDU offices, handing over the super admin',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('The CEDU admin sees its own offices\' staff only, with each one\'s office', async () => {
      const page = s.cedu = await ctx.loginAdmin(CEDU_ADMIN);
      await page.goto(ctx.url('/admin/staff'));
      const row = page.locator('.tabulator-row', { hasText: HOSTEL_STAFF });
      await expect.visible(row, 'the Hostel staff member is not listed for the CEDU admin');
      expect.match(await row.textContent(), /USeP Hostel/, 'the office label in the row');
      expect.equal(await page.locator('.tabulator-row', { hasText: BAHAY_STAFF }).count(), 0, 'Bahay Alumni staff rows shown to the CEDU admin');
      expect.equal(await page.locator('.tabulator-row', { hasText: 'Super admin' }).count(), 0, 'the super admin row shown to a normal admin');
      return page;
    });

    await step('Ticks the Hostel staff member for both CEDU offices', async () => {
      const page = s.cedu;
      const reloaded = page.waitForEvent('load');
      await page.locator('.tabulator-row', { hasText: HOSTEL_STAFF }).locator('input.sm-both').check();
      await reloaded;
      const row = page.locator('.tabulator-row', { hasText: HOSTEL_STAFF });
      await expect.visible(row.locator('input.sm-both:checked'), 'the tick is gone after the reload');
      expect.match(await row.textContent(), /USeP Venues and USeP Hostel/, 'the office label after the tick');
      const st = await dbOne(`SELECT s.venue_id, s.both_cedu_offices FROM staff s JOIN users u ON u.id = s.user_id WHERE u.email = ?`, [HOSTEL_STAFF]);
      expect.equal(Number(st.both_cedu_offices), 1, 'staff.both_cedu_offices');
      return page;
    });

    await step('The super admin hands over to the CEDU admin (reason + password) and is signed out', async () => {
      const page = s.super = await ctx.loginAdmin(SUPER);
      await page.goto(ctx.url('/admin/staff'));
      const row = page.locator('.tabulator-row', { hasText: CEDU_ADMIN });
      await row.getByRole('button', { name: 'Make super admin' }).click();
      await modal(ctx, page, { expect: /becomes the super admin/, fill: 'Leaving the office', ok: 'Next' });
      await modal(ctx, page, { expect: /Both of you are emailed/, fill: ctx.password, ok: 'Hand over' });
      await modal(ctx, page, { expect: /is now the super admin/ });
      await page.waitForURL(/\/admin\/admin-login/);
      const old = await dbOne(`SELECT is_super_admin, is_active, handed_over_at FROM users WHERE email = ?`, [SUPER]);
      const now = await dbOne(`SELECT is_super_admin, office_group FROM users WHERE email = ?`, [CEDU_ADMIN]);
      expect.ok(Number(old.is_super_admin) === 0 && Number(old.is_active) === 0 && old.handed_over_at, 'the old super admin is not suspended for good');
      expect.ok(Number(now.is_super_admin) === 1 && now.office_group === null, 'the CEDU admin is not the super admin');
      const mails = await dbOne(`SELECT COUNT(*) AS n FROM email_outbox WHERE kind = 'super_admin_handover' AND to_email IN (?, ?)`, [SUPER, CEDU_ADMIN]);
      expect.equal(Number(mails.n), 2, 'handover emails queued');
      return page;
    });

    await step('The new super admin has Activity; the old one cannot sign in', async () => {
      const page = s.cedu;
      await page.goto(ctx.url('/admin/activity'));
      await expect.url(page, /\/admin\/activity/);
      await expect.visible(page.locator('aside.sidebar a', { hasText: 'Backup & Restore' }), 'no Backup & Restore in the new super admin\'s menu');

      const login = await ctx.newPage('old super admin');
      await login.goto(ctx.url('/admin/admin-login'));
      await login.locator('label.department-card').first().click();
      await login.locator('#adminEmail').fill(SUPER);
      await login.locator('#adminPassword').fill(ctx.password);
      await login.locator('#adminLoginForm button[type="submit"]').click();
      await login.waitForLoadState('load');
      await expect.text(login, 'Your account has been suspended.');
      return login;
    });
  },
};
