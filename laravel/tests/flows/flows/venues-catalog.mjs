// Flow — the room catalog, end to end.
// admin adds an event room → uploads two photos, makes the second the cover, removes
// one → a cleared rate is refused (not saved as P0) → a new rate leaves existing
// bookings' prices alone → customers see the room, then not once it is disabled
// (a direct link sends them back to the list) → staff of another venue cannot
// disable or rename USeP Venues' things → the admin deletes the room, and its
// photo files go with it.
//
// Every change is checked in the database, not just on the page.

import { copyFileSync } from 'node:fs';

const ADMIN = 'admin@gmail.com';
const STAFF = 'staff@gmail.com';            // handles Bahay Alumni (venue 1) only
const CUSTOMER = 'customer@gmail.com';
const VENUE = 'Bahay Alumni';
const RATE_ROOM = 'r1';                     // has booking history
const OTHER_VENUE_ROOM = 'r5';              // USeP Venues

async function modal(ctx, page, { expect: re, ok } = {}) {
  const box = page.locator('.vz-modal-overlay .vz-modal').last();
  await ctx.expect.visible(box, 'no dialog appeared');
  const text = ((await box.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (re) ctx.expect.match(text, re, 'dialog text');
  const el = await box.elementHandle();
  await box.locator(ok ? `button[data-vz="ok"]:has-text("${ok}")` : 'button[data-vz="ok"]').click();
  await el.waitForElementState('hidden').catch(() => {});
  return text;
}

const roomCard = (page, code) => page.locator('.vm-card', { has: page.locator(`[data-toggle-id="${code}"]`) });

export default {
  name: 'venues-catalog',
  title: 'Room catalog — add, photos, rate, disable, staff scope, delete',

  async run(ctx) {
    const { step, expect, dbOne, db } = ctx;
    const s = ctx.state;

    await step('The admin signs in', async () => {
      s.admin = await ctx.loginAdmin(ADMIN);
      return s.admin;
    });

    await step('Adds an event room to Bahay Alumni (capacity 40, P2,500/day)', async () => {
      const page = s.admin;
      s.roomName = `Flow Room ${ctx.uniq}`.slice(0, 60);
      await page.goto(ctx.url('/admin/venue-management'));
      await page.getByRole('link', { name: '+ Add Room' }).click();
      await expect.url(page, /\/admin\/room-form$/);
      await page.locator('#roomName').fill(s.roomName);
      await page.locator('#roomLoc .vm-dd-trigger').click();
      await page.locator('#roomLoc .vm-dd-option', { hasText: VENUE }).click();
      await page.locator('#roomCap').fill('40');
      await page.locator('#roomRate').fill('2500');
      await page.getByRole('button', { name: 'Save Changes' }).click();
      await modal(ctx, page, { expect: /Room created as r\d+/ });
      await expect.url(page, /\/admin\/room-form\?id=r\d+$/);
      s.code = new URL(page.url()).searchParams.get('id');

      const row = await dbOne(`SELECT r.name, r.is_active, v.name AS venue, e.attendee_capacity, e.fee_per_day
                                 FROM rooms r JOIN venues v ON v.id = r.venue_id JOIN event_room_details e ON e.room_id = r.id
                                WHERE r.room_code = ?`, [s.code]);
      expect.ok(row, `room ${s.code} is not in the database`);
      expect.equal(row.name, s.roomName, 'rooms.name');
      expect.equal(row.venue, VENUE, 'venue');
      expect.equal(row.attendee_capacity, 40, 'attendee_capacity');
      expect.equal(Number(row.fee_per_day), 2500, 'fee_per_day');
      ctx.note(`created ${s.code}`);
      return page;
    });

    await step('Uploads two photos, makes the second the cover, removes the first', async () => {
      const page = s.admin;
      const a = await ctx.fixtures.idImage('Photo A');
      const b = a.replace(/\.png$/, '-b.png');
      copyFileSync(a, b);
      await page.getByRole('button', { name: /Edit photos/ }).click();
      await expect.visible(page.locator('#vmGalOverlay'), 'the photo gallery did not open');
      const input = page.locator('#vmGalAddInput');
      await input.setInputFiles([a, b]);
      await expect.visible(page.locator('#vmGalCount', { hasText: '(2 of 5)' }), 'the gallery does not show 2 photos');
      const rows = await db(`SELECT m.file_path FROM room_media m JOIN rooms r ON r.id = m.room_id
                              WHERE r.room_code = ? AND m.media_type = 'photo' ORDER BY m.display_order, m.id`, [s.code]);
      expect.equal(rows.length, 2, 'room_media photo rows');
      s.first = rows[0].file_path; s.second = rows[1].file_path;

      await page.locator('#vmGalGrid .vm-galthumb').nth(1).click();   // make it the cover
      await page.waitForResponse((r) => r.url().includes('/admin/room-photos-api') && r.request().method() === 'POST');
      const cover = await dbOne(`SELECT m.file_path FROM room_media m JOIN rooms r ON r.id = m.room_id
                                  WHERE r.room_code = ? AND m.media_type = 'photo' ORDER BY m.display_order, m.id LIMIT 1`, [s.code]);
      expect.equal(cover.file_path, s.second, 'the cover (first by display_order)');

      /* remove the old first photo — now in position 2 */
      await page.locator('#vmGalGrid .vm-galthumb').nth(1).locator('span[onclick^="vmGalDelete"]').click();
      await modal(ctx, page, { ok: 'Remove' });
      await expect.visible(page.locator('#vmGalCount', { hasText: '(1 of 5)' }), 'the gallery does not show 1 photo');
      const left = await db(`SELECT m.file_path FROM room_media m JOIN rooms r ON r.id = m.room_id WHERE r.room_code = ? AND m.media_type = 'photo'`, [s.code]);
      expect.equal(left.length, 1, 'photos left');
      expect.equal(left[0].file_path, s.second, 'the photo kept');
      const gone = await page.request.get(ctx.url('/' + s.first.replace(/^\.\.\//, '')));
      expect.equal(gone.status(), 404, 'the removed photo file should be gone from disk');
      return page;
    });

    await step('Clearing the rate box and saving is refused — the room does not become free', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/room-form?id=' + s.code));
      await page.locator('#roomRate').fill('');
      await page.getByRole('button', { name: 'Save Changes' }).click();
      await modal(ctx, page, { expect: /rate of ₱0 or more/ });
      const row = await dbOne(`SELECT e.fee_per_day FROM event_room_details e JOIN rooms r ON r.id = e.room_id WHERE r.room_code = ?`, [s.code]);
      expect.equal(Number(row.fee_per_day), 2500, 'fee_per_day after the refused save');
      return page;
    });

    await step('A new rate on a room with bookings leaves their prices alone', async () => {
      const page = s.admin;
      const sumSql = `SELECT COUNT(*) AS n, SUM(b.room_price) AS price, SUM(b.total_amount) AS total
                        FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE r.room_code = ?`;
      const before = await dbOne(sumSql, [RATE_ROOM]);
      expect.ok(Number(before.n) > 0, `${RATE_ROOM} should have bookings`);
      const fee = await dbOne(`SELECT e.fee_per_day FROM event_room_details e JOIN rooms r ON r.id = e.room_id WHERE r.room_code = ?`, [RATE_ROOM]);
      s.newRate = Number(fee.fee_per_day) + 1234;

      await page.goto(ctx.url('/admin/room-form?id=' + RATE_ROOM));
      await page.locator('#roomRate').fill(String(s.newRate));
      await page.getByRole('button', { name: 'Save Changes' }).click();
      await modal(ctx, page, { expect: /existing bookings keep the price/ });

      const after = await dbOne(sumSql, [RATE_ROOM]);
      expect.equal(after.price, before.price, 'sum of room_price on the room\'s bookings');
      expect.equal(after.total, before.total, 'sum of total_amount on the room\'s bookings');
      const fee2 = await dbOne(`SELECT e.fee_per_day FROM event_room_details e JOIN rooms r ON r.id = e.room_id WHERE r.room_code = ?`, [RATE_ROOM]);
      expect.equal(Number(fee2.fee_per_day), s.newRate, 'the room\'s new rate');
      return page;
    });

    await step('Customers see the new room on the venue list', async () => {
      s.cust = await ctx.loginCustomer(CUSTOMER);
      await s.cust.goto(ctx.url('/customer/venues'));
      await expect.visible(s.cust.locator(`a.lp-card[href*="room=${s.code}"]`), 'the new room is not on the customer list');
      return s.cust;
    });

    await step('Disabled: the room leaves the customer list and its direct link goes back to the list', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/venue-management'));
      await roomCard(page, s.code).locator(`[data-toggle-id="${s.code}"]`, { hasText: 'Disable' }).click();
      await expect.visible(roomCard(page, s.code).getByText('Hidden from customers'), 'the card does not say "Hidden from customers"');
      expect.equal((await dbOne(`SELECT is_active FROM rooms WHERE room_code = ?`, [s.code])).is_active, 0, 'rooms.is_active');

      const cust = s.cust;
      await cust.goto(ctx.url('/customer/venues'));
      expect.equal(await cust.locator(`a.lp-card[href*="room=${s.code}"]`).count(), 0, 'disabled room cards on the customer list');
      await cust.goto(ctx.url('/customer/room-reservation?room=' + s.code));
      await expect.url(cust, /\/customer\/venues/);
      return cust;
    });

    await step('Staff of Bahay Alumni cannot disable or rename USeP Venues\' things', async () => {
      const page = await ctx.loginStaff(STAFF);
      s.staff = page;
      const before = await dbOne(`SELECT is_active FROM rooms WHERE room_code = ?`, [OTHER_VENUE_ROOM]);
      await page.goto(ctx.url('/admin/venue-management'));
      /* the card has no Edit/Disable for another venue's room (the server refuses it too) */
      expect.equal(await page.locator(`[data-toggle-id="${OTHER_VENUE_ROOM}"]`).count(), 0, `Disable buttons on ${OTHER_VENUE_ROOM}`);
      const after = await dbOne(`SELECT is_active FROM rooms WHERE room_code = ?`, [OTHER_VENUE_ROOM]);
      expect.equal(after.is_active, before.is_active, `${OTHER_VENUE_ROOM} is_active`);

      const venue = await dbOne(`SELECT id, name FROM venues WHERE id = 2`);
      await page.goto(ctx.url('/admin/venue-form?id=' + venue.id));
      await page.locator('#venueName').fill(venue.name + ' X');
      await page.getByRole('button', { name: 'Save Changes' }).click();
      await modal(ctx, page, { expect: /belongs to another venue/ });
      expect.equal((await dbOne(`SELECT name FROM venues WHERE id = 2`)).name, venue.name, 'venues.name');
      return page;
    });

    await step('The admin deletes the room; its row and photo files are gone', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/venue-management'));
      await roomCard(page, s.code).locator('[data-delete-entity="room"]').click();
      await modal(ctx, page, { ok: 'Delete' });
      /* a delete reloads the page; the card is gone once the reload lands */
      await roomCard(page, s.code).waitFor({ state: 'detached', timeout: 15_000 })
        .catch(() => ctx.fail('the deleted room\'s card is still on Venue Management'));
      expect.equal(await dbOne(`SELECT id FROM rooms WHERE room_code = ?`, [s.code]), null, 'rooms row');
      const gone = await page.request.get(ctx.url('/' + s.second.replace(/^\.\.\//, '')));
      expect.equal(gone.status(), 404, 'the room\'s photo file should be gone from disk');
      return page;
    });
  },
};
