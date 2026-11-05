// Flow — a walk-in refund (Aron, 2026-11-01). A walk-in has no account, so staff file it
// on the request page: a wrong receipt number is refused, the right one files it, and the
// money goes back in cash. A lost receipt needs proof, and an administrator approves it.
const STAFF = 'staff@gmail.com';              // handles venue 1
const ADMIN = 'admin@gmail.com';

export default {
  name: 'walkin-refund',
  title: 'Walk-in refund: receipt checked → cash payout; lost receipt → proof → admin approval',
  settings: { refunds_enabled: '1' },

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    /* A paid walk-in booking with its System Receipt: the counter's own two requests,
       sent from the staff's session (the screens under test are the refund's). */
    async function seedPaidWalkIn(salt) {
      const page = s.staff;
      await page.goto(ctx.url('/customer/room-reservation?room=r1&counter=1'));
      const stamp = Number(ctx.uniq.replace(/\D/g, '').slice(-3)) + salt;
      const day = String((await dbOne(`SELECT DATE_ADD(CURDATE(), INTERVAL ? DAY) AS d`, [100 + stamp % 80])).d).slice(0, 10);
      const post = (url, data) => page.evaluate(async ([u, d]) => {
        const r = await fetch(u, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _token: CSRF, ...d }) });
        return r.json();
      }, [ctx.url(url), data]);
      const made = await post('/admin/booking-create', {
        type: 'venue', room: 'r1', method: 'cash', id_checked: '1', usep_id: '0', booker_mode: 'walkin',
        walkin_name: 'Walk In ' + salt, walkin_phone: '09171234567', walkin_no_email: '1',
        event_name: 'Walk-in event', date_start: day, date_end: day, attendees: '20',
        times: JSON.stringify({ [day]: { start: '08:00', end: '12:00' } }),
      });
      expect.ok(made.ok, 'the counter refused the booking: ' + made.message);
      const paid = await post('/admin/booking-action', { booking: made.reference, action: 'confirm_payment' });
      expect.ok(paid.ok, 'the cash was refused: ' + paid.message);
      const rec = await dbOne(`SELECT id, YEAR(issued_at) AS y FROM system_receipts WHERE booking_id = ?`, [made.bookingId]);
      return { id: made.bookingId, ref: made.reference, number: `VSR-${rec.y}-${String(rec.id).padStart(6, '0')}` };
    }
    const openFiled = async (page, ref) => {
      await page.goto(ctx.url(`/admin/booking-request?id=${ref}`));
      await page.getByRole('button', { name: 'File refund' }).click();
      await page.locator('#rfNote').fill('The event moved to another month.');
    };

    await step('Staff file with a wrong receipt number: refused, nothing filed', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      s.a = await seedPaidWalkIn(1);
      await openFiled(s.staff, s.a.ref);
      await s.staff.locator('#rfNumber').fill('VSR-1999-000001');
      await s.staff.locator('#rfSend').click();
      await expect.text(s.staff, 'That receipt number does not match this booking.');
      expect.equal(await dbOne(`SELECT id FROM refunds WHERE booking_id = ?`, [s.a.id]), null, 'a refund row after the refusal');
    });

    await step('The right number files it; the staff return the money in cash', async () => {
      const page = s.staff;
      await page.locator('#rfNumber').fill(s.a.number);
      await Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.locator('#rfSend').click()]);
      await expect.text(page, 'Receipt checked by staff');
      expect.equal(await page.getByRole('button', { name: 'Return for correction' }).count(), 0, '"Return for correction" shown for a walk-in');
      await page.getByRole('button', { name: 'Mark refunded' }).click();
      expect.equal(await page.locator('#payoutMethod').inputValue(), 'cash', 'a cash booking defaults to a cash payout');
      expect.equal(await page.locator('#payoutTo').count(), 0, 'the GCash number field in a cash payout');
      await page.getByRole('button', { name: 'Complete refund' }).click();
      await expect.text(page, 'Refunded — booking closed');
      const row = await dbOne(`SELECT refund_status, refund_to_number FROM refunds WHERE booking_id = ?`, [s.a.id]);
      expect.equal(row.refund_status, 'completed', 'refund_status');
      expect.equal(row.refund_to_number, null, 'refund_to_number for cash');
      expect.equal((await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [s.a.id])).payment_status, 'refunded', 'payment_status');
    });

    await step('Staff can switch a payout to GCash and back', async () => {
      s.b = await seedPaidWalkIn(2);
      await openFiled(s.staff, s.b.ref);
      await s.staff.locator('#rfNumber').fill(s.b.number);
      await Promise.all([s.staff.waitForResponse((r) => r.url().includes('part=1')), s.staff.locator('#rfSend').click()]);
      await s.staff.getByRole('button', { name: 'Mark refunded' }).click();
      await s.staff.locator('#payoutMethod').selectOption('gcash');
      await expect.visible(s.staff.locator('#payoutTo'), 'the GCash number field did not appear');
      await s.staff.locator('#payoutMethod').selectOption('cash');
      expect.equal(await s.staff.locator('#payoutTo').count(), 0, 'the GCash number field after going back to cash');
    });

    await step('A lost receipt: staff attach proof, then the refund waits for an administrator', async () => {
      const page = s.staff;
      s.c = await seedPaidWalkIn(3);
      await openFiled(page, s.c.ref);
      await page.locator('#rfLost').check();
      expect.equal(await page.locator('#rfNumberBox').isVisible(), false, 'the receipt number box stays open when lost');
      await page.locator('#rfSend').click();
      await expect.text(page, 'Attach the proof you found');
      await page.locator('#rfFiles').setInputFiles({ name: 'gmail-search.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 proof') });
      await Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.locator('#rfSend').click()]);
      await expect.text(page, 'Refund on hold — an administrator must approve the proof');
      await expect.text(page, 'Waiting for an administrator to approve the proof');
      expect.equal(await page.getByRole('button', { name: 'Mark refunded' }).count(), 0, '"Mark refunded" before the approval');
      await expect.visible(page.getByRole('link', { name: 'gmail-search.pdf' }), 'the proof has no link on the request');
      await Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.locator('#rfAddProof').setInputFiles({ name: 'cashier-note.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 more') })]);
      await expect.visible(page.getByRole('link', { name: 'cashier-note.pdf' }), 'the added proof has no link on the request');
    });

    await step('The administrator approves it; the payout opens', async () => {
      s.admin = await ctx.loginAdmin(ADMIN);
      const page = s.admin;
      await page.goto(ctx.url(`/admin/booking-request?id=${s.c.ref}`));
      await Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.getByRole('button', { name: 'Approve the proof' }).click()]);
      await page.getByRole('button', { name: 'Mark refunded' }).click();
      await page.getByRole('button', { name: 'Complete refund' }).click();
      await expect.text(page, 'Refunded — booking closed');
      expect.equal((await dbOne(`SELECT refund_status FROM refunds WHERE booking_id = ?`, [s.c.id])).refund_status, 'completed', 'refund_status');
    });
  },
};
