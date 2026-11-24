// Flow — the Server page (failover). The super admin opens Settings > Server: this machine's
// database is the main and Working; a typed address nobody answers on is "Can't reach it";
// Switch asks for the password and the authenticator code; a normal admin has no Server link.
// No replication runs here, so Switch itself is checked by the two-laptop drill (DRILL.md).

export default {
  title: 'Server page: the main, Test, and the switch confirmation',
  async run(ctx) {
    const { step, expect } = ctx;
    const s = ctx.state;

    await step('The super admin opens Server; this machine’s database is the main and Working', async () => {
      s.page = await ctx.loginAdmin('admin@gmail.com');
      const page = s.page;
      await page.goto(ctx.url('/admin/server'));
      await expect.visible(page.locator('h1:visible', { hasText: 'Server' }), 'no Server heading');
      const row = page.locator('#svRows tr').first();
      await expect.visible(row.locator('.sv-pill', { hasText: 'Main' }), 'the first row is not the main', 20_000);
      await expect.visible(row.locator('.sv-pill', { hasText: 'Working' }), 'the main is not Working', 20_000);
      return page;
    });

    await step('Test of an address nobody answers on says it can’t be reached', async () => {
      const page = s.page;
      await page.locator('#svIp').fill('10.255.255.1');
      await page.locator('#svTest').click();
      await expect.visible(page.locator('#svResult li.is-no', { hasText: 'Can’t reach it' }), 'no "can’t reach it" line', 20_000);
      return page;
    });

    await step('Test on the main row lists its checks; there is no Switch on the main', async () => {
      const page = s.page;
      const row = page.locator('#svRows tr').first();
      await row.locator('[data-act="test"]').click();
      await expect.visible(page.locator('#svResult li.is-yes', { hasText: 'The main: it takes every save' }), 'no main check line', 20_000);
      expect.equal(await row.locator('[data-act="switch"]').count(), 0, 'Switch buttons on the main row');
      return page;
    });

    await step('A normal admin has no Server link and is turned away from the page', async () => {
      const page = await ctx.loginAdmin('francishubero@gmail.com');
      expect.equal(await page.locator('a[href$="/admin/server"]').count(), 0, 'Server links for a normal admin');
      await page.goto(ctx.url('/admin/server'));
      expect.ok(!page.url().endsWith('/admin/server'), 'a normal admin stayed on the Server page');
      return page;
    });
  },
};
