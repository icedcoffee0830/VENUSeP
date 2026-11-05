// Flow — the admin's overview pages and system switches, checked against the database.
// admin signs in → Dashboard tiles (today's bookings, this quarter's revenue) match the
// database → Quarterly Reports tiles match it for the current and the previous quarter,
// and the print layout names the chosen quarter → the office toggle (Hostel, CEDU) matches
// the database, and one-office staff get only their own office → staff make a backup and cannot
// download it → the admin downloads it, then deletes it → a customer
// opens a notification (marked read in the database, lands on the right page) and
// marks the rest read.

const ADMIN = 'admin@gmail.com';
const STAFF = 'staff@gmail.com';
const CUSTOMER = 'jmdelacruz@usep.edu.ph';        // two unread "payment open" notifications in the snapshot

const digits = (s) => String(s).replace(/\D/g, '');

/* the shared venusepAlert/venusepConfirm modal (assets/js/confirm-modal.js) */
async function modal(ctx, page, { expect: re, ok } = {}) {
  const box = page.locator('.vz-modal-overlay .vz-modal').last();
  await ctx.expect.visible(box, 'no dialog appeared', 60_000);
  const text = ((await box.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (re) ctx.expect.match(text, re, 'dialog text');
  const el = await box.elementHandle();
  await box.locator(ok ? `button[data-vz="ok"]:has-text("${ok}")` : 'button[data-vz="ok"]').click();
  await el.waitForElementState('hidden').catch(() => {});
  return text;
}

/* collected money for one quarter, by event date — the rule report_base_sql() uses:
   paid, and counted until the refund payout is complete (DB-DECISIONS #16) */
const QUARTER_REVENUE = `SELECT COALESCE(SUM(b.total_amount), 0) AS v FROM bookings b
    LEFT JOIN venue_booking_details vd ON vd.booking_id = b.id
    LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
   WHERE b.payment_status IN ('confirmed', 'paid_cash', 'refund_requested', 'refund_correction',
                              'refund_await_or', 'refund_processing', 'refund_denied')
     AND YEAR(COALESCE(vd.start_date, hd.check_in_date)) = ? AND QUARTER(COALESCE(vd.start_date, hd.check_in_date)) = ?`;
/* the same, for some offices only (venue ids, whole numbers from this file) */
const officeRevenue = (ids) => QUARTER_REVENUE.replace('WHERE', `JOIN rooms r ON r.id = b.room_id WHERE r.venue_id IN (${ids.join(',')}) AND`);
/* a booking of room r whose event falls in the quarter */
const QUARTER_ROOM_BOOKINGS = `SELECT 1 FROM bookings b
    LEFT JOIN venue_booking_details vd ON vd.booking_id = b.id
    LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
   WHERE b.room_id = r.id
     AND YEAR(COALESCE(vd.start_date, hd.check_in_date)) = ? AND QUARTER(COALESCE(vd.start_date, hd.check_in_date)) = ?`;

export default {
  name: 'admina-dashboard-reports-backup',
  title: 'Admin overview — dashboard + reports vs the database, backup, demo mode, notifications',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('The admin signs in; the Dashboard tiles match the database', async () => {
      s.admin = await ctx.loginAdmin(ADMIN);
      const page = s.admin;
      await expect.url(page, /\/admin\/dashboard/);
      const today = await dbOne(`SELECT COUNT(*) AS n FROM bookings b
          LEFT JOIN venue_booking_details vd ON vd.booking_id = b.id
          LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
         WHERE (CURDATE() BETWEEN vd.start_date AND vd.end_date OR (hd.check_in_date <= CURDATE() AND hd.check_out_date > CURDATE()))
           AND b.reservation_status IN ('approved', 'completed')`);
      const q = await dbOne(`SELECT YEAR(CURDATE()) AS y, QUARTER(CURDATE()) AS q`);
      const rev = await dbOne(QUARTER_REVENUE, [q.y, q.q]);
      const tile = (title) => page.locator('.stat-card', { has: page.locator('h3', { hasText: title }) }).locator('.number');
      expect.equal(Number(await tile("Today's Bookings").textContent()), Number(today.n), "Today's Bookings tile");
      expect.equal(digits(await tile('Revenue').textContent()), digits(Math.round(Number(rev.v))), 'Revenue tile (current quarter)');
      ctx.note(`today ${today.n}, Q${q.q} ${q.y} revenue ₱${Math.round(Number(rev.v))}`);
      s.y = Number(q.y); s.q = Number(q.q);
      return page;
    });

    await step('Quarterly Reports: current and previous quarter match the database; print names the quarter', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/reports'));
      const cur = `Q${s.q} ${s.y}`;
      expect.equal(await page.locator('#reportPeriod').inputValue(), cur, 'the period the page opens on');
      await expect.visible(page.locator('#rpStatus', { hasText: 'In progress' }), 'the current quarter is not "In progress"');
      const rev = await dbOne(QUARTER_REVENUE, [s.y, s.q]);
      expect.equal(digits(await page.locator('#totalRevenue').textContent()), digits(Math.round(Number(rev.v))), `Total Revenue ${cur}`);
      /* one row per active hall or hostel room, plus an inactive one with business in the quarter */
      const rooms = await dbOne(`SELECT COUNT(*) AS n FROM rooms r JOIN venues v ON v.id = r.venue_id
          WHERE (r.is_active = 1 AND v.is_active = 1) OR EXISTS (${QUARTER_ROOM_BOOKINGS})`, [s.y, s.q]);
      expect.equal(await page.locator('#hallRows tr, #hostelRows tr').count(), Number(rooms.n), 'one table row per room');

      const py = s.q === 1 ? s.y - 1 : s.y, pq = s.q === 1 ? 4 : s.q - 1;
      const prev = `Q${pq} ${py}`;
      await page.locator('#reportPeriod').selectOption(prev);
      await expect.visible(page.locator('#rpStatus', { hasText: 'Closed' }), `${prev} is not "Closed"`);
      const prevRev = await dbOne(QUARTER_REVENUE, [py, pq]);
      expect.equal(digits(await page.locator('#totalRevenue').textContent()), digits(Math.round(Number(prevRev.v))), `Total Revenue ${prev}`);

      await page.evaluate(() => prepareForPrint());
      expect.ok(await page.evaluate(() => document.documentElement.classList.contains('rp-print')), 'the print layout did not switch on');
      expect.equal(await page.locator('#rpPrintPeriod').textContent(), prev, 'the quarter printed on the paper');
      await page.emulateMedia({ media: 'print' });
      await page.screenshot({ path: `${ctx.outDir}/${ctx.name}-print.png`, fullPage: true });
      await page.emulateMedia({ media: 'screen' });
      await page.evaluate(() => restoreAfterPrint());
      return page;
    });

    await step('Reports office toggle: the admin picks the Hostel, then CEDU; staff get their own office only', async () => {
      const page = s.admin;
      const cur = `Q${s.q} ${s.y}`;
      await page.goto(ctx.url('/admin/reports'));
      await expect.visible(page.locator('.rp-office-tabs a[aria-current="page"]', { hasText: 'All offices' }), 'the admin opens on All offices');
      await page.locator('.rp-office-tabs a', { hasText: 'USeP Hostel' }).click();
      await expect.url(page, /office=usep-hostel/);
      const hostel = await dbOne(officeRevenue([3]), [s.y, s.q]);
      expect.equal(digits(await page.locator('#totalRevenue').textContent()), digits(Math.round(Number(hostel.v))), `Hostel revenue ${cur}`);
      expect.equal(await page.locator('#hallRows').count(), 0, 'the hostel report has no halls table');
      await expect.visible(page.locator('#bathroomTypes', { hasText: 'By bathroom type' }), 'no bathroom types line');

      await page.locator('.rp-office-tabs a', { hasText: 'USeP CEDU (both)' }).click();
      await expect.url(page, /office=cedu/);
      const cedu = await dbOne(officeRevenue([2, 3]), [s.y, s.q]);
      expect.equal(digits(await page.locator('#totalRevenue').textContent()), digits(Math.round(Number(cedu.v))), `CEDU revenue ${cur}`);
      await expect.visible(page.locator('.rp-office-note', { hasText: 'counted once' }), 'no combined-customers line');
      await page.evaluate(() => prepareForPrint());
      await expect.visible(page.locator('.rp-office-name', { hasText: 'USeP CEDU (both)' }), 'the printout does not name the office');
      await page.emulateMedia({ media: 'print' });
      await page.screenshot({ path: `${ctx.outDir}/${ctx.name}-print-cedu.png`, fullPage: true });
      await page.emulateMedia({ media: 'screen' });
      await page.evaluate(() => restoreAfterPrint());

      s.staff = await ctx.loginStaff(STAFF);
      await s.staff.goto(ctx.url('/admin/reports?office=usep-hostel'));   // asks for another office
      expect.equal(await s.staff.locator('.rp-office-tabs').count(), 0, 'one-office staff get no toggle');
      await expect.visible(s.staff.locator('.rp-office-name', { hasText: 'Bahay Alumni' }), 'staff report is not their office');
      const bahay = await dbOne(officeRevenue([1]), [s.y, s.q]);
      expect.equal(digits(await s.staff.locator('#totalRevenue').textContent()), digits(Math.round(Number(bahay.v))), `staff see their office's money ${cur}`);
      ctx.note(`${cur}: hostel ₱${Math.round(Number(hostel.v))}, CEDU ₱${Math.round(Number(cedu.v))}, Bahay ₱${Math.round(Number(bahay.v))}`);
      return s.staff;
    });

    await step('Staff are kept out of backups; the admin makes one', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      await s.staff.goto(ctx.url('/admin/backup-restore'));
      await expect.url(s.staff, /\/admin\/dashboard/);
      const page = s.admin;
      await page.goto(ctx.url('/admin/backup-restore'));
      s.rowsBefore = await page.locator('tr[data-file]').count();
      await page.locator('#brCreate').click();
      await modal(ctx, page, { expect: /Backup made/ });
      await page.waitForLoadState('load');
      await expect.visible(page.locator('tr[data-file]').first(), 'no backup listed after "Back up now"');
      expect.equal(await page.locator('tr[data-file]').count(), s.rowsBefore + 1, 'backups listed');
      s.file = await page.locator('tr[data-file]').first().getAttribute('data-file');
      expect.match(s.file, /^venusep-backup-\d{8}-\d{6}-manual\.tar\.gz$/, 'backup file name');
      return page;
    }, { timeout: 180_000 });

    await step('The admin downloads that backup, then deletes it', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/backup-restore'));
      const row = page.locator(`tr[data-file="${s.file}"]`);
      await expect.visible(row, 'the staff backup is not listed for the admin');
      const [dl] = await Promise.all([page.waitForEvent('download'), row.locator('a', { hasText: 'Download' }).click()]);
      expect.equal(dl.suggestedFilename(), s.file, 'downloaded file name');
      expect.ok(!(await dl.failure()), 'the download failed');

      await row.locator('[data-delete]').click();
      await modal(ctx, page, { expect: /Delete this backup\?/, ok: 'Delete' });
      await page.locator(`tr[data-file="${s.file}"]`).waitFor({ state: 'detached', timeout: 15_000 })
        .catch(() => ctx.fail('the deleted backup is still listed'));
      await page.reload();
      expect.equal(await page.locator(`tr[data-file="${s.file}"]`).count(), 0, 'the deleted backup is listed after a reload');
      return page;
    });

    await step('A customer opens a notification (read in the database, right page), then marks the rest read', async () => {
      const unread = await ctx.db(`SELECT n.id, n.booking_id FROM notifications n JOIN users u ON u.id = n.user_id
          WHERE u.email = ? AND n.read_at IS NULL ORDER BY n.id DESC`, [CUSTOMER]);
      expect.ok(unread.length >= 2, `${CUSTOMER} needs two unread notifications in the snapshot`);
      const b = await dbOne(`SELECT id, YEAR(submitted_at) AS y, payment_status FROM bookings WHERE id = ?`, [unread[0].booking_id]);
      const ref = `VB-${b.y}-${String(b.id).padStart(3, '0')}`;

      const page = await ctx.loginCustomer(CUSTOMER);
      await page.goto(ctx.url('/customer/booking-history'));
      await expect.visible(page.locator('.nb-count', { hasText: String(unread.length) }).first(), 'the unread count on the bell');
      await page.locator('.nb-bell').first().click();
      const item = page.locator('.nb-panel .nb-item.is-unread', { hasText: ref }).first();
      await expect.visible(item, `no unread notification about ${ref}`);
      await item.click();
      /* overdue is closed until staff extend it (Aron 2026-11-05): its notification opens the booking */
      const open = ['await_gcash', 'await_cash'].includes(b.payment_status);
      if (open) {
        await expect.url(page, new RegExp(`/customer/pay\\?booking=${ref}$`));
      } else {
        await expect.url(page, /\/customer\/booking-history/);   // ?view= opens the details, then leaves the address
        await expect.visible(page.locator('.bdm-sub', { hasText: ref }), `the notification did not open ${ref}`);
        await page.locator('[data-bdm-close]').first().click();   // the details cover the bell
      }
      const r = await dbOne(`SELECT read_at FROM notifications WHERE id = ?`, [unread[0].id]);
      expect.ok(r.read_at, 'the clicked notification is still unread in the database');

      await page.locator('.nb-bell').first().click();
      await page.locator('.nb-panel .nb-all').click();
      for (let i = 0; i < 20; i++) {
        const left = await dbOne(`SELECT COUNT(*) AS n FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email = ? AND n.read_at IS NULL`, [CUSTOMER]);
        if (Number(left.n) === 0) return page;
        await ctx.sleep(250);
      }
      ctx.fail('"Mark all as read" left unread notifications in the database');
    });
  },
};
