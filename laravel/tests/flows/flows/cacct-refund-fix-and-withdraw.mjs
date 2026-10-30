// Flow — the customer's side of a refund that staff sent back for correction.
// Seed booking 31 (msantos@usep.edu.ph, GCash, paid ₱1,440) has a refund
// 'returned_for_correction'. Booking History must offer "Fix and resubmit"; the
// form must open in fix mode; a document the store cannot keep is refused with a
// reason (nothing filed); the corrected request goes back to staff; then the row
// offers "Withdraw request", and withdrawing puts the payment back to Paid.
import { writeFileSync } from 'node:fs';
import path from 'node:path';

const CUSTOMER = 'msantos@usep.edu.ph';
const BOOKING = 31;

export default {
  name: 'cacct-refund-fix-and-withdraw',
  title: 'Refund returned for correction → fix and resubmit → withdraw',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    const row = (page) => page.locator(`tr[data-booking-id="${s.ref}"]`);
    async function openHistoryRow(page) {
      await page.goto(ctx.url('/customer/booking-history'));
      await page.locator('#booking-history-search').fill(s.ref);
      await expect.visible(row(page), `${s.ref} is not listed in Booking History`);
    }

    await step('The customer signs in and sees "Fix and resubmit" on the returned refund', async () => {
      const b = await dbOne(`SELECT submitted_at FROM bookings WHERE id = ?`, [BOOKING]);
      s.ref = `VB-${new Date(b.submitted_at).getFullYear()}-${String(BOOKING).padStart(3, '0')}`;
      const rf = await dbOne(`SELECT refund_status, correction_attempts FROM refunds WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [BOOKING]);
      expect.equal(rf.refund_status, 'returned_for_correction', 'seed refund state');
      s.corrections = Number(rf.correction_attempts);
      s.cust = await ctx.loginCustomer(CUSTOMER);
      const page = s.cust;
      await openHistoryRow(page);
      await expect.visible(row(page).locator('a.booking-action-fix', { hasText: 'Fix and resubmit' }),
        'no "Fix and resubmit" action on the returned refund');
      return page;
    });

    await step('"Fix and resubmit" opens the form in correction mode', async () => {
      const page = s.cust;
      await row(page).locator('a.booking-action-fix').click();
      await expect.url(page, /\/customer\/refund-request\?booking=/);
      await expect.visible(page.locator('#rr-form'), 'the form is not shown (refused?)');
      await expect.visible(page.locator('#rr-fix'), 'the "Staff sent this back for correction" notice is not shown');
      return page;
    });

    await step('A document the store cannot keep (.heic) is refused with a reason; nothing is filed', async () => {
      const page = s.cust;
      s.txn = await ctx.fixtures.idImage('SYSTEM RECEIPT ' + s.ref);
      s.heic = path.join(path.dirname(s.txn), `${ctx.name}-gcash.heic`);
      writeFileSync(s.heic, 'ftypheic — not a format the document store accepts');
      await page.locator('#rr-reason').selectOption('schedule_conflict');
      await page.locator('#rr-details').fill('Our seminar moved to next quarter, so we no longer need this date.');
      await page.locator('#rr-gcash').fill('0917 111 2233');
      await page.locator('input.rr-file[data-key="txn"]').setInputFiles(s.txn);
      await page.locator('input.rr-file[data-key="gcash"]').setInputFiles(s.heic);
      await page.locator('#rr-nohave-or').check();
      await page.locator('#rr-confirm').check();
      await page.getByRole('button', { name: 'Submit refund request' }).click();
      await expect.text(page, 'Upload a JPG, PNG, WEBP or PDF', 20_000);
      expect.ok(await page.locator('#rr-done').isHidden(), 'the page claims the request was submitted');
      const rf = await dbOne(`SELECT refund_status FROM refunds WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [BOOKING]);
      expect.equal(rf.refund_status, 'returned_for_correction', 'refunds.refund_status (nothing filed)');
      const docs = await dbOne(`SELECT COUNT(*) AS n FROM booking_documents WHERE booking_id = ? AND document_type = 'refund_support'`, [BOOKING]);
      expect.equal(Number(docs.n), 0, 'refund_support documents stored');
      return page;
    });

    await step('The corrected request goes back to staff', async () => {
      const page = s.cust;
      await page.locator('input.rr-file[data-key="gcash"]').setInputFiles(await ctx.fixtures.idImage('GCASH ' + s.ref));
      await page.getByRole('button', { name: 'Submit refund request' }).click();
      await expect.visible(page.locator('#rr-done'), 'no "Refund request submitted" panel', 20_000);
      const rf = await dbOne(`SELECT refund_status, correction_attempts, refund_to_number, official_receipt_pending
          FROM refunds WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [BOOKING]);
      expect.equal(rf.refund_status, 'requested', 'refunds.refund_status');
      expect.equal(Number(rf.correction_attempts), s.corrections + 1,'refunds.correction_attempts');
      expect.equal(rf.refund_to_number, '09171112233', 'refunds.refund_to_number');
      expect.equal(Number(rf.official_receipt_pending), 1, 'refunds.official_receipt_pending');
      const b = await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [BOOKING]);
      expect.equal(b.payment_status, 'refund_requested', 'payment_status');
      const docs = await dbOne(`SELECT COUNT(*) AS n FROM booking_documents WHERE booking_id = ? AND document_type = 'refund_support'`, [BOOKING]);
      expect.equal(Number(docs.n), 2, 'refund_support documents stored');
      return page;
    });

    await step('Booking History offers "Withdraw request"; withdrawing puts the payment back to Paid', async () => {
      const page = s.cust;
      await openHistoryRow(page);
      const btn = row(page).locator('.booking-action-withdraw', { hasText: 'Withdraw request' });
      await expect.visible(btn, 'no "Withdraw request" action on the open refund');
      await btn.click();
      await page.locator('.vz-modal .vz-ok').click();
      await page.waitForLoadState('load');
      await expect.visible(row(page), `${s.ref} is not listed after withdrawing`);
      const rf = await dbOne(`SELECT refund_status, withdrawn_at FROM refunds WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [BOOKING]);
      expect.equal(rf.refund_status, 'withdrawn', 'refunds.refund_status');
      expect.ok(rf.withdrawn_at, 'refunds.withdrawn_at not set');
      const b = await dbOne(`SELECT payment_status, reservation_status FROM bookings WHERE id = ?`, [BOOKING]);
      expect.equal(b.payment_status, 'confirmed', 'payment_status after withdrawing');
      expect.equal(b.reservation_status, 'approved', 'reservation_status (untouched)');
      await page.locator('#booking-history-search').fill(s.ref);
      expect.match(await row(page).getAttribute('data-payment-status'), /^Paid$/, 'payment badge after withdrawing');
      // the event date is past, so no new request may be offered
      expect.equal(await row(page).locator('.booking-action-refund, .booking-action-withdraw, .booking-action-fix').count(), 0,
        'a refund action is still offered on a past, withdrawn booking');
      return page;
    });
  },
};
