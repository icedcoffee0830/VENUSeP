// Flow — the staff actions on admin/booking-request fixed on 2026-10-30.
// A. staff extend an OVERDUE booking's deadline (the snapshot has post-pay
//    bookings that are overdue) — the new timeline entry names the staff member
//    and survives a reload — and release another overdue booking's slot.
// B. the customer of the extended booking uploads a GCash receipt for the wrong
//    amount → staff reject it with a reason from the list + a note → the
//    customer's bell shows that reason → staff UNDO the rejection (under review
//    again) → reject again ("Other") → OVERRIDE & confirm with the verified ref.
// C. the admin books a hostel stay two days out at the counter (paid on
//    creation): no "Check the guest in" before the check-in date, and the server
//    refuses a check-in posted anyway.
//
// staff@gmail.com handles venue 1 (Bahay Alumni) only; the hostel is venue 3,
// so part C runs as admin@gmail.com.
import { copyFileSync } from 'node:fs';
import path from 'node:path';

const STAFF = 'staff@gmail.com';
const STAFF_NAME = 'Marites Robles';
const ADMIN = 'admin@gmail.com';
const COUNTER_CUSTOMER = 'rafael.lim@gmail.com';
const HOSTEL_ROOM = 'h2';

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (n) => { const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() + n); return d; };
const refOf = (b) => `VB-${b.y}-${String(b.id).padStart(3, '0')}`;
const ref13 = (salt) => String(1000000000000 + ((Date.now() + salt * 7919) % 9000000000000));

