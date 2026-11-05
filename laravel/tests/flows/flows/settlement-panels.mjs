// Flow — the three settlement panels on the request page that had never been clicked in a
// browser (settlePanel in admin/booking-request): Reverse payment, Write-off, and Record
// outside refund. Overdue bookings come from the snapshot; the walk-in for the outside
// refund is made with the counter's own two requests, as walkin-refund does.
const STAFF = 'staff@gmail.com';              // handles venue 1
const ADMIN = 'admin@gmail.com';
const NOTICE = 'You have an unpaid booking';
const refOf = (b) => `VB-${b.y}-${String(b.id).padStart(3, '0')}`;

export default {
  name: 'settlement-panels',
  title: 'Reverse payment (password, VOID), write-off (request, approve, decline), outside refund',
  settings: { refunds_enabled: '1' },

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    const open = (page, ref) => page.goto(ctx.url(`/admin/booking-request?id=${ref}`));
    const panel = async (page, name) => {
      await page.getByRole('button', { name }).click();
      await expect.visible(page.locator('#xSend'), 'the panel did not open');
    };
    const send = (page) => Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.locator('#xSend').click()]);
    const booking = (id) => dbOne(`SELECT reservation_status, payment_status, write_off_reason FROM bookings WHERE id = ?`, [id]);
    /* the customer's first booking page: the Overdue block says so up front */
    const blocked = async (page) => {
      await page.goto(ctx.url('/customer/room-reservation?room=r1'));
      return page.getByText(NOTICE).count();
    };

    /* ================= Reverse payment ================= */

    await step('Reverse payment: the admin must give a reason, a 10-character note and the right password', async () => {
      /* The snapshot has no System Receipts, so an Overdue booking with an account is paid
         first, with the staff screen's own request; that issues the receipt. */
      s.paid = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y, b.payment_method, u.email FROM bookings b
          JOIN rooms r ON r.id = b.room_id JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
         WHERE r.venue_id = 2 AND b.payment_status = 'overdue' AND u.is_active = 1 ORDER BY b.id LIMIT 1`);
      expect.ok(s.paid, 'no Overdue booking with a customer account at venue 2');
      s.admin = await ctx.loginAdmin(ADMIN);
      const page = s.admin;
      await open(page, refOf(s.paid));
      const paid = await page.evaluate(async ([u, id]) => {
        const r = await fetch(u, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _token: CSRF, booking: id, action: 'confirm_payment' }) });
        return r.json();
      }, [ctx.url('/admin/booking-action'), refOf(s.paid)]);
      expect.ok(paid.ok, 'the payment was refused: ' + paid.message);
      s.paid.payment_status = (await booking(s.paid.id)).payment_status;
      ctx.note(`reversing ${refOf(s.paid)} (${s.paid.payment_status}, customer ${s.paid.email})`);
      expect.ok(await dbOne(`SELECT id FROM system_receipts WHERE booking_id = ? AND voided_at IS NULL`, [s.paid.id]), 'no System Receipt to reverse');
      await open(page, refOf(s.paid));
      await panel(page, 'Reverse payment');
      await page.locator('#xSend').click();
      await expect.text(page, 'Choose a reason and write a note of at least 10 characters.');
      await page.locator('#xReason').selectOption('fake');
      await page.locator('#xNote').fill('Too short');
      await page.locator('#xSend').click();
      await expect.text(page, 'Choose a reason and write a note of at least 10 characters.');
      await page.locator('#xNote').fill('The money never reached the GCash app');
      await page.locator('#xSend').click();
      await expect.text(page, 'Enter your password to confirm.');
      await page.locator('#xPass').fill('not-the-password');
      await page.locator('#xSend').click();
      await expect.text(page, /Wrong password\. \d+ attempt\(s\) left/);
      const b = await booking(s.paid.id);
      expect.equal(b.payment_status, s.paid.payment_status, 'payment_status after the wrong password');
      expect.equal((await dbOne(`SELECT COUNT(*) AS n FROM system_receipts WHERE booking_id = ? AND voided_at IS NOT NULL`, [s.paid.id])).n, 0, 'voided receipts after the wrong password');
    });

    await step('The right password reverses it: back to unpaid, the receipt void, the button gone', async () => {
      const page = s.admin;
      await page.locator('#xPass').fill(ctx.password);
      await Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.locator('#xSend').click()]);
      const b = await booking(s.paid.id);
      expect.equal(b.payment_status, s.paid.payment_method === 'cash' ? 'await_cash' : 'await_gcash', 'payment_status');
      const r = await dbOne(`SELECT voided_at, void_reason FROM system_receipts WHERE booking_id = ?`, [s.paid.id]);
      expect.ok(r.voided_at, 'the System Receipt was not voided');
      expect.match(r.void_reason, /^Fake receipt: The money never reached/, 'void_reason');
      expect.equal((await dbOne(`SELECT COUNT(*) AS n FROM payments WHERE booking_id = ? AND payment_record_status = 'confirmed'`, [s.paid.id])).n, 0, 'confirmed payments left');
      await expect.text(page, /Payment reversed by an administrator/);
      expect.equal(await page.getByRole('button', { name: 'Reverse payment' }).count(), 0, 'Reverse payment still offered');
    });

    await step('The customer\'s System Receipt shows VOID', async () => {
      const page = await ctx.loginCustomer(s.paid.email);
      await page.goto(ctx.url(`/customer/receipt?booking=${refOf(s.paid)}`));
      await expect.text(page, /VOID since .*: this payment was reversed\./);
      return page;
    });

    /* ================= Write-off ================= */

    await step('Staff sign in; an Overdue booking is the customer\'s only one at this office, and it blocks them', async () => {
      s.a = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y, u.email FROM bookings b
          JOIN rooms r ON r.id = b.room_id JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
         WHERE r.venue_id = 1 AND b.payment_status = 'overdue' AND u.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM bookings o JOIN rooms ro ON ro.id = o.room_id
                            WHERE o.customer_id = b.customer_id AND o.id <> b.id AND o.payment_status = 'overdue' AND ro.venue_id = 1)
         ORDER BY b.id LIMIT 1`);
      expect.ok(s.a, 'no customer with exactly one Overdue booking at venue 1');
      s.b = await dbOne(`SELECT b.id, YEAR(b.submitted_at) AS y FROM bookings b JOIN rooms r ON r.id = b.room_id
         WHERE r.venue_id = 1 AND b.payment_status = 'overdue' AND b.customer_id <> (SELECT customer_id FROM bookings WHERE id = ?)
         ORDER BY b.id LIMIT 1`, [s.a.id]);
      expect.ok(s.b, 'no second Overdue booking at venue 1 for another customer');
      ctx.note(`write off ${refOf(s.a)} (customer ${s.a.email}), decline on ${refOf(s.b)}`);
      s.staff = await ctx.loginStaff(STAFF);
      s.customer = await ctx.loginCustomer(s.a.email);
      expect.ok(await blocked(s.customer) > 0, 'the Overdue block is not shown before the write-off');
    });

    await step('Staff request a write-off: a reason is required, and then they cannot decide it', async () => {
      const page = s.staff;
      await open(page, refOf(s.a));
      await panel(page, 'Request write-off');
      await page.locator('#xSend').click();
      await expect.text(page, 'Write why this debt cannot be collected.');
      await page.locator('#xNote').fill('The organizer left the school');
      await send(page);
      await expect.text(page, 'Write-off waiting for an administrator');
      expect.equal(await page.getByRole('button', { name: 'Approve write-off' }).count(), 0, 'Approve offered to staff');
      expect.equal(await page.getByRole('button', { name: 'Decline write-off' }).count(), 0, 'Decline offered to staff');
      /* the server refuses too, not only the missing button */
      const r = await page.evaluate(async ([u, id]) => {
        const res = await fetch(u, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _token: CSRF, booking: id, action: 'write_off_approve' }) });
        return res.status;
      }, [ctx.url('/admin/booking-action'), refOf(s.a)]);
      expect.equal(r, 403, 'staff approving by hand');
      const b = await booking(s.a.id);
      expect.equal(b.payment_status, 'overdue', 'payment_status');
      expect.equal(b.write_off_reason, 'The organizer left the school', 'write_off_reason');
    });

    await step('The admin approves: Completed and written off, and the customer\'s block ends', async () => {
      const page = s.admin;
      await open(page, refOf(s.a));
      await Promise.all([page.waitForResponse((r) => r.url().includes('part=1')), page.getByRole('button', { name: 'Approve write-off' }).click()]);
      const b = await booking(s.a.id);
      expect.equal(b.reservation_status, 'completed', 'reservation_status');
      expect.equal(b.payment_status, 'written_off', 'payment_status');
      await page.locator('.br-tl').waitFor({ state: 'visible' });
      expect.equal(await page.getByRole('button', { name: 'Approve write-off' }).count(), 0, 'Approve still offered');
      expect.equal(await blocked(s.customer), 0, 'the Overdue block after the write-off');
    });

    await step('The admin declines another: still Overdue, the request is cleared, staff may ask again', async () => {
      await open(s.staff, refOf(s.b));
      await panel(s.staff, 'Request write-off');
      await s.staff.locator('#xNote').fill('They changed their number');
      await send(s.staff);
      await open(s.admin, refOf(s.b));
      await Promise.all([s.admin.waitForResponse((r) => r.url().includes('part=1')), s.admin.getByRole('button', { name: 'Decline write-off' }).click()]);
      const b = await booking(s.b.id);
      expect.equal(b.payment_status, 'overdue', 'payment_status');
      expect.equal(b.write_off_reason, null, 'write_off_reason');
      await expect.text(s.admin, 'Write-off declined.');
      await open(s.staff, refOf(s.b));
      await expect.visible(s.staff.getByRole('button', { name: 'Request write-off' }), 'staff cannot request again');
    });

    /* ================= Record outside refund ================= */

    await step('Outside refund: the panel refuses until the reason, the reference, the date and the proof are all there', async () => {
      const page = s.staff;
      await page.goto(ctx.url('/customer/room-reservation?room=r1&counter=1'));
      const stamp = Number(ctx.uniq.replace(/\D/g, '').slice(-3));
      const day = String((await dbOne(`SELECT DATE_ADD(CURDATE(), INTERVAL ? DAY) AS d`, [40 + stamp % 200])).d).slice(0, 10);
      const post = (url, data) => page.evaluate(async ([u, d]) => {
        const r = await fetch(u, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ _token: CSRF, ...d }) });
        return r.json();
      }, [ctx.url(url), data]);
      const made = await post('/admin/booking-create', {
        type: 'venue', room: 'r1', method: 'cash', id_checked: '1', usep_id: '0', booker_mode: 'walkin',
        walkin_name: 'Outside Refund', walkin_phone: '09171234567', walkin_no_email: '1',
        event_name: 'Outside refund event', date_start: day, date_end: day, attendees: '20',
        times: JSON.stringify({ [day]: { start: '08:00', end: '12:00' } }),
      });
      expect.ok(made.ok, 'the counter refused the booking: ' + made.message);
      const paid = await post('/admin/booking-action', { booking: made.reference, action: 'confirm_payment' });
      expect.ok(paid.ok, 'the cash was refused: ' + paid.message);
      s.c = { id: made.bookingId, ref: made.reference };

      await open(page, s.c.ref);
      await panel(page, 'Record outside refund');
      await page.locator('#xSend').click();
      await expect.text(page, 'Choose a reason and write the explanation. The customer reads it.');
      await page.locator('#xReason').selectOption('school');
      await page.locator('#xNote').fill('The school cancelled the event');
      await page.locator('#xSend').click();
      await expect.text(page, "Enter the Cashier's reference and the date the money was returned.");
      await page.locator('#xRef').fill('CR-2026-0042');
      s.today = String((await dbOne(`SELECT CURDATE() AS d`)).d).slice(0, 10);
      await page.locator('#xOn').fill(s.today);
      await page.locator('#xSend').click();
      await expect.text(page, "Attach the proof from the Cashier's record.");
      expect.equal((await booking(s.c.id)).payment_status, 'paid_cash', 'payment_status before the proof');
      expect.equal(await dbOne(`SELECT id FROM refunds WHERE booking_id = ?`, [s.c.id]), null, 'a refund row before the proof');
    });

    await step('With the proof it records: Cancelled and Refunded, marked as outside the system', async () => {
      const page = s.staff;
      await page.locator('#xFile').setInputFiles({ name: 'cashier-proof.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 proof') });
      await send(page);
      const b = await booking(s.c.id);
      expect.equal(b.reservation_status, 'cancelled', 'reservation_status');
      expect.equal(b.payment_status, 'refunded', 'payment_status');
      const r = await dbOne(`SELECT refund_status, outside_system, cashier_reference, refunded_on FROM refunds WHERE booking_id = ?`, [s.c.id]);
      expect.equal(r.refund_status, 'completed', 'refund_status');
      expect.equal(r.outside_system, 1, 'outside_system');
      expect.equal(r.cashier_reference, 'CR-2026-0042', 'cashier_reference');
      expect.equal(String(r.refunded_on).slice(0, 10), s.today, 'refunded_on');
      expect.equal(await page.getByRole('button', { name: 'Record outside refund' }).count(), 0, 'Record outside refund still offered');
      await expect.text(page, /Refunded outside the system by the school/);
    });
  },
};
