// Flow — what stops a GCash payment that should not count.
// Seed: booking 20 (customer@gmail.com) and booking 13 (jmdelacruz@usep.edu.ph),
// both overdue at USeP Venues (one GCash account). customer@ pays booking 20 with
// reference R. jmdelacruz then sends a receipt with the SAME reference R for his
// own booking 13 — his browser has never seen R, so only the SERVER can catch it:
// the attempt is recorded as rejected (duplicate_ref) and booking 13 stays unpaid.
// Then jmdelacruz tries customer@'s booking by typing its reference into the URLs.
import { copyFileSync } from 'node:fs';
import path from 'node:path';

const A = { email: 'customer@gmail.com', booking: 20 };
const B = { email: 'jmdelacruz@usep.edu.ph', booking: 13 };
const ref13 = (salt) => String(1000000000000 + ((Date.now() + salt * 7919) % 9000000000000));

export default {
  name: 'cacct-payment-guards',
  title: 'GCash reference reused across bookings is refused; another customer\'s booking stays out of reach',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    async function bookingInfo(id) {
      const b = await dbOne(`SELECT b.submitted_at, b.total_amount, b.payment_status, g.account_name, g.mobile_number
          FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN v_gcash_active_accounts g ON g.venue_id = r.venue_id
         WHERE b.id = ?`, [id]);
      b.ref = `VB-${new Date(b.submitted_at).getFullYear()}-${String(id).padStart(3, '0')}`;
      return b;
    }
    async function receiptFile(label, b, ref) {
      const src = await ctx.fixtures.gcashReceipt({ amount: Number(b.total_amount), name: b.account_name, number: b.mobile_number, ref });
      const dst = path.join(path.dirname(src), `${ctx.name}-${label}.png`);
      copyFileSync(src, dst);
      return dst;
    }
    /* Pay page: tick the exact-amount box, upload, wait for the in-browser verdict, submit. */
    async function uploadAndSubmit(page, file) {
      await page.locator('label.pay-agree input[type="checkbox"]').check();
      await page.locator('#gcFile').setInputFiles(file);
      const verdict = page.getByText(/Receipt looks right|A coordinator will check this receipt|This receipt cannot be accepted|You already uploaded this receipt/).first();
      await expect.visible(verdict, 'the receipt check never finished', 120_000);
      const text = (await verdict.textContent()).trim();
      ctx.note('in-browser verdict: ' + text);
      expect.ok(!/cannot be accepted|already uploaded/.test(text), 'the browser refused the receipt: ' + text);
      await page.getByRole('button', { name: 'Submit receipt' }).click();
    }

    await step('Customer A pays booking 20 by GCash with reference R', async () => {
      s.a = await bookingInfo(A.booking);
      s.b = await bookingInfo(B.booking);
      s.R = ref13(1);
      ctx.note(`${s.a.ref} ₱${s.a.total_amount}, reference ${s.R}`);
      const page = await ctx.loginCustomer(A.email);
      await page.goto(ctx.url('/customer/pay?booking=' + encodeURIComponent(s.a.ref)));
      await uploadAndSubmit(page, await receiptFile('a', s.a, s.R));
      await expect.url(page, /sent=(accepted|manual_review)/, 30_000);
      const g = await dbOne(`SELECT verdict, reference_number FROM gcash_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [A.booking]);
      expect.equal(String(g.reference_number), s.R, 'reference stored for booking 20');
      expect.ok(g.verdict !== 'rejected', 'booking 20\'s receipt was rejected: ' + g.verdict);
      const b = await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [A.booking]);
      expect.equal(b.payment_status, 'under_review', 'booking 20 payment_status');
      return page;
    }, { timeout: 180_000 });

    await step('Customer B sends the SAME reference for his own booking 13: refused by the server', async () => {
      s.bPage = await ctx.loginCustomer(B.email);
      const page = s.bPage;
      await page.goto(ctx.url('/customer/pay?booking=' + encodeURIComponent(s.b.ref)));
      await uploadAndSubmit(page, await receiptFile('b', s.b, s.R));
      await expect.text(page, 'That GCash reference number has already been used for a booking.', 30_000);
      expect.match(page.url(), /\/customer\/pay\?booking=/, 'the page moved on as if it had been accepted');
      const g = await dbOne(`SELECT verdict, JSON_CONTAINS(flags_json, '"duplicate_ref"') AS dup, payment_id
          FROM gcash_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [B.booking]);
      expect.ok(g, 'the attempt was not recorded');
      expect.equal(g.verdict, 'rejected', 'verdict on booking 13\'s attempt');
      expect.equal(Number(g.dup), 1, 'duplicate_ref flag');
      expect.equal(g.payment_id, null, 'a payments row was created for a rejected receipt');
      const b = await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [B.booking]);
      expect.equal(b.payment_status, 'overdue', 'booking 13 payment_status (still unpaid)');
      return page;
    }, { timeout: 180_000 });

    await step('Customer B cannot open customer A\'s booking by typing its reference', async () => {
      const page = s.bPage;
      const req = page.context().request;
      const pay = await req.get(ctx.url('/customer/pay?booking=' + encodeURIComponent(s.a.ref)));
      expect.equal(pay.status(), 404, 'pay page for someone else\'s booking');
      const pdf = await req.get(ctx.url('/receipt-pdf?booking=' + encodeURIComponent(s.a.ref)));
      expect.equal(pdf.status(), 404, 'receipt PDF for someone else\'s booking');
      const rc = await req.get(ctx.url('/customer/receipt?booking=' + encodeURIComponent(s.a.ref)));
      expect.equal(rc.status(), 404, 'receipt page for someone else\'s booking');
      await page.goto(ctx.url('/customer/refund-request?booking=' + encodeURIComponent(s.a.ref)));
      await expect.text(page, `We could not find booking ${s.a.ref} on your account.`);
      return page;
    });
  },
};
