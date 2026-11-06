// Every link each role can reach, at a desktop and a phone width: no 5xx, no dead link, no
// sideways scroll, no console error. Follows plain links only and never presses a button, so it
// changes nothing. Pages that differ only by an id count as one page after their first 2.
// The findings go to crawl.json next to the screenshots; the last step fails on a 5xx or a dead link.
import { writeFileSync } from 'node:fs';
import path from 'node:path';

const SKIP = /logout|sign-?out|\.pdf|\/print|download|export|receipt-pdf|\/assets\/|\/storage\//i;
const PER_PATTERN = 2;
const MAX_PAGES = 90;

const pattern = (u) => u.pathname.replace(/\d+/g, 'N') + '?' + [...u.searchParams.keys()].sort().join('&');

async function crawl(ctx, page, who, width, found) {
  const origin = new URL(ctx.baseUrl).origin;
  await page.setViewportSize({ width, height: 900 });
  const queue = [page.url()];
  const seen = new Set();
  const perPattern = new Map();
  let visited = 0;
  while (queue.length && visited < MAX_PAGES) {
    const href = queue.shift();
    const u = new URL(href);
    u.hash = '';
    if (seen.has(u.href) || u.origin !== origin || SKIP.test(u.href)) continue;
    seen.add(u.href);
    const p = pattern(u);
    perPattern.set(p, (perPattern.get(p) ?? 0) + 1);
    if (perPattern.get(p) > PER_PATTERN) continue;

    const errors = [];
    const onError = (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); };
    const onCrash = (e) => errors.push(String(e).slice(0, 160));
    page.on('console', onError);
    page.on('pageerror', onCrash);
    const res = await page.goto(u.href, { waitUntil: 'load', timeout: 30_000 }).catch((e) => ({ status: () => 0, why: String(e).slice(0, 120) }));
    await ctx.sleep(120);
    visited++;
    const status = res.status();
    const m = await page.evaluate(() => ({
      over: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      links: [...document.querySelectorAll('a[href]')].filter((a) => !a.closest('[contenteditable]')).map((a) => a.href),   // an editor holds a link's stored form
    })).catch(() => ({ over: 0, links: [] }));
    page.off('console', onError);
    page.off('pageerror', onCrash);

    const at = `${who} ${width}px ${u.pathname}${u.search}`;
    if (status === 0 || status >= 500) found.broken.push(`${at}: ${status} ${res.why ?? ''}`);
    else if (status === 404) found.dead.push(`${at}: 404`);
    else if (status >= 400) found.refused.push(`${at}: ${status}`);
    if (m.over > 0) found.sideways.push(`${at}: +${m.over}px`);
    for (const e of errors) found.console.push(`${at}: ${e}`);
    for (const link of m.links) queue.push(link);
  }
  found.visited.push(`${who} ${width}px: ${visited} pages, ${perPattern.size} kinds`);
}

export default {
  name: 'crawler',
  title: 'Crawler: every role, desktop and phone width: no 5xx, no dead link, no sideways scroll, no console error',

  async run(ctx) {
    const { step, expect } = ctx;
    const found = { visited: [], broken: [], dead: [], refused: [], sideways: [], console: [] };
    const roles = [
      ['guest', async () => { const page = await ctx.newPage('guest'); await page.goto(ctx.url('/')); return page; }],
      ['customer', () => ctx.loginCustomer('customer@gmail.com')],
      ['staff', () => ctx.loginStaff('staff@gmail.com')],
      ['admin', () => ctx.loginAdmin('cedu.admin@gmail.com')],
      ['super admin', () => ctx.loginAdmin('admin@gmail.com')],
    ];
    for (const [who, open] of roles) {
      await step(`The ${who} walks every link at both widths`, async () => {
        const page = await open();
        const home = page.url();
        await crawl(ctx, page, who, 1280, found);
        await page.goto(home);
        await crawl(ctx, page, who, 390, found);
        await page.setViewportSize({ width: 1280, height: 900 });
        writeFileSync(path.join(ctx.outDir, 'crawl.json'), JSON.stringify(found, null, 1));
        return page;
      }, { timeout: 480_000 });
    }
    await step('No page was broken or dead', async () => {
      expect.equal([...found.broken, ...found.dead].join(' | ') || 'none', 'none', 'broken or dead pages');
    });
  },
};
