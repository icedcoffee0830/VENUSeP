// Flow — an administrator changes the settings the rest of the system runs on.
// sign in (password + code) → edit an event room → edit a hostel room → disable a
// room, edit it while disabled, enable it again → change the USeP discount (staff
// then see the rate but no "Change rate" button) → change a venue's GCash account
// (password re-asked, the venue's note kept) → create a staff account, suspend it,
// it can no longer sign in → add a FAQ question, customers see it.
//
// Every save is checked twice: in the database and by reloading the page.

const ADMIN = 'admin@gmail.com';
const STAFF = 'staff@gmail.com';
const EVENT_ROOM = 'r2';          // Heritage Function Room, Bahay Alumni
const HOSTEL_ROOM = 'h2';         // Hostel Room 2
const TOGGLE_ROOM = 'r5';         // USeP Gymnasium — disabled and enabled again
const GCASH_VENUE = 'USeP Venues';

/* the shared venusepAlert/venusepConfirm/venusepPrompt modal (assets/js/confirm-modal.js) */
async function modal(ctx, page, { expect: re, fill, ok } = {}) {
  const box = page.locator('.vz-modal-overlay .vz-modal').last();
  await ctx.expect.visible(box, 'no dialog appeared');
  const text = ((await box.textContent()) || '').replace(/\s+/g, ' ').trim();
  if (re) ctx.expect.match(text, re, 'dialog text');
  if (fill !== undefined) await box.locator('#vzPromptInput').fill(fill);
  const el = await box.elementHandle();   // this dialog — a queued one may open right after it
  await box.locator(ok ? `button[data-vz="ok"]:has-text("${ok}")` : 'button[data-vz="ok"]').click();
  await el.waitForElementState('hidden').catch(() => {});   // gone, or the page navigated away
  return text;
}

/* the room card on Venue Management, found by its Enable/Disable button's room code */
const roomCard = (page, code) => page.locator('.vm-card', { has: page.locator(`[data-toggle-id="${code}"]`) });

