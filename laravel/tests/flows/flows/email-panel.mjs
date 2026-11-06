// Flow — the booking page's "Receipt & emails" panel, with no mail settings (flows never have any).
// Resend never says Sent: the row turns "Not sent", says why, and keeps its Resend button.
// Real sending is covered by BookingEmailsTest, through a pretend mail server.

export default {
  name: 'email-panel',
  title: 'Receipt & emails — an email that never left says Not sent, and can be resent',

  async run(ctx) {
    const { step, expect, db, dbOne } = ctx;
    const s = ctx.state;

    await step('A failed email shows why, and Resend turns it Not sent', async () => {
      const b = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y, u.email FROM bookings b
                               JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
                              ORDER BY b.id LIMIT 1`);
      await db(`INSERT INTO email_outbox (kind, booking_id, to_email, subject, body_html, body_text, status, last_error)
                VALUES ('booking_approved', ?, ?, 'Test', '<p>Test</p>', 'Test', 'failed', 'Could not reach the mail server.')`, [b.id, b.email]);

      const page = s.page = await ctx.loginAdmin();
      await page.goto(ctx.url(`/admin/booking-request?id=VB-${b.y}-${b.id}`));
      const row = page.locator('.br-mail', { hasText: 'Booking approved' });
      await row.getByText('Failed', { exact: true }).waitFor();
      await expect.text(row, 'Could not reach the mail server.');

      await row.getByRole('button', { name: 'Resend' }).click();
      await row.getByText('Not sent', { exact: true }).waitFor();
      await expect.text(row, "Not sent: email isn't set up on this laptop.");
      await expect.text(page.locator('.br-mail-flash'), "Not sent: email isn't set up on this laptop.");
      expect.equal(await row.locator('.br-badge.b-green').count(), 0, 'a green Sent chip');
      expect.ok(await row.getByRole('button', { name: 'Resend' }).isEnabled(), 'Resend is still there for when settings exist');

      const mail = await dbOne(`SELECT status, sent_at FROM email_outbox WHERE booking_id = ? AND kind = 'booking_approved' ORDER BY id DESC LIMIT 1`, [b.id]);
      expect.equal(mail.status, 'not_sent', 'the row');
      expect.equal(mail.sent_at, null, 'sent_at');
      return page;
    });
  },
};
