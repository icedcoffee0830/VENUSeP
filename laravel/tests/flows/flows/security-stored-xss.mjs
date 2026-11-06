// SECURITY: stored XSS. A new customer signs up with a name, an address and an event name that
// each try to run script (in text, in an attribute and in a JS string), books a hall, then every
// page that shows them is opened as the customer, the hall's staff and the super admin.
// The payload only sets window.__xss; a page passes when it stays unset and no dialog opens.
const P = (tag) => `${tag}"'><img src=x onerror="window.__xss=(window.__xss||'')+'${tag} '"><svg onload="window.__xss=(window.__xss||'')+'${tag}s '">`;
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/* A POST from inside the page, with its own CSRF token (a meta tag or the page's CSRF const). */
function post(page, url, fields, withId = false) {
  return page.evaluate(async ({ url, fields, png, withId }) => {
    const fd = new FormData();
    fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || (typeof CSRF !== 'undefined' ? CSRF : ''));
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    if (withId) fd.append('id_document', new Blob([Uint8Array.from(atob(png), (c) => c.charCodeAt(0))], { type: 'image/png' }), 'id.png');
    const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
    return { status: res.status, body: await res.json().catch(() => null) };
  }, { url, fields, png: PNG_1PX, withId });
}

async function sweep(ctx, page, who, paths, hits) {
  let dialogs = 0;
  page.on('dialog', (d) => { dialogs++; d.dismiss().catch(() => {}); });
  for (const p of paths) {
    await page.goto(ctx.url(p), { waitUntil: 'load' });
    await ctx.sleep(400);
    /* the booking details modal, where a page has one */
    const view = page.locator('[data-details], .js-view, button:has-text("View")').first();
    if (await view.isVisible().catch(() => false)) { await view.click().catch(() => {}); await ctx.sleep(600); }
    /* and the notification bell's panel (booking news names the customer) */
    const bell = page.locator('.nb-bell:visible').first();
    if (await bell.count()) { await bell.click().catch(() => {}); await ctx.sleep(600); }
    const fired = await page.evaluate(() => window.__xss || '');
    if (fired || dialogs) hits.push(`${who} ${p}: ${fired}${dialogs ? ' dialog' : ''}`);
    dialogs = 0;
  }
}