export default {
  name: 'admin-settings',
  title: 'Admin settings — rooms, discount, GCash account, staff accounts, FAQ',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;
    const tag = ctx.uniq;

    await step('The admin signs in (password + authenticator code)', async () => {
      s.admin = await ctx.loginAdmin(ADMIN);
      await expect.url(s.admin, /\/admin\/dashboard/);
      return s.admin;
    });

    await step('Edits an event room (description + capacity) and saves', async () => {
      const page = s.admin;
      const before = await dbOne(`SELECT r.name, e.attendee_capacity FROM rooms r JOIN event_room_details e ON e.room_id = r.id WHERE r.room_code = ?`, [EVENT_ROOM]);
      expect.ok(before, `no event room ${EVENT_ROOM}`);
      s.eventDesc = `Flow test description ${tag} — a bright room for seminars.`;
      s.eventCap = Number(before.attendee_capacity) + 5;

      await page.goto(ctx.url('/admin/venue-management'));
      await roomCard(page, EVENT_ROOM).locator('a.vm-edit-link').click();
      await expect.url(page, new RegExp(`/admin/room-form\\?id=${EVENT_ROOM}$`));
      await expect.visible(page.getByRole('heading', { name: 'Edit Room Details' }), 'room-form did not open in edit mode');
      expect.equal(await page.locator('#roomName').inputValue(), before.name, 'room name on the form');
      await page.locator('#roomDesc').fill(s.eventDesc);
      await page.locator('#roomCap').fill(String(s.eventCap));
      await page.getByRole('button', { name: 'Save Changes' }).click();
      await modal(ctx, page, { expect: /Saved/ });
      await expect.url(page, /\/admin\/venue-management/);

      const after = await dbOne(`SELECT r.description, e.attendee_capacity FROM rooms r JOIN event_room_details e ON e.room_id = r.id WHERE r.room_code = ?`, [EVENT_ROOM]);
      expect.equal(after.description, s.eventDesc, 'rooms.description');
      expect.equal(after.attendee_capacity, s.eventCap, 'event_room_details.attendee_capacity');
      await expect.visible(roomCard(page, EVENT_ROOM).getByText(`Capacity: ${s.eventCap} persons`), 'the card does not show the new capacity');

      await page.goto(ctx.url('/admin/room-form?id=' + EVENT_ROOM));
      expect.equal(await page.locator('#roomDesc').inputValue(), s.eventDesc, 'description after a reload');
      expect.equal(await page.locator('#roomCap').inputValue(), s.eventCap, 'capacity after a reload');
      return page;
    });

    await step('Edits a hostel room (description + check-in time) and saves', async () => {
      const page = s.admin;
      const before = await dbOne(`SELECT r.name FROM rooms r WHERE r.room_code = ?`, [HOSTEL_ROOM]);
      s.hostelDesc = `Flow test hostel description ${tag} — six bunks, fan, lockers.`;

      await page.goto(ctx.url('/admin/venue-management'));
      await roomCard(page, HOSTEL_ROOM).locator('a.vm-edit-link').click();
      await expect.url(page, new RegExp(`/admin/hostel-room-form\\?id=${HOSTEL_ROOM}$`));
      expect.equal(await page.locator('#roomName').inputValue(), before.name, 'room name on the form');
      await page.locator('#roomDesc').fill(s.hostelDesc);
      await page.locator('#roomCheckIn').fill('15:00');
      await page.getByRole('button', { name: 'Save Changes' }).click();
      await modal(ctx, page, { expect: /Saved/ });
      await expect.url(page, /\/admin\/venue-management/);

      const after = await dbOne(`SELECT r.description, h.check_in_time FROM rooms r JOIN hostel_room_details h ON h.room_id = r.id WHERE r.room_code = ?`, [HOSTEL_ROOM]);
      expect.equal(after.description, s.hostelDesc, 'rooms.description');
      expect.match(after.check_in_time, /^15:00/, 'hostel_room_details.check_in_time');

      await page.goto(ctx.url('/admin/hostel-room-form?id=' + HOSTEL_ROOM));
      expect.equal(await page.locator('#roomDesc').inputValue(), s.hostelDesc, 'description after a reload');
      expect.equal(await page.locator('#roomCheckIn').inputValue(), '15:00', 'check-in after a reload');
      return page;
    });

    await step('Disables a room, opens Edit on the disabled room (edit mode), enables it again', async () => {
      const page = s.admin;
      const room = await dbOne(`SELECT name, is_active FROM rooms WHERE room_code = ?`, [TOGGLE_ROOM]);
      expect.equal(room.is_active, 1, `${TOGGLE_ROOM} should start enabled`);

      await page.goto(ctx.url('/admin/venue-management'));
      await roomCard(page, TOGGLE_ROOM).locator(`[data-toggle-id="${TOGGLE_ROOM}"]`, { hasText: 'Disable' }).click();
      await expect.visible(page.locator(`[data-toggle-id="${TOGGLE_ROOM}"]`, { hasText: 'Enable' }), 'the button did not turn into "Enable"');
      await expect.visible(roomCard(page, TOGGLE_ROOM).getByText('Hidden from customers'), 'the card does not say "Hidden from customers"');
      expect.equal((await dbOne(`SELECT is_active FROM rooms WHERE room_code = ?`, [TOGGLE_ROOM])).is_active, 0, 'rooms.is_active after Disable');

      await roomCard(page, TOGGLE_ROOM).locator('a.vm-edit-link').click();
      await expect.url(page, new RegExp(`/admin/room-form\\?id=${TOGGLE_ROOM}$`));
      await expect.visible(page.getByRole('heading', { name: 'Edit Room Details' }), 'the disabled room opened as "Add Room", not in edit mode');
      expect.equal(await page.locator('#vmRoomId').inputValue(), TOGGLE_ROOM, 'room id on the form');
      expect.equal(await page.locator('#roomName').inputValue(), room.name, 'room name on the form');

      await page.goto(ctx.url('/admin/venue-management'));
      await page.locator(`[data-toggle-id="${TOGGLE_ROOM}"]`, { hasText: 'Enable' }).click();
      await expect.visible(page.locator(`[data-toggle-id="${TOGGLE_ROOM}"]`, { hasText: 'Disable' }), 'the button did not turn back into "Disable"');
      expect.equal((await dbOne(`SELECT is_active FROM rooms WHERE room_code = ?`, [TOGGLE_ROOM])).is_active, 1, 'rooms.is_active after Enable');
      await page.reload();
      expect.equal(await roomCard(page, TOGGLE_ROOM).getByText('Hidden from customers').count(), 0, '"Hidden from customers" after a reload');
      return page;
    });

    await step('Changes the USeP discount rate with a reason → saved + history row', async () => {
      const page = s.admin;
      const cur = Number((await dbOne(`SELECT setting_value AS v FROM system_settings WHERE setting_key = 'discount_percent'`)).v);
      s.rate = cur === 25 ? 30 : 25;
      s.reason = `Flow test rate change ${tag}`;

      await page.goto(ctx.url('/admin/venue-management'));
      await page.locator('#dcOpen').click();
      await page.locator('#dcRate').fill(String(s.rate));
      await page.locator('#dcReason').fill(s.reason);
      /* a save shows "Saved" for ~0.7 s and reloads the page; a refusal shows #dcErr */
      const reloaded = page.waitForEvent('load').then(() => 'reloaded', () => 'timeout');
      await page.locator('#dcForm').getByRole('button', { name: 'Save', exact: true }).click();
      const refused = page.locator('#dcErr').waitFor({ state: 'visible' }).then(() => 'error', () => 'timeout');
      const outcome = await Promise.race([reloaded, refused]);
      if (outcome === 'timeout') ctx.fail('the page neither reloaded nor showed an error after Save');
      if (outcome === 'error') ctx.fail('the rate was not saved: ' + (await page.locator('#dcErr').textContent()));
      const history = page.locator('#dcHistory .vm-setting-row', { hasText: s.reason });
      await expect.visible(history, 'the change is not in the history list after the reload');
      expect.match(await history.textContent(), new RegExp(`${cur}%\\s*→\\s*${s.rate}%`), 'history row');
      expect.equal(await page.locator('#dcCurrent').textContent(), `${s.rate}%`, 'rate shown after the reload');

      const set = await dbOne(`SELECT setting_value AS v FROM system_settings WHERE setting_key = 'discount_percent'`);
      expect.equal(Number(set.v), s.rate, 'system_settings.discount_percent');
      const h = await dbOne(`SELECT h.old_value, h.new_value, u.email FROM system_settings_history h LEFT JOIN users u ON u.id = h.changed_by_user_id
          WHERE h.setting_key = 'discount_percent' AND h.change_note = ?`, [s.reason]);
      expect.ok(h, 'no system_settings_history row with the reason');
      expect.equal(Number(h.new_value), s.rate, 'history new_value');
      expect.equal(Number(h.old_value), cur, 'history old_value');
      expect.equal(h.email, ADMIN, 'history changed_by');
      return page;
    });

    await step('Staff sign in: they see the new rate but no "Change rate" button', async () => {
      s.staff = await ctx.loginStaff(STAFF);
      const page = s.staff;
      await page.goto(ctx.url('/admin/venue-management'));
      await expect.visible(page.locator('#vmDiscount'), 'no discount panel on Venue Management for staff');
      expect.equal(await page.locator('#dcCurrent').textContent(), `${s.rate}%`, 'rate shown to staff');
      expect.equal(await page.getByRole('button', { name: 'Change rate' }).count(), 0, '"Change rate" buttons visible to staff');
      expect.equal(await page.locator('#dcOpen').count(), 0, '#dcOpen elements for staff');
      return page;
    });

    await step(`Payment Settings: changes the ${GCASH_VENUE} GCash account (password re-asked) — the note is kept`, async () => {
      const page = s.admin;
      const before = await dbOne(`SELECT g.id, g.note FROM gcash_accounts g JOIN venues v ON v.id = g.venue_id
          WHERE v.name = ? AND g.is_active = 1 AND g.valid_until IS NULL`, [GCASH_VENUE]);
      expect.ok(before && before.note, `no active GCash account with a note for ${GCASH_VENUE}`);
      s.gcName = 'Flow Tester GCash';
      s.gcNumber = '09170001122';

      await page.goto(ctx.url('/admin/payment-settings'));
      const card = page.locator(`.ps-card[data-venue="${GCASH_VENUE}"]`);
      await expect.visible(card, `no ${GCASH_VENUE} card on Payment Settings`);
      await card.getByRole('button', { name: 'Edit' }).click();
      await card.locator('[data-i="name"]').fill(s.gcName);
      await card.locator('[data-i="number"]').fill(s.gcNumber);
      await card.getByRole('button', { name: 'Save' }).click();
      await modal(ctx, page, { expect: /Change where .* is paid\?/, fill: ctx.password, ok: 'Confirm change' });
      const saved = card.locator('.ps-saved'), refused = card.locator('[data-e]:visible');
      await expect.visible(saved.or(refused), 'the card showed neither "Saved" nor an error');
      if (await refused.count()) ctx.fail('the account was not saved: ' + (await refused.textContent()));

      const after = await dbOne(`SELECT g.id, g.account_name, g.mobile_number, g.note, u.email FROM gcash_accounts g JOIN venues v ON v.id = g.venue_id
          LEFT JOIN users u ON u.id = g.created_by_user_id WHERE v.name = ? AND g.is_active = 1 AND g.valid_until IS NULL`, [GCASH_VENUE]);
      expect.equal(after.account_name, s.gcName, 'active account_name');
      expect.equal(after.mobile_number, s.gcNumber, 'active mobile_number');
      expect.equal(after.note, before.note, 'the venue note (should be kept)');
      expect.ok(after.id !== before.id, 'the old account row was overwritten instead of retired');
      const old = await dbOne(`SELECT is_active, valid_until FROM gcash_accounts WHERE id = ?`, [before.id]);
      expect.ok(Number(old.is_active) === 0 && old.valid_until, 'the old account was not retired');

      await page.reload();
      expect.equal((await card.locator('[data-f="name"]').textContent()).trim(), s.gcName, 'name after a reload');
      expect.equal((await card.locator('[data-f="number"]').textContent()).replace(/\D/g, ''), s.gcNumber, 'number after a reload');
      expect.equal((await card.locator('.ps-note').textContent()).trim(), before.note, 'note after a reload');
      return page;
    });

    await step('FAQ management: adds a question → customers see it on the FAQ page', async () => {
      const page = s.admin;
      s.faqQ = `Flow test question ${tag}: can I book a room for a whole week?`;
      s.faqA = 'Yes — book each day you need; the flow tester added this answer.';

      await page.goto(ctx.url('/admin/faq-management'));
      await page.locator('#fqSection').selectOption('booking');
      await page.locator('#fqPolicy').selectOption('any');
      await page.locator('#fqQuestion').fill(s.faqQ);
      await page.locator('#fqAnswerEditor').click();
      await page.keyboard.type(s.faqA);
      await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Add question' }).click()]);
      await page.waitForLoadState('load');
      await expect.visible(page.locator('.fq-flash.ok'), 'no success message after adding the question');
      await expect.visible(page.locator('#booking .fq-row', { hasText: s.faqQ }), 'the question is not listed under "Booking a venue"');

      const f = await dbOne(`SELECT section_key, answer, policy, is_active, is_builtin FROM faqs WHERE question = ?`, [s.faqQ]);
      expect.ok(f, 'no faqs row for the new question');
      expect.equal(f.section_key, 'booking', 'section_key');
      expect.equal(f.policy, 'any', 'policy');
      expect.equal(f.is_active, 1, 'is_active');
      expect.equal(f.is_builtin, 0, 'is_builtin');
      expect.match(f.answer, /flow tester added this answer/, 'answer');

      const guest = await ctx.newPage('guest');
      await guest.goto(ctx.url('/customer/faq'));
      await expect.text(guest, s.faqQ);
      return guest;
    });

    await step('Staff management: creates a staff account for a venue → it is listed', async () => {
      const page = s.admin;
      s.newEmail = `flow.staff.${tag.replace(/\D/g, '')}@example.com`;
      s.newName = 'Flow Staff ' + tag.slice(-6);
      const venue = await dbOne(`SELECT id FROM venues WHERE name = ?`, [GCASH_VENUE]);

      await page.goto(ctx.url('/admin/staff'));
      await page.getByRole('link', { name: 'Add account' }).click();
      await expect.url(page, /\/admin\/admin-register/);
      await page.locator('input[name="account_role"][value="staff"]').check();
      await page.locator('#staffVenue').selectOption(String(venue.id));
      await page.locator('#adminFullName').fill(s.newName);
      await page.locator('#adminEmail').fill(s.newEmail);
      await page.locator('#adminContactNumber').fill('09171234321');
      await page.locator('#adminPassword').fill(ctx.password);
      await page.locator('#adminConfirmPassword').fill(ctx.password);
      await page.locator('#adminTerms').check();
      await page.getByRole('button', { name: 'Create Account' }).click();
      await expect.text(page, 'Staff account created');
      // the page goes back to the dashboard by itself ~1s later; going elsewhere first gets cancelled by it
      await page.waitForURL(/\/admin\/dashboard/);

      const u = await dbOne(`SELECT u.id, u.account_type, u.is_active, s.venue_id, s.full_name FROM users u JOIN staff s ON s.user_id = u.id WHERE u.email = ?`, [s.newEmail]);
      expect.ok(u, 'no users/staff row for the new account');
      expect.equal(u.account_type, 'staff', 'account_type');
      expect.equal(u.is_active, 1, 'is_active');
      expect.equal(u.venue_id, venue.id, 'staff.venue_id');
      expect.equal(u.full_name, s.newName, 'staff.full_name');
      s.newId = u.id;

      await page.goto(ctx.url('/admin/staff'));
      await page.locator('#table-filter').fill(s.newEmail);
      const row = page.locator('.tabulator-row', { hasText: s.newEmail });
      await expect.visible(row, 'the new account is not in the staff table');
      expect.match(await row.textContent(), new RegExp(`${GCASH_VENUE}.*Active`), 'venue + status in the row');
      return page;
    });

    await step('The new staff member can sign in', async () => {
      const page = await ctx.loginStaff(s.newEmail);
      return page;
    });

    await step('The admin suspends the new account → it can no longer sign in', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/staff'));
      await page.locator('#table-filter').fill(s.newEmail);
      const row = page.locator('.tabulator-row', { hasText: s.newEmail });
      await row.getByRole('button', { name: 'Suspend' }).click();
      const answer = page.waitForResponse((r) => r.url().includes('/admin/staff-save') && r.request().method() === 'POST').catch(() => null);
      const reloaded = page.waitForEvent('load').then(() => true, () => false);   // the table reloads after a change
      await modal(ctx, page, { expect: /Suspend /, ok: 'Suspend' });
      const res = await answer;
      if (!res) ctx.fail('Suspend sent no request to /admin/staff-save');
      if (res.status() !== 200) {
        const body = (await res.text().catch(() => '')).slice(0, 200);
        const alert = await page.locator('.vz-modal .vz-modal-message').last().textContent().catch(() => '');
        ctx.fail(`APP BUG — Suspend did nothing: POST /admin/staff-save answered HTTP ${res.status()} ${body}; the page says "${alert}".`
          + (/"error":"csrf"/.test(body) ? ' (staff-management.blade.php sends its token only as the old "csrf" field; Laravel\'s CSRF middleware reads "_token" / X-CSRF-TOKEN.)' : ''));
      }
      if (!(await reloaded)) ctx.fail('the staff page did not reload after Suspend');
      await page.locator('#table-filter').fill(s.newEmail);
      await expect.visible(page.locator('.tabulator-row', { hasText: s.newEmail }).getByText('Suspended', { exact: true }), 'the row does not say Suspended after the reload');
      expect.equal((await dbOne(`SELECT is_active FROM users WHERE id = ?`, [s.newId])).is_active, 0, 'users.is_active');

      const login = await ctx.newPage('suspended staff ' + s.newEmail);
      await login.goto(ctx.url('/admin/admin-login'));
      await ctx.pickDepartment(login, s.newEmail);
      await login.locator('#adminEmail').fill(s.newEmail);
      await login.locator('#adminPassword').fill(ctx.password);
      await Promise.all([login.waitForLoadState('load'), login.locator('#adminLoginForm button[type="submit"]').click()]);
      await login.waitForLoadState('load');
      await expect.text(login, /Invalid email or password|suspended/i);
      expect.ok(!login.url().includes('/admin/dashboard'), 'the suspended account reached the dashboard');
      return login;
    });

  },
};
