// Flow — covers (Aron 2026-11-05, migration 18).
// The CEDU admin sets a cover inside its group → it counts at once: the Hostel staff member
// opens a USeP Venues booking → more than 7 days is refused in the form → the admin ends it
// early and the booking closes to them → a cover across groups waits for the Bahay Alumni
// admin, who rejects one (with a reason) and approves another → all of it is on Activity.

const CEDU_ADMIN = 'cedu.admin@gmail.com';
const BAHAY_ADMIN = 'francishubero@gmail.com';
const SUPER = 'admin@gmail.com';
const HOSTEL_STAFF = 'jakemerson@gmail.com';
const REASON = 'Flow test: shift change';

/* the shared venusepPrompt/venusepConfirm modal (assets/js/confirm-modal.js) */
async function modal(ctx, page, { expect: re, fill, ok } = {}) {
  const box = page.locator('.vz-modal-overlay .vz-modal').last();
  await ctx.expect.visible(box, 'no dialog appeared');
  const text = ((await box.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (re) ctx.expect.match(text, re, 'dialog text');
  if (fill !== undefined) await box.locator('#vzPromptInput').fill(fill);
  await box.locator(ok ? `button[data-vz="ok"]:has-text("${ok}")` : 'button[data-vz="ok"]').click();
  return text;
}

/* Fill the form; times come from the database clock, not the browser's time zone. */
async function fillCover(ctx, page, { staff, office, fromSql, toSql }) {
  const t = await ctx.dbOne(`SELECT DATE_FORMAT(${fromSql}, '%Y-%m-%dT%H:%i') AS f, DATE_FORMAT(${toSql}, '%Y-%m-%dT%H:%i') AS t`);
  await page.locator('#smCoverOpen').click();
  const form = page.locator('#smCoverDlg');
  await ctx.expect.visible(form, 'the cover form did not open');
  await form.locator('select[name="user_id"]').selectOption(String(staff));
  await form.locator('select[name="venue_id"]').selectOption({ label: office });
  await form.locator('input[name="from"]').fill(t.f);
  await form.locator('input[name="to"]').fill(t.t);
  await form.locator('textarea[name="reason"]').fill(REASON);
  return form;
}

async function saveCover(page, form) {
  const reloaded = page.waitForEvent('load');
  await form.getByRole('button', { name: 'Set up cover' }).click();
  await reloaded;
}

const coverRow = (page, state) => page.locator(`tr[data-state="${state}"]`).first();

export default {
  name: 'staff-covers',
  title: 'Covers — inside a group, across groups, ending early, on Activity',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('The CEDU admin sets a cover inside its group: it counts at once', async () => {
      s.jake = (await dbOne(`SELECT id FROM users WHERE email = ?`, [HOSTEL_STAFF])).id;
      const page = s.cedu = await ctx.loginAdmin(CEDU_ADMIN);
      await page.goto(ctx.url('/admin/staff'));
      await expect.visible(page.locator('.tabulator-row', { hasText: HOSTEL_STAFF }).getByText('Whole hostel'), 'the hostel staff row does not say Whole hostel');
      const form = await fillCover(ctx, page, { staff: s.jake, office: 'USeP Venues', fromSql: 'NOW()', toSql: 'NOW() + INTERVAL 1 DAY' });
      await saveCover(page, form);
      await expect.visible(coverRow(page, 'on').getByText('On now'), 'the cover is not On now');
      return page;
    });

    await step('The staff member opens a USeP Venues booking during the cover; the list opens on My halls', async () => {
      const b = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE r.venue_id = 2 ORDER BY b.id DESC LIMIT 1`);
      s.ref = `VB-${b.y}-${String(b.id).padStart(3, '0')}`;
      const page = s.jakePage = await ctx.loginStaff(HOSTEL_STAFF);
      await page.goto(ctx.url('/admin/booking-requests'));
      await expect.visible(page.locator('.br-scope button[aria-pressed="true"]', { hasText: 'My halls' }), 'the list did not open on My halls');
      await page.locator('.br-scope button', { hasText: 'Whole office' }).click();
      await expect.visible(page.locator('.br-scope button[aria-pressed="true"]', { hasText: 'Whole office' }), 'one click did not show the whole office');
      await page.goto(ctx.url('/admin/booking-request?id=' + s.ref));
      await expect.text(page, s.ref);
      return page;
    });

    await step('More than 7 days is refused inside the form', async () => {
      const page = s.cedu;
      const form = await fillCover(ctx, page, { staff: s.jake, office: 'USeP Venues', fromSql: 'NOW() + INTERVAL 1 DAY', toSql: 'NOW() + INTERVAL 9 DAY' });
      await form.getByRole('button', { name: 'Set up cover' }).click();
      await expect.visible(form.getByText('A cover lasts 7 days at most.'), 'the form did not say 7 days at most');
      await form.getByRole('button', { name: 'Cancel' }).click();
      return page;
    });

    await step('The admin ends it early: the booking closes to the staff member', async () => {
      const page = s.cedu;
      const reloaded = page.waitForEvent('load');
      await coverRow(page, 'on').getByRole('button', { name: 'End now' }).click();
      await modal(ctx, page, { expect: /stops now/, ok: 'End cover' });
      await reloaded;
      await expect.visible(coverRow(page, 'ended').getByText('Ended early'), 'the cover does not say Ended early');
      const staff = s.jakePage;
      await staff.goto(ctx.url('/admin/booking-requests'));
      expect.equal(await staff.locator('.br-row', { hasText: s.ref }).count(), 0, 'rows of the other office still listed');
      return staff;
    });

    await step('A cover across groups waits; the Bahay Alumni admin rejects it with a reason', async () => {
      const page = s.cedu;
      const form = await fillCover(ctx, page, { staff: s.jake, office: 'Bahay Alumni', fromSql: 'NOW() + INTERVAL 1 DAY', toSql: 'NOW() + INTERVAL 2 DAY' });
      await expect.visible(form.locator('#smCoverHint'), 'the form does not say this needs approval');
      await saveCover(page, form);
      await expect.visible(coverRow(page, 'waiting').getByText('Waiting for approval'), 'the cover is not waiting');
      expect.equal(await coverRow(page, 'waiting').getByRole('button', { name: 'Approve' }).count(), 0, 'Approve buttons for the admin who asked');

      const bahay = s.bahay = await ctx.loginAdmin(BAHAY_ADMIN);
      await bahay.goto(ctx.url('/admin/staff'));
      const reloaded = bahay.waitForEvent('load');
      await coverRow(bahay, 'waiting').getByRole('button', { name: 'Reject' }).click();
      await modal(ctx, bahay, { expect: /Reject this request/, fill: 'Flow test: no room for it', ok: 'Reject' });
      await reloaded;
      await expect.visible(coverRow(bahay, 'rejected').getByText('Flow test: no room for it'), 'the reason is not shown');
      return bahay;
    });

    await step('Asked again, the Bahay Alumni admin approves it', async () => {
      const page = s.cedu;
      await page.goto(ctx.url('/admin/staff'));
      const form = await fillCover(ctx, page, { staff: s.jake, office: 'Bahay Alumni', fromSql: 'NOW() + INTERVAL 1 DAY', toSql: 'NOW() + INTERVAL 2 DAY' });
      await saveCover(page, form);
      const bahay = s.bahay;
      await bahay.goto(ctx.url('/admin/staff'));
      const reloaded = bahay.waitForEvent('load');
      await coverRow(bahay, 'waiting').getByRole('button', { name: 'Approve' }).click();
      await reloaded;
      await expect.visible(coverRow(bahay, 'scheduled').getByText('Starts later'), 'the approved cover does not say Starts later');
      return bahay;
    });

    await step('Every step is on the super admin\'s Activity page', async () => {
      const page = await ctx.loginAdmin(SUPER);
      await page.goto(ctx.url('/admin/activity'));
      for (const line of ['Set a cover at USeP Venues', 'Ended the cover at USeP Venues', 'Asked for a cover at Bahay Alumni',
        'Rejected the cover at Bahay Alumni', 'Approved the cover at Bahay Alumni']) {
        await expect.text(page, line);
      }
      return page;
    });
  },
};
