// The approved design pass, in a real browser: what only a browser can see. The lists fit the
// screen without sideways scroll, one-click actions sit on the row, and a phone gets cards with
// 44px buttons. One step per design item; the wording and rules are in the feature tests.

import { readFileSync } from 'node:fs';

const DESKTOP = { width: 1280, height: 800 };
const PHONE = { width: 390, height: 844 };
const SUPER = 'admin@gmail.com';
const BAHAY_ADMIN = 'francishubero@gmail.com';
const CUSTOMER = 'customer@gmail.com';

/* the page and the table itself: nothing wider than the screen */
const sideways = (page) => page.evaluate(() => {
  const holder = document.querySelector('.tabulator-tableholder');
  return document.documentElement.scrollWidth > innerWidth || (holder !== null && holder.scrollWidth > holder.clientWidth + 1);
});

/* the buttons shown in the rows that are shorter than 44px */
const shortButtons = (page, within) => page.evaluate((q) => [...document.querySelectorAll(q)]
  .filter((b) => b.getClientRects().length && b.getBoundingClientRect().height < 44).map((b) => b.textContent.trim()), within);

export default {
  name: 'design-pass',
  title: 'Design pass: lists fit, actions stay on the row, phones get cards',

  async run(ctx) {
    const { step, expect, dbOne } = ctx;
    const s = ctx.state;

    await step('Staff Management at 1280: no sideways scroll, role chips whole, one-click actions on the row', async () => {
      const page = s.admin = await ctx.loginAdmin(SUPER);
      await page.setViewportSize(DESKTOP);
      await page.goto(ctx.url('/admin/staff'));
      const row = page.locator('.tabulator-row', { hasText: 'staff@gmail.com' });
      await expect.visible(row, 'no staff row');
      expect.equal(await sideways(page), false, 'sideways scroll at 1280');
      for (const name of ['Reset password', 'Move group', 'Suspend']) await expect.visible(row.getByRole('button', { name, exact: true }), name + ' is not on the row');
      await expect.visible(page.locator('.tabulator-row').getByRole('button', { name: 'Make super admin' }).first(), 'Make super admin is not on the row');
      expect.equal(await page.getByRole('button', { name: 'Manage' }).count(), 0, 'a Manage menu');
      const cut = await page.evaluate(() => [...document.querySelectorAll('.tabulator-cell .vz-chip')].filter((c) => c.getBoundingClientRect().right > c.closest('.tabulator-cell').getBoundingClientRect().right).map((c) => c.textContent));
      expect.equal(cut.join() || 'none', 'none', 'chips cut by their cell');
      return page;
    });

    await step('The filters narrow the list, and the tabs keep their place in the address', async () => {
      const page = s.admin;
      const rows = page.locator('.tabulator-row');
      await page.locator('#smRole').selectOption('Admin');
      await page.waitForFunction(() => ![...document.querySelectorAll('.tabulator-row')].some((r) => r.textContent.includes('staff@gmail.com')));
      await page.locator('#smRole').selectOption('');
      await expect.visible(rows.filter({ hasText: 'staff@gmail.com' }), 'the staff row did not come back');
      await page.locator('.lp-seg [data-tab="covers"]').click();
      await expect.visible(page.locator('#smCoverOpen'), 'the Covers tab did not open');
      expect.equal(await page.locator('#users-table').isVisible(), false, 'the people table stays on the Covers tab');
      expect.match(page.url(), /tab=covers/, 'the address');
      await page.reload();
      await expect.visible(page.locator('#smCoverOpen'), 'a reload forgot the tab');
      await page.locator('.lp-seg [data-tab="people"]').click();
      await expect.visible(page.locator('.tabulator-row', { hasText: 'staff@gmail.com' }), 'the table did not draw when its tab opened');
      expect.equal(await sideways(page), false, 'sideways scroll after the tab opened');
      return page;
    });

    await step('Set up cover on a row starts with that person; unticking a hall they hold alone stops Save', async () => {
      const page = s.bahay = await ctx.loginAdmin(BAHAY_ADMIN);
      await page.goto(ctx.url('/admin/staff'));
      const id = String((await dbOne('SELECT id FROM users WHERE email = ?', ['staff@gmail.com'])).id);
      /* every other staff member of the office gives up their halls, so this one holds them alone */
      const others = (await dbOne('SELECT GROUP_CONCAT(user_id) AS ids FROM staff WHERE venue_id = 1 AND user_id <> ?', [id])).ids.split(',');
      for (const o of others) {
        const out = await page.evaluate(([u, d]) => Live.post(u, d), [ctx.url('/admin/staff-save'), { action: 'set_halls', user_id: o, hall_ids: '[]' }]);
        expect.ok(out.ok, 'set_halls refused: ' + JSON.stringify(out));
      }
      await page.reload();
      const row = page.locator('.tabulator-row', { hasText: 'staff@gmail.com' });
      await row.getByRole('button', { name: 'Set up cover' }).click();
      expect.equal(await page.locator('#smCoverDlg select[name="user_id"]').inputValue(), id, 'the person in the form');
      await page.locator('#smCoverDlg').getByRole('button', { name: 'Cancel' }).click();

      await row.locator('.sm-halls-edit').click();
      const dlg = page.locator('#smHallsDlg');
      await expect.visible(dlg, 'the halls dialog did not open');
      const alone = dlg.locator('.sm-check[data-last="1"]').first();
      await expect.visible(alone, 'no hall held by this staff member alone');
      const save = dlg.getByRole('button', { name: 'Save halls' });
      expect.equal(await save.isDisabled(), false, 'Save disabled before any change');
      await alone.locator('input').uncheck();
      await expect.visible(dlg.getByText('would have no staff'), 'no warning');
      expect.equal(await save.isDisabled(), true, 'Save still on');
      await alone.locator('input').check();
      expect.equal(await save.isDisabled(), false, 'Save stays off after ticking back');
      await dlg.getByRole('button', { name: 'Cancel' }).click();
      return page;
    });

    await step('Staff Management on a phone: cards, 44px buttons, filters behind one button', async () => {
      const page = s.admin;
      await page.setViewportSize(PHONE);
      await page.goto(ctx.url('/admin/staff'));
      await expect.visible(page.locator('.tabulator-row', { hasText: 'staff@gmail.com' }), 'no staff card');
      expect.equal(await sideways(page), false, 'sideways scroll on a phone');
      expect.equal((await shortButtons(page, '.tabulator-row button')).join() || 'none', 'none', 'row buttons under 44px');
      expect.equal(await page.locator('#smRole').isVisible(), false, 'the filters show before Filters is tapped');
      await page.locator('.lp-filters-btn').click();
      await expect.visible(page.locator('#smRole'), 'Filters did not open them');
      return page;
    });

    await step('Transaction History at 1280: fits, and the CSV keeps every column', async () => {
      const page = s.customer = await ctx.loginCustomer(CUSTOMER);
      await page.setViewportSize(DESKTOP);
      await page.goto(ctx.url('/customer/transaction-history'));
      await expect.visible(page.locator('.tabulator-row').first(), 'no rows');
      expect.equal((await page.locator('h1').textContent()).trim(), 'Transaction History', 'the title');
      expect.equal(await sideways(page), false, 'sideways scroll at 1280');
      await page.getByRole('button', { name: 'Download' }).click();
      const saved = page.waitForEvent('download');
      await page.locator('#export-csv').click();
      const file = await (await saved).path();
      const head = readFileSync(file, 'utf8').slice(0, 300);
      for (const col of ['Transaction ID', 'Booking ID', 'Venue', 'Event Date', 'Transaction Date', 'Amount', 'Payment Method', 'Payment Status', 'Booking Status']) {
        expect.match(head, new RegExp(col), 'the CSV heading');
      }
      expect.equal(await page.locator('#thDownload').isVisible(), false, 'the Download menu stayed open');
      return page;
    });

    await step('On a phone: one card per payment, filters in a sheet that says how many match', async () => {
      const page = s.customer;
      await page.setViewportSize(PHONE);
      await page.goto(ctx.url('/customer/transaction-history'));
      const card = page.locator('.th-card').first();
      await expect.visible(card, 'no cards');
      expect.equal(await page.locator('.tabulator-header').isVisible(), false, 'the table heading on a phone');
      expect.equal(await sideways(page), false, 'sideways scroll on a phone');
      expect.equal((await shortButtons(page, '.th-card .th-act')).join() || 'none', 'none', 'card buttons under 44px');
      expect.equal(await page.getByRole('link', { name: 'Back to Venues' }).isVisible(), false, 'Back to Venues on a phone');
      const all = await page.locator('.th-card:visible').count();
      await page.locator('#thFilters').click();
      const sheet = page.locator('#thSheet');
      await expect.visible(sheet, 'the sheet did not open');
      expect.equal((await sheet.evaluate((d) => d.getBoundingClientRect().bottom)), PHONE.height, 'the sheet is not at the bottom');
      await page.locator('#date-from-filter').fill('2000-01-01');
      await page.locator('#date-to-filter').fill('2000-01-02');
      await expect.visible(sheet.getByRole('button', { name: 'Show 0 payments' }), 'the sheet does not count');
      await sheet.getByRole('button', { name: 'Show 0 payments' }).click();
      await expect.text(page, 'No payments match these filters.');
      expect.equal((await page.locator('#thCount').textContent()).trim(), '2', 'the count on Filters');
      await page.locator('[data-reset]').click();
      await page.waitForFunction((n) => document.querySelectorAll('.th-card').length >= n, all);
      return page;
    });

    await step('Venue Management: rooms sit under their venue, the jump bar narrows them, Needs attention finds the rest', async () => {
      const page = s.admin;
      await page.setViewportSize(DESKTOP);
      await page.goto(ctx.url('/admin/venue-management'));
      const hostel = page.locator('.vm-venue', { has: page.getByRole('heading', { name: 'USeP Hostel' }) });
      await expect.visible(hostel.getByRole('link', { name: 'Add hostel room' }), 'no Add hostel room in the hostel section');
      await expect.visible(hostel.getByText('Hostel Room 1'), 'a hostel room is not under the hostel');
      expect.equal(await sideways(page), false, 'sideways scroll at 1280');
      const bahay = page.locator('.vm-venue', { has: page.getByRole('heading', { name: 'Bahay Alumni' }) });

      await page.locator('[data-jump]', { hasText: 'USeP Hostel' }).click();
      expect.equal(await bahay.isVisible(), false, 'Bahay Alumni stays under the USeP Hostel filter');
      await expect.visible(hostel, 'the hostel went');

      await page.locator('[data-jump="attention"]').click();
      const shown = await page.locator('.vm-card:visible').count();
      const told = Number((await page.locator('[data-jump="attention"] span').textContent()).trim());
      expect.equal(shown, told, 'cards under Needs attention');
      expect.ok(told > 0, 'nothing needs attention in the snapshot');
      expect.equal(await page.locator('.vm-card:visible:not([data-attention])').count(), 0, 'a healthy room under Needs attention');

      await page.locator('[data-jump="all"]').click();
      await page.locator('.vm-search').fill('Hostel Room 1');
      await expect.visible(hostel.getByText('Hostel Room 1'), 'the search lost the room');
      expect.equal(await bahay.isVisible(), false, 'a venue with no match stays');
      return page;
    });

    await step('Venue Management on a phone: photo at the left, 44px buttons, no photo dots', async () => {
      const page = s.admin;
      await page.setViewportSize(PHONE);
      await page.goto(ctx.url('/admin/venue-management'));
      const card = page.locator('.vm-card').first();
      await expect.visible(card, 'no room');
      expect.equal(await sideways(page), false, 'sideways scroll on a phone');
      const box = await card.locator('.vm-thumb').boundingBox();
      expect.equal(Math.round(box.width), 112, 'the photo column');
      expect.equal((await shortButtons(page, '.vm-card-foot .vm-btn, .vm-venue-acts .vm-btn')).join() || 'none', 'none', 'buttons under 44px');
      expect.equal(await page.locator('.vm-dot-nav').count(), 0, 'photo dots');
      return page;
    });

    await step('Customer Management at 1280: fits, Suspend is one click, the Blocks menu is not cut off and Esc closes it', async () => {
      const page = s.admin;
      await page.setViewportSize(DESKTOP);
      await page.goto(ctx.url('/admin/customer-management'));
      const row = page.locator('.tabulator-row', { hasText: CUSTOMER });
      await expect.visible(row, 'no customer row');
      expect.equal(await sideways(page), false, 'sideways scroll at 1280');
      await expect.visible(row.getByRole('button', { name: 'Suspend', exact: true }), 'Suspend is not on the row');
      expect.equal(await page.getByRole('button', { name: 'Manage' }).count(), 0, 'a Manage menu');
      const blocks = row.getByRole('button', { name: 'Blocks' });
      await blocks.click();
      const menu = page.locator('.lp-menu:popover-open');
      await expect.visible(menu, 'the menu did not open');
      const inView = await menu.evaluate((m) => { const r = m.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && r.bottom <= innerHeight && r.top >= 0; });
      expect.equal(inView, true, 'the menu runs off the screen');
      await page.keyboard.press('Escape');
      expect.equal(await page.locator('.lp-menu:popover-open').count(), 0, 'Esc did not close it');
      await page.locator('#cmBlocks').selectOption('yes');
      await expect.visible(page.getByText('0 customers'), 'an unblocked customer stays under Blocked somewhere');
      await page.locator('#cmBlocks').selectOption('');
      return page;
    });

    await step('The bookings link opens that customer in Booking Management', async () => {
      const page = s.admin;
      const link = page.locator('.tabulator-row', { hasText: CUSTOMER }).locator('.cm-bookings');
      await link.click();
      await page.waitForURL(/booking-requests\?q=/);
      const who = await dbOne('SELECT c.full_name FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = ?', [CUSTOMER]);
      expect.equal(await page.locator('#brSearch').inputValue(), who.full_name, 'the search box');
      await expect.visible(page.locator('#brRows a.br-row').first(), 'none of their bookings listed');
      expect.equal(await page.locator('#brRows a.br-row').filter({ hasNotText: who.full_name }).count(), 0, 'other customers listed');
      return page;
    });

    await step('Customer Management on a phone: cards and 44px buttons', async () => {
      const page = s.admin;
      await page.setViewportSize(PHONE);
      await page.goto(ctx.url('/admin/customer-management'));
      await expect.visible(page.locator('.tabulator-row', { hasText: CUSTOMER }), 'no customer card');
      expect.equal(await sideways(page), false, 'sideways scroll on a phone');
      expect.equal((await shortButtons(page, '.tabulator-row .lp-btn')).join() || 'none', 'none', 'row buttons under 44px');
      return page;
    });

    await step('The staff-side sidebar fits a 768px laptop with full names, one line each, and says what waits', async () => {
      const page = s.admin;
      await page.setViewportSize({ width: 1366, height: 768 });
      await page.goto(ctx.url('/admin/booking-requests'));
      await expect.visible(page.locator('#brRows a.br-row').first(), 'no queue');
      const bar = await page.evaluate(() => {
        const side = document.querySelector('aside.sidebar');
        const links = [...side.querySelectorAll('.menu a')];
        return { fits: side.scrollHeight <= side.clientHeight, logOut: links.at(-1).getBoundingClientRect().bottom <= innerHeight,
          wrapped: links.filter((a) => a.scrollWidth > a.clientWidth || a.getBoundingClientRect().height > 36).map((a) => a.textContent.trim()) };
      });
      expect.equal(bar.fits, true, 'the sidebar scrolls at 768');
      expect.equal(bar.logOut, true, 'Log Out is off the screen');
      expect.equal(bar.wrapped.join() || 'none', 'none', 'links on two lines or cut');
      const need = (await page.locator('#brCount').textContent()).match(/(\d+) need action/)[1];
      expect.equal((await page.locator('aside .cnt[data-live="sb-Booking Management"]').textContent()).trim(), need, 'the count against the queue');
      await expect.visible(page.locator('.hd-counter'), 'no Counter booking in the header');
      await page.goto(ctx.url('/admin/calendar'));
      await page.locator('.hd-counter').click();
      await page.waitForURL(/customer\/venues/);
      await page.setViewportSize(DESKTOP);
      return page;
    });

    await step('The queue keeps its tab and search in the address; the booking page names the tab and offers the next one', async () => {
      const page = s.admin;
      await page.setViewportSize(DESKTOP);
      await page.goto(ctx.url('/admin/booking-requests'));
      await page.locator('#brTabBtns .br-tab', { hasText: 'Overdue' }).click();
      expect.match(page.url(), /tab=overdue/, 'the tab in the address');
      await page.reload();
      await expect.visible(page.locator('#brTabBtns .br-tab.active', { hasText: 'Overdue' }), 'a reload forgot the tab');
      const rows = page.locator('#brRows a.br-row');
      const n = await rows.count();
      expect.ok(n > 1, 'the snapshot needs two overdue bookings on the first page');
      const second = await rows.nth(1).locator('.r-id').textContent();
      await rows.first().click();
      await page.waitForURL(/booking-request\?id=/);
      await expect.visible(page.locator('.br-crumb .br-back', { hasText: 'Overdue' }), 'the back link does not name the tab');
      await expect.text(page, /1 of \d+ in this tab/);
      expect.equal(await page.locator('.br-done').count(), 0, 'a done note before any action');
      /* a real action on this booking, as the button would send it */
      expect.equal(await page.evaluate(() => staffAction('extend_deadline', {})), true, 'the action failed');
      await expect.visible(page.locator('.br-done', { hasText: 'waiting in this tab' }), 'no done note after the action');
      await page.locator('.br-next').click();
      await page.waitForURL(new RegExp(second.trim() + '$'));
      await expect.text(page, /2 of \d+ in this tab/);
      await page.locator('.br-crumb .br-back').click();
      await page.waitForURL(/tab=overdue/);
      await expect.visible(page.locator('#brTabBtns .br-tab.active', { hasText: 'Overdue' }), 'back did not land on the tab');
      return page;
    });
  },
};
