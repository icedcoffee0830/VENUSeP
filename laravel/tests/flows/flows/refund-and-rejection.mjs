// Flow — a rejected GCash receipt, then a refund.
// admin turns refunds ON (Payment Settings, password) → a new customer books an
// event room → staff approve → the customer uploads a receipt for the WRONG
// amount → staff reject it → the customer sees the rejection (bell) and the Pay
// page open again → uploads the corrected receipt → staff confirm → the customer
// files a refund (reason, GCash number, documents) → staff record the payout with
// a GCash receipt → the customer sees the booking refunded.
//
// The switch is reset to OFF so the admin step always flips it in the UI. It must
// be ON before the booking is MADE: each booking snapshots it (refunds_allowed,
// DB-DECISIONS #16), and refunds ON also means pre-pay (#18). A refund can only be
// filed before the event (cb_is_refundable), so the date is two weeks out.
import { copyFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const ROOM_CODE = 'r1';                       // Alumni Grand Ballroom, Bahay Alumni (venue 1)
const STAFF = 'staff@gmail.com';              // handles venue 1
const ADMIN = 'admin@gmail.com';
const PHONE = '09171112233';                  // registered number = where the refund goes

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const ref13 = (salt) => String(1000000000000 + ((Date.now() + salt * 7919) % 9000000000000));

export default {
  name: 'refund-and-rejection',
  title: 'Rejected GCash receipt → resubmit → confirm → refund request → refund paid',
  settings: { refunds_enabled: '0' },

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    s.email = `refund.${ctx.uniq.replace(/\D/g, '')}@example.com`;
    s.name = 'Refund Tester';
    s.eventName = 'Flow refund seminar ' + ctx.uniq;

    /* a GCash receipt drawn to its own file (the fixture always writes the same name) */
    async function receiptFile(label, opts) {
      const src = await ctx.fixtures.gcashReceipt(opts);
      const dst = path.join(path.dirname(src), `${ctx.name}-${label}.png`);
      copyFileSync(src, dst);
      return dst;
    }

    /* Pay page: tick the exact-amount box, upload, wait for the in-browser OCR, submit. */
    async function uploadReceipt(page, file) {
      await page.locator('label.pay-agree input[type="checkbox"]').check();
      await page.locator('#gcFile').setInputFiles(file);
      const verdict = page.getByText(/Receipt looks right|A coordinator will check this receipt|This receipt cannot be accepted|You already uploaded this receipt/).first();
      await expect.visible(verdict, 'the receipt check never finished', 120_000);
      const text = (await verdict.textContent()).trim();
      ctx.note('in-browser verdict: ' + text);
      expect.ok(!/cannot be accepted|already uploaded/.test(text), 'the browser refused the receipt: ' + text);
      await page.getByRole('button', { name: 'Submit receipt' }).click();
      await expect.url(page, /sent=(accepted|manual_review)/, 30_000);
      return text;
    }

    await step('The admin turns refund requests ON in Payment Settings (password confirmed)', async () => {
      s.admin = await ctx.loginAdmin(ADMIN);
      const page = s.admin;
      await page.goto(ctx.url('/admin/payment-settings'));
      await expect.visible(page.locator('#rsCard .rs-pill', { hasText: 'OFF' }), 'the Refund Requests card does not say OFF');
      await page.locator('#rsOpen').click();
      await expect.text(page, 'Enable refund requests?');
      await page.locator('#rsPassword').fill(ctx.password);
      await page.locator('#rsConfirm').click();
      await expect.url(page, /refunds=saved/, 20_000);
      await expect.text(page, 'Saved. Refund requests are now ON.');
      await expect.visible(page.locator('#rsCard .rs-pill', { hasText: 'ON' }), 'the card does not say ON after saving');
      const set = await dbOne(`SELECT setting_value FROM system_settings WHERE setting_key = 'refunds_enabled'`);
      expect.equal(set.setting_value, '1', 'system_settings.refunds_enabled');
      const hist = await dbOne(`SELECT COUNT(*) AS n FROM system_settings_history WHERE setting_key = 'refunds_enabled' AND new_value = '1'`);
      expect.ok(Number(hist.n) >= 1, 'no system_settings_history row for the switch');
    });

    await step('A new customer registers and signs in', async () => {
      const page = await ctx.newPage('new customer');
      await page.goto(ctx.url('/customer/customer-register'));
      await page.locator('#fullName').fill(s.name);
      await page.locator('#email').fill(s.email);
      await page.locator('#contactNumber').fill(PHONE);
      await page.locator('#password').fill(ctx.password);
      await page.locator('#confirmPassword').fill(ctx.password);
      await page.locator('#terms').check();
      await page.getByRole('button', { name: 'Create Account' }).click();
      await expect.text(page, 'Account created successfully');
      await page.context().close();
      s.cust = await ctx.loginCustomer(s.email);
      await expect.url(s.cust, /\/customer\/venues/);
      return s.cust;
    });

    await step('Books the Alumni Grand Ballroom two weeks out and submits it with a valid ID', async () => {
      const page = s.cust;
      await page.goto(ctx.url(`/customer/room-reservation?room=${ROOM_CODE}`));
      await expect.text(page, 'Reserve this room');
      const room = await dbOne(`SELECT r.id, e.attendee_capacity FROM rooms r JOIN event_room_details e ON e.room_id = r.id WHERE r.room_code = ?`, [ROOM_CODE]);
      const d = new Date(); d.setDate(d.getDate() + 14);
      for (let i = 0; i < 60; i++, d.setDate(d.getDate() + 1)) {
        const day = iso(d);
        const busy = await dbOne(`SELECT
            (SELECT COUNT(*) FROM venue_booking_slots WHERE room_id = ? AND slot_date = ? AND released_at IS NULL) +
            (SELECT COUNT(*) FROM maintenance_windows WHERE room_id = ? AND from_date <= ? AND (until_date IS NULL OR until_date >= ?)) AS n`,
          [room.id, day, room.id, day, day]);
        if (Number(busy.n) === 0) { s.date = day; break; }
      }
      expect.ok(s.date, 'no free date found in the next 60 days');
      ctx.note(`date ${s.date}, 9:00 AM – 1:00 PM`);
      await page.locator('#bk-eventName').fill(s.eventName);
      await page.locator('#bk-date').click();
      const day = page.locator(`.cal-pop button[onclick="calPick('${s.date}')"]`);
      for (let i = 0; i < 4 && !(await day.isVisible()); i++) await page.locator('.cal-pop .cal-nav').last().click();
      await day.click();
      await page.locator('#bk-start').click();
      await page.locator('.tt-pop').getByRole('button', { name: '9:00 AM', exact: true }).click();
      await page.locator('#bk-end').click();
      await page.locator('.tt-pop').getByRole('button', { name: '1:00 PM', exact: true }).click();
      await page.locator('#bk-attendees').fill(String(Math.min(40, Number(room.attendee_capacity))));
      await expect.text(page, 'This slot is available');
      await page.getByRole('button', { name: /Continue — review/ }).click();
      await expect.text(page, 'Valid ID');
      await page.getByText('Not affiliated', { exact: true }).click();
      await page.locator('#idFileInput').setInputFiles(await ctx.fixtures.idImage(s.name));
      await page.getByRole('button', { name: 'Submit booking request' }).click();
      await expect.text(page, 'Request submitted — awaiting approval', 30_000);
      const refCell = page.locator('div', { hasText: /^Reference$/ }).locator('xpath=following-sibling::div[1]').first();
      s.ref = (await refCell.textContent())?.trim();
      expect.match(s.ref, /^VB-\d{4}-\d+$/, 'reference on the confirmation screen');
      s.bookingId = Number(s.ref.split('-')[2]);
      ctx.note('reference ' + s.ref);
      const b = await dbOne(`SELECT reservation_status, payment_status, refunds_allowed FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.reservation_status, 'pending', 'reservation_status');
      expect.equal(b.refunds_allowed, 1, 'refunds_allowed snapshot (the switch was ON when it was made)');
    });

    await step('Staff approve the ID and reservation', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      const page = s.staff;
      await page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(s.ref)));
      await page.getByRole('button', { name: /Approve ID & reservation/ }).click();
      await expect.text(page, /ID \+ reservation approved|Reservation approved/, 20_000);
      const b = await dbOne(`SELECT reservation_status, payment_status FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.reservation_status, 'approved', 'reservation_status');
      expect.equal(b.payment_status, 'await_gcash', 'payment_status after approval (pre-pay)');
    });

    await step('The customer uploads a GCash receipt for the WRONG amount (half)', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      await page.locator(`tr[data-booking-id="${s.ref}"] a.booking-action-pay`).click();
      await expect.url(page, /\/customer\/pay\?booking=/);
      await expect.text(page, 'Attempt 1 of 5');
      const b = await dbOne(`SELECT total_amount FROM bookings WHERE id = ?`, [s.bookingId]);
      s.total = Number(b.total_amount);
      s.acct = await dbOne(`SELECT g.account_name, g.mobile_number FROM v_gcash_active_accounts g
          JOIN rooms r ON r.venue_id = g.venue_id WHERE r.id = (SELECT room_id FROM bookings WHERE id = ?)`, [s.bookingId]);
      expect.ok(s.acct, 'no active GCash account for this venue');
      s.badRef = ref13(1);
      const file = await receiptFile('receipt-wrong-amount', { amount: s.total / 2, name: s.acct.account_name, number: s.acct.mobile_number, ref: s.badRef });
      await uploadReceipt(page, file);
      const r = await dbOne(`SELECT b.payment_status, COUNT(g.id) AS n FROM bookings b LEFT JOIN gcash_receipts g ON g.booking_id = b.id WHERE b.id = ? GROUP BY b.id`, [s.bookingId]);
      expect.equal(r.payment_status, 'under_review', 'payment_status after the upload');
      expect.equal(r.n, 1, 'gcash_receipts rows');
    }, { timeout: 180_000 });

    await step('Staff reject the receipt', async () => {
      const page = s.staff;
      await page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(s.ref)));
      await expect.text(page, 'Needs manual review');
      await page.getByRole('button', { name: 'Reject receipt' }).click();
      await page.locator('#rejReason').selectOption('Reference number not found in the Transaction History');
      await page.getByRole('button', { name: 'Reject and tell the customer' }).click();
      await expect.text(page, 'Awaiting GCash payment', 20_000);
      const b = await dbOne(`SELECT payment_status, staff_notes FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.payment_status, 'await_gcash', 'payment_status after the rejection (payment reopened)');
      expect.ok(b.staff_notes, 'no reason stored on the booking (staff_notes)');
      s.rejectReason = b.staff_notes;
      const g = await dbOne(`SELECT reviewed_by_user_id, override_note, JSON_CONTAINS(flags_json, '"rejected_by_staff"') AS staff_flag
          FROM gcash_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [s.bookingId]);
      expect.ok(g.reviewed_by_user_id, 'the receipt does not record who rejected it');
      expect.equal(g.staff_flag, 1, 'rejected_by_staff flag on the receipt');
      const p = await dbOne(`SELECT COUNT(*) AS n FROM payments WHERE booking_id = ? AND payment_record_status = 'rejected'`, [s.bookingId]);
      expect.ok(Number(p.n) >= 1, 'no rejected payments row');
    });

    await step('The customer sees the rejection in the bell and the Pay page open again', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      await page.locator('.nb-bell').first().click();
      const item = page.locator('.nb-panel .nb-item', { hasText: `Your GCash receipt for ${s.ref} was not accepted` });
      await expect.visible(item, 'no "receipt was not accepted" notification in the bell');
      expect.match(await item.textContent(), /Please upload another/, 'notification text');
      await item.click();
      await expect.url(page, new RegExp(`/customer/pay\\?booking=${s.ref}`));
      await expect.text(page, 'Attempt 2 of 5');
      await expect.text(page, '4 receipt uploads left');
      await expect.visible(page.locator('label.pay-agree'), 'the GCash upload is not offered again');
    });

    await step('The customer uploads the corrected receipt (exact amount, new reference)', async () => {
      const page = s.cust;
      s.goodRef = ref13(2);
      s.goodReceipt = await receiptFile('receipt-corrected', { amount: s.total, name: s.acct.account_name, number: s.acct.mobile_number, ref: s.goodRef });
      await uploadReceipt(page, s.goodReceipt);
      const r = await dbOne(`SELECT b.payment_status, COUNT(g.id) AS n FROM bookings b LEFT JOIN gcash_receipts g ON g.booking_id = b.id WHERE b.id = ? GROUP BY b.id`, [s.bookingId]);
      expect.equal(r.payment_status, 'under_review', 'payment_status after the second upload');
      expect.equal(r.n, 2, 'gcash_receipts rows (both attempts kept)');
      const last = await dbOne(`SELECT reference_number, verdict FROM gcash_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [s.bookingId]);
      expect.equal(String(last.reference_number).replace(/\D/g, ''), s.goodRef, 'reference read from the corrected receipt');
      ctx.note('server verdict: ' + last.verdict);
    }, { timeout: 180_000 });

    await step('Staff confirm the corrected payment', async () => {
      const page = s.staff;
      await page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(s.ref)));
      await page.getByRole('button', { name: 'Confirm payment' }).click();
      await expect.text(page, 'Payment confirmed', 20_000);
      const b = await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.payment_status, 'confirmed', 'payment_status');
      const r = await dbOne(`SELECT COUNT(*) AS n FROM system_receipts WHERE booking_id = ?`, [s.bookingId]);
      expect.equal(r.n, 1, 'system_receipts rows');
    });

    await step('The customer files a refund request (reason, GCash number, documents)', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      const row = page.locator(`tr[data-booking-id="${s.ref}"]`);
      expect.match(await row.getAttribute('data-payment-status'), /Paid/i, 'payment status in My Bookings');
      // the System Receipt PDF is what the customer attaches as the first document
      await page.goto(ctx.url('/customer/receipt?booking=' + encodeURIComponent(s.ref)));
      const pdfHref = await page.getByRole('link', { name: 'Download PDF' }).getAttribute('href');
      const pdf = await page.context().request.get(new URL(pdfHref, page.url()).href);
      expect.equal(pdf.status(), 200, 'System Receipt PDF status');
      const pdfFile = path.join(path.dirname(s.goodReceipt), `${ctx.name}-system-receipt.pdf`);
      writeFileSync(pdfFile, await pdf.body());
      const orFile = await ctx.fixtures.idImage('OFFICIAL RECEIPT ' + s.ref);

      await page.goto(ctx.url('/customer/booking-history'));
      await row.locator('a.booking-action-refund').click();
      await expect.url(page, /\/customer\/refund-request\?booking=/);
      await expect.text(page, 'Your refund request');
      await page.locator('#rr-reason').selectOption('event_cancelled');
      await page.locator('#rr-details').fill('The seminar was cancelled by the organisers, so we no longer need the ballroom.');
      expect.equal(await page.locator('#rr-gcash').inputValue(), PHONE, 'GCash number prefilled from the account');
      await page.locator('input.rr-file[data-key="txn"]').setInputFiles(pdfFile);
      await page.locator('input.rr-file[data-key="gcash"]').setInputFiles(s.goodReceipt);
      await page.locator('input.rr-file[data-key="or"]').setInputFiles(orFile);
      await page.locator('#rr-confirm').check();
      await page.getByRole('button', { name: 'Submit refund request' }).click();
      await expect.visible(page.locator('#rr-done'), 'no "Refund request submitted" panel: ' + ((await page.locator('#rr-error').textContent().catch(() => '')) || ''), 20_000);
      const rf = await dbOne(`SELECT refund_status, reason_category, refund_to_number, official_receipt_pending, amount_requested FROM refunds WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [s.bookingId]);
      expect.ok(rf, 'no refunds row');
      expect.equal(rf.refund_status, 'requested', 'refunds.refund_status');
      expect.equal(rf.reason_category, 'event_cancelled', 'refunds.reason_category');
      expect.equal(rf.refund_to_number, PHONE, 'refunds.refund_to_number');
      expect.equal(rf.official_receipt_pending, 0, 'refunds.official_receipt_pending (OR attached)');
      expect.equal(Number(rf.amount_requested), s.total, 'refunds.amount_requested');
      const b = await dbOne(`SELECT reservation_status, payment_status FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.payment_status, 'refund_requested', 'payment_status');
      expect.equal(b.reservation_status, 'approved', 'reservation_status (a request does not cancel the booking)');
      const docs = await dbOne(`SELECT COUNT(*) AS n FROM booking_documents WHERE booking_id = ? AND document_type = 'refund_support'`, [s.bookingId]);
      expect.equal(docs.n, 3, 'refund_support documents stored');
    });

    await step('Staff record the refund payout with a GCash receipt and complete it', async () => {
      const page = s.staff;
      await page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(s.ref)));
      await expect.text(page, /Refund requested/);
      await page.getByRole('button', { name: 'Mark refunded' }).click();
      await expect.text(page, 'Record the refund payout');
      expect.equal(await page.locator('#payoutTo').inputValue(), PHONE, 'payout number prefilled from the request');
      s.payoutRef = ref13(3);
      const payout = await receiptFile('refund-payout', { amount: s.total, name: s.name, number: PHONE, ref: s.payoutRef });
      await page.locator('#gcFile').setInputFiles(payout);
      const VERDICT = /Receipt verified|Receipt needs a closer look|This receipt does not match/;
      const verdict = page.locator('.br-ovr .br-banner').filter({ hasText: VERDICT }).first();
      await expect.visible(verdict, 'the payout receipt check never finished', 120_000);
      const verdictText = (await verdict.textContent()).trim();
      s.payoutVerdict = verdictText.match(VERDICT)[0];
      ctx.note('payout receipt verdict: ' + verdictText);
      if (s.payoutVerdict !== 'Receipt verified') {
        await page.locator('#payoutNote').fill('Flow test: payout found in the GCash history.');
      }
      await page.getByRole('button', { name: 'Complete refund' }).click();
      await expect.text(page, 'Refunded — booking closed, date released', 20_000);
      const rf = await dbOne(`SELECT refund_status, amount_approved, payout_reference, completed_at FROM refunds WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [s.bookingId]);
      expect.equal(rf.refund_status, 'completed', 'refunds.refund_status');
      expect.equal(Number(rf.amount_approved), s.total, 'refunds.amount_approved');
      expect.ok(rf.completed_at, 'refunds.completed_at not set');
      ctx.note('payout reference stored: ' + rf.payout_reference);
      const b = await dbOne(`SELECT reservation_status, payment_status FROM bookings WHERE id = ?`, [s.bookingId]);
      expect.equal(b.reservation_status, 'cancelled', 'reservation_status');
      expect.equal(b.payment_status, 'refunded', 'payment_status');
      const p = await dbOne(`SELECT COUNT(*) AS n FROM payments WHERE booking_id = ? AND payment_record_status = 'refunded'`, [s.bookingId]);
      expect.equal(p.n, 1, 'payments rows marked refunded');
      const slot = await dbOne(`SELECT COUNT(*) AS n FROM venue_booking_slots WHERE booking_id = ? AND released_at IS NULL`, [s.bookingId]);
      expect.equal(slot.n, 0, 'held slots not released');
    }, { timeout: 180_000 });

    await step('The customer sees the refund completed', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      const row = page.locator(`tr[data-booking-id="${s.ref}"]`);
      await expect.visible(row, `${s.ref} is not listed in Booking History`);
      expect.match(await row.getAttribute('data-payment-status'), /Refunded/i, 'payment status in My Bookings');
      expect.equal(await row.locator('.booking-action-refund').count(), 0, 'a "Request Refund" button is still offered');
      await page.locator('.nb-bell').first().click();
      await expect.visible(page.locator('.nb-panel .nb-item', { hasText: `Your refund request for ${s.ref} was completed` }),
        'no "refund request was completed" notification in the bell');
      await page.goto(ctx.url('/customer/refund-request?booking=' + encodeURIComponent(s.ref)));
      await expect.text(page, /has already been refunded/);
      return page;
    });
  },
};