export default {
  name: 'security-stored-xss',
  title: 'Security: names, addresses and event names a customer types never run as script on any page',

  async run(ctx) {
    const { step, expect, dbOne, db } = ctx;
    const s = ctx.state;
    s.email = `xss.${ctx.uniq}@example.com`.toLowerCase();

    await step('A customer signs up with a hostile name', async () => {
      const page = await ctx.newPage('guest');
      await page.goto(ctx.url('/customer/customer-register'));
      const form = page.locator('form[data-auth-form]');
      await form.locator('[name="full_name"]').fill(P('name'));
      await form.locator('[name="email"]').fill(s.email);
      await form.locator('[name="contact_number"]').fill('09171234567');
      await form.locator('[name="password"]').fill(ctx.password);
      await form.locator('[name="confirm_password"]').fill(ctx.password);
      await form.locator('[name="terms"]').check();
      const [res] = await Promise.all([page.waitForResponse((r) => r.url().includes('/register-submit')),
        form.locator('button[type="submit"]').click()]);
      expect.equal(res.status(), 200, 'register: ' + (await res.text()).slice(0, 200));
      return page;
    });

    await step('They add a hostile address and book a hall with a hostile event name', async () => {
      const page = s.c = await ctx.loginCustomer(s.email);
      await page.goto(ctx.url('/customer/customer-profile'));
      const d = await post(page, ctx.url('/profile-save'), { action: 'details', full_name: P('name'), email: s.email,
        contact_number: '09171234567', address: P('addr') });
      expect.equal(d.status, 200, 'profile: ' + JSON.stringify(d.body));

      const staff = await dbOne(`SELECT id FROM users WHERE email = 'staff@gmail.com'`);
      const rooms = await db(`SELECT r.room_code, r.id FROM rooms r WHERE r.room_type = 'event' AND r.deleted_at IS NULL
                                AND r.is_active = 1 AND fn_staff_on_room(?, r.id) ORDER BY r.id`, [staff.id]);
      expect.ok(rooms.length, 'staff@gmail.com has no event hall');
      for (const room of rooms) {
        await page.goto(ctx.url(`/customer/room-reservation?room=${room.room_code}`));
        const day = new Date(); day.setDate(day.getDate() + 20);
        for (let i = 0; i < 40 && !s.ref; i++, day.setDate(day.getDate() + 1)) {
          const r = await post(page, ctx.url('/customer/booking-submit'), { type: 'venue', room: room.room_code, event_name: P('event'),
            date_start: iso(day), date_end: iso(day), attendees: '20', affiliated: '0', method: 'gcash', agree_no_refund: '1',
            times: JSON.stringify({ [iso(day)]: { start: '09:00', end: '11:00' } }) }, true);
          if (r.status === 200 && r.body?.ok) s.ref = r.body.reference;
          else if (i === 0) ctx.note(`${room.room_code} ${iso(day)}: ${r.status} ${r.body?.message}`);
        }
        if (s.ref) break;
      }
      expect.ok(s.ref, 'no booking was made');
      ctx.note('booking ' + s.ref);
      return page;
    });

    const hits = [];
    await step('The customer\'s own pages run none of it', async () => {
      await sweep(ctx, s.c, 'customer', ['/customer/booking-history', `/customer/booking-history?view=${s.ref}`,
        '/customer/transaction-history', `/customer/pay?booking=${s.ref}`, '/customer/customer-profile', '/customer/calendar',
        `/valid-id-view?booking=${s.ref}`], hits);
      expect.equal(hits.join(' | ') || 'none', 'none', 'script ran');
      return s.c;
    });

    for (const [who, open] of [['staff', () => ctx.loginStaff('staff@gmail.com')], ['super admin', () => ctx.loginAdmin('admin@gmail.com')]]) {
      await step(`The ${who}'s pages run none of it`, async () => {
        const page = await open();
        await sweep(ctx, page, who, ['/admin/dashboard', '/admin/booking-requests', `/admin/booking-request?id=${s.ref}`,
          '/admin/calendar', '/admin/transaction-history', `/valid-id-view?booking=${s.ref}`,
          ...(who === 'super admin' ? ['/admin/customer-management', '/admin/reports', '/admin/activity'] : [])], hits);
        expect.equal(hits.join(' | ') || 'none', 'none', 'script ran');
        s[who] = page;
        return page;
      });
    }

    /* CSV injection: a name a spreadsheet would run as a formula when staff open the export. */
    await step('The ledger\'s CSV export never starts a cell with a formula a customer typed', async () => {
      const formula = '=HYPERLINK("https://example.com","Open")';
      await s.c.goto(ctx.url('/customer/customer-profile'));
      const d = await post(s.c, ctx.url('/profile-save'), { action: 'details', full_name: formula, email: s.email, contact_number: '09171234567' });
      expect.equal(d.status, 200, 'profile: ' + JSON.stringify(d.body));
      const page = s['super admin'];
      await page.goto(ctx.url('/admin/transaction-history'));
      await page.locator('#table-filter').fill(s.ref);
      const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('#export-csv').click()]);
      const csv = (await import('node:fs')).readFileSync(await dl.path(), 'utf8');
      expect.ok(csv.includes('HYPERLINK'), 'the customer\'s row is not in the export: ' + csv.slice(0, 300));
      expect.ok(!/(^|,)"?[=+\-@]HYPERLINK/m.test(csv), 'a cell starts with the formula: ' + csv.split('\n').find((l) => l.includes('HYPERLINK')));
      return page;
    });
  },
};
