// Every table's pager sits above its rows, once only (Aron 2026-11-05: top only).
// Before the rows on screen AND in the page's order, so Tab reaches it first too.

const ADMIN_PAGES = [
  { url: '/admin/booking-requests', pager: '#brFoot', rows: '#brRows' },
  { url: '/admin/transaction-history', pager: '.tabulator-footer', rows: '.tabulator-tableholder' },
  { url: '/admin/customer-management', pager: '.tabulator-footer', rows: '.tabulator-tableholder' },
  { url: '/admin/staff', pager: '.tabulator-footer', rows: '.tabulator-tableholder' },
];
const CUSTOMER_PAGES = [
  { url: '/customer/booking-history', pager: '.booking-table-footer', rows: '.booking-table-scroll' },
  { url: '/customer/transaction-history', pager: '.tabulator-footer', rows: '.tabulator-tableholder' },
];

async function check(ctx, page, { url, pager, rows }) {
  await page.goto(ctx.url(url));
  await page.waitForTimeout(800);   /* a table draws after the page loads */
  const seen = await page.evaluate(([p, r]) => [p, r].map((q) => { const e = document.querySelector(q); return e ? (e.getBoundingClientRect().height > 0 ? 'ok' : 'hidden') : 'missing'; }), [pager, rows]);
  if (seen.join() !== 'ok,ok') return [`pager ${seen[0]}, rows ${seen[1]}`];
  return page.evaluate(([p, r]) => {
    const pagers = document.querySelectorAll(p);
    const a = pagers[0], b = document.querySelector(r);
    const problems = [];
    if (pagers.length !== 1) problems.push(pagers.length + ' pagers');
    if (a.getBoundingClientRect().bottom > b.getBoundingClientRect().top + 1) problems.push('below the rows on screen');
    if (!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)) problems.push('after the rows in the page order');
    if (document.documentElement.scrollWidth > document.documentElement.clientWidth) problems.push('sideways scroll');
    return problems;
  }, [pager, rows]);
}

export default {
  name: 'table-pagers',
  title: 'Every table has one pager, above its rows',

  async run(ctx) {
    const { step, expect } = ctx;
    const s = ctx.state;

    await step('All six tables, as the super admin and a customer', async () => {
      s.admin = await ctx.loginAdmin('admin@gmail.com');
      s.customer = await ctx.loginCustomer('customer@gmail.com');
      const bad = [];
      for (const p of ADMIN_PAGES) {
        const problems = await check(ctx, s.admin, p);
        if (problems.length) bad.push(`${p.url}: ${problems.join(', ')}`);
      }
      for (const p of CUSTOMER_PAGES) {
        const problems = await check(ctx, s.customer, p);
        if (problems.length) bad.push(`${p.url}: ${problems.join(', ')}`);
      }
      expect.equal(bad.join(' | ') || 'none', 'none', 'pagers');
      return s.admin;
    }, { timeout: 150_000 });

    await step('A Tabulator pager still turns the page, and the size box still works, from the top', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/transaction-history'));
      const next = page.locator('.tabulator-footer .tabulator-page[data-page="next"]');
      await next.waitFor({ state: 'visible' });
      const first = await page.locator('.tabulator-row').first().textContent();
      await next.click();
      await page.waitForFunction((t) => document.querySelector('.tabulator-row')?.textContent !== t, first);
      await page.locator('.vz-ps input').fill('25');
      await page.locator('.vz-ps input').press('Enter');
      await page.waitForFunction(() => document.querySelectorAll('.tabulator-row').length > 10);
      return page;
    });

    await step('Booking Management: the pager at the top still turns the page', async () => {
      const page = s.admin;
      await page.goto(ctx.url('/admin/booking-requests'));
      const first = await page.locator('#brRows a.br-row').first().getAttribute('href');
      await page.locator('#brPages').getByRole('button', { name: 'Next' }).click();
      expect.ok(await page.locator('#brRows a.br-row').first().getAttribute('href') !== first, 'the rows did not change');
      return page;
    });

    await step('On a phone the pager stays on the page', async () => {
      const page = s.customer;
      await page.setViewportSize({ width: 390, height: 800 });
      const problems = await check(ctx, page, CUSTOMER_PAGES[0]);
      expect.equal(problems.join(', ') || 'none', 'none', 'Booking History at 390px');
      return page;
    });
  },
};
