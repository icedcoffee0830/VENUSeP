// The approved design pass, in a real browser: what only a browser can see. The lists fit the
// screen without sideways scroll, one-click actions sit on the row, and a phone gets cards with
// 44px buttons. One step per design item; the wording and rules are in the feature tests.

const DESKTOP = { width: 1280, height: 800 };
const PHONE = { width: 390, height: 844 };
const SUPER = 'admin@gmail.com';
const BAHAY_ADMIN = 'francishubero@gmail.com';

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
  },
};
