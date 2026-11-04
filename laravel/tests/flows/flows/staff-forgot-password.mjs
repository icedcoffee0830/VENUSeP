// Flow — Forgot password on the staff sign-in page (DB-DECISIONS 28).
// "Forgot Password?" opens the staff side's own page; a staff email and an unknown
// email get the same reply; only the real account gets a link, and the stored
// email no longer holds it. Following the link is covered by StaffPasswordResetTest
// (only the token's hash is stored, so a browser cannot be handed the link here).

const STAFF = 'staff@gmail.com';

async function request(ctx, page, email) {
  await page.locator('#fpEmail').fill(email);
  await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Send reset link' }).click()]);
  await ctx.expect.url(page, /\/admin\/forgot-password$/);
  await ctx.expect.text(page, 'Check your email');
  await ctx.expect.text(page, 'If that email belongs to a VENUSeP account');
}

export default {
  name: 'staff-forgot-password',
  title: 'Staff sign-in — Forgot password sends a link to staff accounts only',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('"Forgot Password?" on the staff sign-in opens the staff reset page', async () => {
      const page = s.page = await ctx.newPage('guest');
      await page.goto(ctx.url('/admin/admin-login'));
      await Promise.all([page.waitForLoadState('load'), page.getByRole('link', { name: 'Forgot Password?' }).click()]);
      await expect.url(page, /\/admin\/forgot-password$/);
      await expect.text(page, 'Reset your password');
      expect.match(await page.locator('a.auth-back').getAttribute('href'), /\/admin\/admin-login$/, 'Back to login goes to the staff sign-in');
      return page;
    });

    await step('A staff email gets one link; the stored email has it removed', async () => {
      const page = s.page;
      await request(ctx, page, STAFF);
      const r = await dbOne(`SELECT COUNT(*) AS n FROM password_resets p JOIN users u ON u.id = p.user_id
                              WHERE u.email = ? AND p.used_at IS NULL AND p.expires_at > NOW()`, [STAFF]);
      expect.equal(Number(r.n), 1, 'open reset links for ' + STAFF);
      const m = await dbOne(`SELECT o.body_text FROM email_outbox o JOIN users u ON u.id = o.requested_by_user_id
                              WHERE u.email = ? AND o.kind = 'password_reset' ORDER BY o.id DESC LIMIT 1`, [STAFF]);
      expect.ok(m, 'no password_reset email queued');
      expect.ok(m.body_text.includes('[link removed after sending]') && !m.body_text.includes('reset-password?token='), 'the stored email still holds the link');
      return page;
    });

    await step('An unknown email gets the same reply and no link', async () => {
      const page = s.page;
      await page.getByRole('link', { name: 'Send another link' }).click();
      await page.waitForLoadState('load');
      const before = await dbOne('SELECT COUNT(*) AS n FROM password_resets');
      await request(ctx, page, `nobody-${ctx.uniq}@example.com`);
      const after = await dbOne('SELECT COUNT(*) AS n FROM password_resets');
      expect.equal(Number(after.n), Number(before.n), 'password_resets rows after an unknown email');
      return page;
    });
  },
};
