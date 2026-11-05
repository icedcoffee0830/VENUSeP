// Flow — an account made at the counter, booked on "ID checked", then its email fixed
// (DB-DECISIONS 28, migration 17).
// The CEDU admin opens a USeP Venues room in counter mode → "New account" with a
// mistyped email domain → takes the suggestion → the confirm step shows the email
// large, to read back → the account is made (no password) → "ID checked" stands in for
// the password → the booking is created, with the ID check on its History →
// the same admin corrects the account's email on Customer Management with a reason.

const CEDU_ADMIN = 'cedu.admin@gmail.com';
const ROOM = 'r5';   // USeP Venues (its only active room in the snapshot)

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/* the shared venusepPrompt/venusepAlert modal (assets/js/confirm-modal.js) */
async function modal(ctx, page, { expect: re, fill, ok } = {}) {
  const box = page.locator('.vz-modal-overlay .vz-modal').last();
  await ctx.expect.visible(box, 'no dialog appeared');
  const text = ((await box.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (re) ctx.expect.match(text, re, 'dialog text');
  if (fill !== undefined) await box.locator('#vzPromptInput').fill(fill);
  await box.locator(ok ? `button[data-vz="ok"]:has-text("${ok}")` : 'button[data-vz="ok"]').click();
  await page.locator('.vz-modal-overlay .vz-modal').filter({ hasText: text.slice(0, 40) }).waitFor({ state: 'detached', timeout: 5_000 }).catch(() => {});
  return text;
}

export default {
  name: 'counter-new-account',
  title: 'Counter — a new account (email suggestion, read back), booked on ID checked, email fixed',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    const n = ctx.uniq.replace(/\D/g, '');
    s.local = `flow.counter.${n}`;
    s.name = `Flow Counter ${n}`;

    await step('The CEDU admin opens a USeP Venues room at the counter and fills in the event', async () => {
      const page = s.admin = await ctx.loginAdmin(CEDU_ADMIN);
      await page.goto(ctx.url(`/customer/room-reservation?room=${ROOM}&counter=1`));
      await expect.text(page, 'Counter booking');
      const room = await dbOne(`SELECT r.id, e.attendee_capacity FROM rooms r JOIN event_room_details e ON e.room_id = r.id WHERE r.room_code = ?`, [ROOM]);
      const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() + 20);
      for (let i = 0; i < 60 && !s.day; i++, d.setDate(d.getDate() + 1)) {
        const day = iso(d);
        const busy = await dbOne(`SELECT
            (SELECT COUNT(*) FROM venue_booking_slots WHERE room_id = ? AND slot_date = ? AND released_at IS NULL) +
            (SELECT COUNT(*) FROM maintenance_windows WHERE room_id = ? AND from_date <= ? AND (until_date IS NULL OR until_date >= ?)) AS n`,
          [room.id, day, room.id, day, day]);
        if (Number(busy.n) === 0) s.day = day;
      }
      expect.ok(s.day, 'no free date found in the next 60 days');
      await page.locator('#bk-eventName').fill('Counter new account ' + ctx.uniq);
      await page.locator('#bk-date').click();
      const day = page.locator(`.cal-pop button[onclick="calPick('${s.day}')"]`);
      for (let i = 0; i < 4 && !(await day.isVisible()); i++) await page.locator('.cal-pop .cal-nav').last().click();
      await day.click();
      await page.locator('#bk-start').click();
      await page.locator('.tt-pop').getByRole('button', { name: '9:00 AM', exact: true }).click();
      await page.locator('#bk-end').click();
      await page.locator('.tt-pop').getByRole('button', { name: '12:00 PM', exact: true }).click();
      await page.locator('#bk-attendees').fill(String(Math.min(20, Number(room.attendee_capacity))));
      await expect.text(page, 'This slot is available');
      await page.getByRole('button', { name: /Continue — review/ }).click();
      await expect.text(page, 'Who is this booking for?');
      return page;
    });

    await step('New account: a taken email is refused; a mistyped domain gets a suggestion', async () => {
      const page = s.admin;
      await page.getByRole('button', { name: /New account/ }).click();
      await page.locator('#bkNewName').fill(s.name);
      await page.locator('#bkNewPhone').fill('09175550188');   // Maria Santos's number: a warning only
      await page.locator('#bkNewEmail').fill('msantos@usep.edu.ph');
      await page.getByRole('button', { name: 'Check the details' }).click();
      await expect.text(page, 'This person already has an account. Search for them instead.');
      await page.locator('#bkNewEmail').fill(`${s.local}@gmial.com`);
      await page.locator('#bkEmailFix').click();
      expect.equal(await page.locator('#bkNewEmail').inputValue(), `${s.local}@gmail.com`, 'the suggestion was not taken');
      return page;
    });

    await step('The confirm step shows the email large to read back, then the account is made', async () => {
      const page = s.admin;
      await page.getByRole('button', { name: 'Check the details' }).click();
      await expect.text(page, 'Read this email address back to the customer');
      expect.equal((await page.locator('#bkConfirmEmail').textContent()).trim(), `${s.local}@gmail.com`, 'the email on the confirm step');
      await expect.text(page, 'Another account already uses this mobile number');
      await page.getByRole('button', { name: /It is right — make the account/ }).click();
      await expect.text(page, 'Account made. A welcome email went to');
      const a = await dbOne(`SELECT c.id, c.counter_venue_id, u.password_hash FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = ?`, [`${s.local}@gmail.com`]);
      expect.ok(a && Number(a.counter_venue_id) === 2 && a.password_hash === null, 'the account (USeP Venues counter, no password): ' + JSON.stringify(a));
      s.customerId = Number(a.id);
      const mail = await dbOne(`SELECT kind FROM email_outbox WHERE to_email = ? ORDER BY id DESC LIMIT 1`, [`${s.local}@gmail.com`]);
      expect.equal(mail && mail.kind, 'counter_welcome', 'the welcome email');
      return page;
    });

    await step('"ID checked" stands in for the password, and the booking is created', async () => {
      const page = s.admin;
      expect.equal(await page.locator('#bkPassword').count(), 0, 'a password box for an account with no password');
      await page.locator('#bkIdIdentity').check();
      await page.locator('#bkIdChecked').check();
      await page.getByRole('button', { name: /Continue — review the booking/ }).click();
      await expect.text(page, 'ID checked: the account has no password');
      await page.locator('#agreeNoRefund').check();
      await page.getByRole('button', { name: /Create booking — approved/ }).click();
      await expect.text(page, 'Booking created and approved', 30_000);
      const b = await dbOne(`SELECT b.id FROM bookings b WHERE b.customer_id = ? ORDER BY b.id DESC LIMIT 1`, [s.customerId]);
      expect.ok(b, 'no booking for the new account');
      const t = await dbOne(`SELECT t.performed_by_user_id AS who FROM booking_timeline t WHERE t.booking_id = ? AND t.action_code = 'counter_identity_id_checked'`, [b.id]);
      const me = await dbOne(`SELECT id FROM users WHERE email = ?`, [CEDU_ADMIN]);
      expect.ok(t && Number(t.who) === Number(me.id), 'the ID check on the booking History: ' + JSON.stringify(t));
      return page;
    });

    await step('The CEDU admin corrects the email on Customer Management, with a reason', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/customer-management'));
      const row = page.locator('.tabulator-row', { hasText: `${s.local}@gmail.com` });
      await expect.visible(row, 'the new account is not listed for the CEDU admin');
      await row.locator('.btn-cust-fix-email').click();
      await modal(ctx, page, { expect: /never signed into/, fill: `${s.local}.fixed@gmail.com`, ok: 'Next' });
      await modal(ctx, page, { expect: /The reason is kept/, fill: 'Flow test: one letter off', ok: 'Fix email' });
      await modal(ctx, page, { expect: /Email corrected/ });
      await expect.visible(page.locator('.tabulator-row', { hasText: `${s.local}.fixed@gmail.com` }), 'the corrected email is not listed');
      const f = await dbOne(`SELECT old_email, new_email, reason FROM customer_email_fixes WHERE customer_id = ?`, [s.customerId]);
      expect.ok(f && f.new_email === `${s.local}.fixed@gmail.com` && f.reason === 'Flow test: one letter off', 'the correction row: ' + JSON.stringify(f));
      return page;
    });
  },
};