export default {
  name: 'abook-staff-actions',
  title: 'Extend / release an overdue booking, reject a receipt with a reason, undo, override, check-in date rule',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    const open = (page, ref) => page.goto(ctx.url('/admin/booking-request?id=' + encodeURIComponent(ref)));
    const lastEntry = (page) => page.locator('.br-tl li.hi');

    /* ================= A. overdue: extend one, release another ================= */

    await step('Staff sign in; two overdue bookings at their venue are picked', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      s.a = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y, u.email FROM bookings b
          JOIN rooms r ON r.id = b.room_id JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
         WHERE r.venue_id = 1 AND b.reservation_status = 'approved' AND b.payment_status = 'overdue'
           AND b.payment_method = 'gcash' ORDER BY b.id LIMIT 1`);
      expect.ok(s.a, 'no overdue GCash booking with a customer account at venue 1');
      s.b = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y FROM bookings b JOIN rooms r ON r.id = b.room_id
         WHERE r.venue_id = 1 AND b.reservation_status = 'approved' AND b.payment_status = 'overdue' AND b.id <> ?
         ORDER BY b.id LIMIT 1`, [s.a.id]);
      expect.ok(s.b, 'no second overdue booking at venue 1');
      s.refA = refOf(s.a); s.refB = refOf(s.b);
      ctx.note(`extend ${s.refA} (customer ${s.a.email}), release ${s.refB}`);
    });

    await step('Extend 48 h: payment reopens, the timeline names the staff member', async () => {
      const page = s.staff;
      await open(page, s.refA);
      await expect.text(page, 'Payment overdue');
      await page.getByRole('button', { name: 'Extend 48 h' }).click();
      await expect.text(page, 'Awaiting GCash payment', 20_000);
      const b = await dbOne(`SELECT payment_status, current_deadline_at > NOW() + INTERVAL 47 HOUR AS later FROM bookings WHERE id = ?`, [s.a.id]);
      expect.equal(b.payment_status, 'await_gcash', 'payment_status after the extension');
      expect.equal(b.later, 1, 'current_deadline_at is not ~48 h away');
      const w = (await lastEntry(page).locator('.w').textContent()).trim();
      expect.match(w, new RegExp(`— ${STAFF_NAME}$`), 'newest timeline entry (who)');
      expect.ok(!/You \(staff\)|Jul 14, 2026/.test(await page.locator('.br-tl').textContent()), 'a fake timeline stamp is still drawn');
      await expect.text(page, /Pay-by deadline extended 48 hours/);
      s.extendW = w;
    });

    await step('The same entry is there after a reload', async () => {
      const page = s.staff;
      await page.reload();
      await expect.text(page, s.extendW);
      await expect.text(page, /Pay-by deadline extended 48 hours/);
    });

    await step('Release the slot of the other overdue booking (after a confirmation)', async () => {
      const page = s.staff;
      await open(page, s.refB);
      await page.getByRole('button', { name: 'Release the slot' }).click();
      const ok = page.locator('.vz-modal [data-vz="ok"]');
      await expect.visible(ok, 'no "Release the slot?" confirmation');
      await ok.click();
      await expect.text(page, /this booking is closed/, 20_000);
      const b = await dbOne(`SELECT reservation_status, payment_status FROM bookings WHERE id = ?`, [s.b.id]);
      expect.equal(b.reservation_status, 'released', 'reservation_status');
      expect.equal(b.payment_status, 'expired', 'payment_status');
      const free = await dbOne(`SELECT COUNT(*) AS n FROM venue_booking_slots WHERE booking_id = ? AND released_at IS NULL`, [s.b.id]);
      expect.equal(free.n, 0, 'held slots still active');
      await expect.text(page, /Payment overdue; the slot was released by staff/);
    });

    /* ================= B. reject with a reason, undo, override ================= */

    await step('The customer uploads a GCash receipt for the wrong amount', async () => {
      s.cust = await ctx.loginCustomer(s.a.email);
      const page = s.cust;
      await page.goto(ctx.url('/customer/pay?booking=' + encodeURIComponent(s.refA)));
      const b = await dbOne(`SELECT total_amount, room_id FROM bookings WHERE id = ?`, [s.a.id]);
      const acct = await dbOne(`SELECT g.account_name, g.mobile_number FROM v_gcash_active_accounts g
          JOIN rooms r ON r.venue_id = g.venue_id WHERE r.id = ?`, [b.room_id]);
      expect.ok(acct, 'no active GCash account for this venue');
      const src = await ctx.fixtures.gcashReceipt({ amount: Number(b.total_amount) / 2, name: acct.account_name, number: acct.mobile_number, ref: ref13(3) });
      const file = path.join(path.dirname(src), `${ctx.name}-wrong-amount.png`);
      copyFileSync(src, file);
      await page.locator('label.pay-agree input[type="checkbox"]').check();
      await page.locator('#gcFile').setInputFiles(file);
      const verdict = page.getByText(/Receipt looks right|A coordinator will check this receipt|This receipt cannot be accepted|You already uploaded this receipt/).first();
      await expect.visible(verdict, 'the receipt check never finished', 120_000);
      ctx.note('in-browser verdict: ' + (await verdict.textContent()).trim());
      await page.getByRole('button', { name: 'Submit receipt' }).click();
      await expect.url(page, /sent=(accepted|manual_review)/, 30_000);
      const r = await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [s.a.id]);
      expect.equal(r.payment_status, 'under_review', 'payment_status after the upload');
    }, { timeout: 180_000 });

    await step('Staff reject it: "Amount doesn\'t match" plus a note', async () => {
      const page = s.staff;
      await open(page, s.refA);
      await expect.text(page, 'Needs manual review');
      await page.getByRole('button', { name: 'Reject receipt' }).click();
      await page.getByRole('button', { name: 'Reject and tell the customer' }).click();
      await expect.text(page, 'Choose a reason — the customer sees it.');            // the list is required
      await page.locator('#rejReason').selectOption('Amount doesn\'t match');
      await page.locator('#rejNote').fill('You sent half of the amount.');
      s.reason = 'Amount doesn\'t match — You sent half of the amount.';
      await page.locator('#rejReason').dispatchEvent('change');                      // redraws the preview
      await expect.text(page, 'The customer will see: ' + s.reason);
      await page.getByRole('button', { name: 'Reject and tell the customer' }).click();
      await expect.text(page, 'Receipt rejected by staff', 20_000);
      await expect.visible(page.getByRole('button', { name: /Undo rejection/ }), 'no Undo rejection button');
      const b = await dbOne(`SELECT payment_status, staff_notes FROM bookings WHERE id = ?`, [s.a.id]);
      expect.equal(b.payment_status, 'await_gcash', 'payment_status after the rejection');
      expect.equal(b.staff_notes, s.reason, 'the reason stored on the booking');
    });

    await step('The customer sees that reason in the bell', async () => {
      const page = s.cust;
      await page.goto(ctx.url('/customer/booking-history'));
      await page.locator('.nb-bell').first().click();
      const item = page.locator('.nb-panel .nb-item', { hasText: `Your GCash receipt for ${s.refA} was not accepted` });
      await expect.visible(item, 'no "receipt was not accepted" notification');
      expect.ok((await item.textContent()).includes(s.reason), 'the bell does not show the chosen reason');
    });

    await step('Staff undo the rejection: the receipt is under review again', async () => {
      const page = s.staff;
      await page.reload();
      await page.getByRole('button', { name: /Undo rejection/ }).click();
      await expect.text(page, 'Needs manual review', 20_000);
      const b = await dbOne(`SELECT b.payment_status, JSON_CONTAINS(g.flags_json, '"rejected_by_staff"') AS flagged, g.reviewed_at
          FROM bookings b JOIN gcash_receipts g ON g.booking_id = b.id WHERE b.id = ? ORDER BY g.id DESC LIMIT 1`, [s.a.id]);
      expect.equal(b.payment_status, 'under_review', 'payment_status after the undo');
      expect.equal(b.flagged, 0, 'the receipt still carries rejected_by_staff');
      expect.equal(b.reviewed_at, null, 'reviewed_at was not cleared');
      await expect.text(page, 'Rejection withdrawn; the receipt is under review again.');
    });

    await step('Reject again ("Other" + note), then Override & confirm with the verified reference', async () => {
      const page = s.staff;
      await page.getByRole('button', { name: 'Reject receipt' }).click();
      await page.locator('#rejReason').selectOption('Other');
      await page.locator('#rejNote').fill('Checking with the cashier first.');
      await page.getByRole('button', { name: 'Reject and tell the customer' }).click();
      await expect.text(page, 'Receipt rejected by staff', 20_000);
      await page.locator('#ovrRef').fill('9876543210123');
      await page.locator('#ovrNote').fill('Found the full transfer in the GCash history.');
      await page.getByRole('button', { name: /Override & confirm/ }).click();
      await expect.text(page, 'Payment confirmed', 20_000);
      const b = await dbOne(`SELECT payment_status FROM bookings WHERE id = ?`, [s.a.id]);
      expect.equal(b.payment_status, 'confirmed', 'payment_status after the override');
      const r = await dbOne(`SELECT COUNT(*) AS n FROM system_receipts WHERE booking_id = ?`, [s.a.id]);
      expect.equal(r.n, 1, 'system_receipts rows');
      const g = await dbOne(`SELECT override_note FROM gcash_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1`, [s.a.id]);
      expect.match(g.override_note, /^Override — verified ref 9876543210123/, 'override_note on the receipt');
      return page;
    });

    /* ================= C. no check-in before the check-in date ================= */

    await step('The admin books a hostel stay two days out at the counter (paid on creation)', async () => {
      s.admin = await ctx.loginAdmin(ADMIN);
      const page = s.admin;
      const room = await dbOne(`SELECT id FROM rooms WHERE room_code = ?`, [HOSTEL_ROOM]);
      for (let off = 2; off <= 6 && !s.checkIn; off++) {
        const n1 = iso(addDays(off));
        const r = await dbOne(`SELECT (SELECT COUNT(*) FROM hostel_beds WHERE room_id = ?) -
            (SELECT COUNT(DISTINCT bed_id) FROM v_active_hostel_bed_nights WHERE room_id = ? AND night_date = ?) AS free,
            (SELECT COUNT(*) FROM maintenance_windows WHERE room_id = ? AND blocks_booking = 1 AND from_date <= ? AND (until_date IS NULL OR until_date >= ?)) AS closed`,
          [room.id, room.id, n1, room.id, n1, n1]);
        if (Number(r.free) >= 1 && Number(r.closed) === 0) { s.checkIn = n1; s.checkOut = iso(addDays(off + 1)); }
      }
      expect.ok(s.checkIn, 'no free bed 2–6 days out');
      ctx.note(`stay ${s.checkIn} → ${s.checkOut}`);
      await page.goto(ctx.url(`/customer/hostel-reservation?room=${HOSTEL_ROOM}&counter=1`));
      await expect.text(page, 'Counter booking');
      for (const [field, day] of [['checkIn', s.checkIn], ['checkOut', s.checkOut]]) {
        await page.locator(`button[onclick*="openCal('${field}')"]`).click();
        const btn = page.locator(`.cal-pop button[onclick*="calPick('${day}')"]`);
        for (let i = 0; i < 4 && !(await btn.isVisible()); i++) await page.locator('.cal-pop .cal-nav').last().click();
        await btn.click();
      }
      await page.locator('.bed-name').first().fill('Rafael Lim');
      await page.locator('.bed-row').first().getByRole('button', { name: 'Male', exact: true }).click();
      await page.locator('#hbGo').click();
      await expect.text(page, 'Who is this booking for?');
      await page.locator('#bkSearch').fill('Rafael');
      await page.locator('button', { hasText: COUNTER_CUSTOMER }).first().click();
      await page.locator('#bkPassword').fill(ctx.password);
      await page.getByRole('button', { name: 'Confirm identity' }).click();
      await expect.text(page, 'Identity confirmed');
      await page.locator('#bkIdChecked').check();
      await page.getByRole('button', { name: /Continue — review the booking/ }).click();
      await page.locator('#agreeNoRefund').check();
      await page.getByRole('button', { name: /Cash received — book it/ }).click();
      await page.locator('.vz-modal [data-vz="ok"]').click();
      await expect.text(page, 'Stay booked and paid', 30_000);
      const refCell = page.locator('div', { hasText: /^Reference$/ }).locator('xpath=following-sibling::div[1]').first();
      s.stayRef = (await refCell.textContent())?.trim();
      expect.match(s.stayRef, /^VB-\d{4}-\d+$/, 'reference on the counter done screen');
      s.stayId = Number(s.stayRef.split('-')[2]);
    });

    await step('Before the check-in date: no check-in button, and the server refuses one', async () => {
      const page = s.admin;
      await open(page, s.stayRef);
      await expect.text(page, /check-in opens on/);
      expect.equal(await page.getByRole('button', { name: 'Check the guest in' }).count(), 0, '"Check the guest in" buttons');
      const res = await page.evaluate(async ({ url, ref }) => {
        const body = new URLSearchParams({ _token: CSRF, booking: ref, action: 'check_in' });   // eslint-disable-line no-undef
        const r = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
        return { status: r.status, out: await r.json() };
      }, { url: ctx.url('/admin/booking-action'), ref: s.stayRef });
      expect.equal(res.status, 409, 'HTTP status of a posted early check-in');
      expect.match(res.out.message, /^Too early: check-in opens on/, 'refusal message');
      const h = await dbOne(`SELECT checked_in FROM hostel_booking_details WHERE booking_id = ?`, [s.stayId]);
      expect.equal(h.checked_in, 0, 'checked_in');
      return page;
    });
  },
};
