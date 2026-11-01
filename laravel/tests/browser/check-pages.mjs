// Browser pass for `php artisan venusep:check --browser` (see BrowserPass.php).
// Input: a JSON file (argv[2]) { baseUrl, cookieName, cookies: {role: value|null}, pages: [{role, route, url}],
//        allowExternal, channel }. Output: one JSON object on stdout.
// Installs and downloads nothing: it uses a playwright / playwright-core already on the machine
// and an installed Edge or Chrome.
import { readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const done = (obj) => { process.stdout.write(JSON.stringify(obj)); process.exit(0); };

function findPlaywright() {
  const roots = [path.join(process.cwd(), 'node_modules')];
  try { roots.push(execSync('npm root -g', { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()); } catch {}
  if (process.env.APPDATA) roots.push(path.join(process.env.APPDATA, 'npm', 'node_modules'));
  for (const root of roots) {
    for (const sub of ['playwright', 'playwright-core', '@playwright/cli/node_modules/playwright', '@playwright/cli/node_modules/playwright-core']) {
      try { return createRequire(path.join(root, 'noop.js'))(path.join(root, sub)); } catch {}
    }
  }
  return null;
}

const pw = findPlaywright();
if (!pw) done({ notes: ['skipped — no playwright or playwright-core package found (local node_modules or global npm).'] });

let browser = null;
const tried = [];
for (const channel of [input.channel, 'msedge', 'chrome', undefined].filter((c, i, a) => a.indexOf(c) === i)) {
  try { browser = await pw.chromium.launch({ channel, headless: true }); break; }
  catch (e) { tried.push((channel || 'bundled chromium') + ': ' + String(e.message).split('\n')[0]); }
}
if (!browser) done({ notes: ['skipped — no browser could be started (' + tried.join('; ') + ')'] });

const base = new URL(input.baseUrl);
try {
  const ctl = new AbortController();
  setTimeout(() => ctl.abort(), 5000);
  await fetch(base, { signal: ctl.signal, redirect: 'manual' });
} catch {
  await browser.close();
  done({ notes: [`skipped — nothing is listening at ${input.baseUrl}. Start it: php artisan serve --port=${base.port || 80}`] });
}

const isLocal = (u) => { try { const x = new URL(u); return x.host === base.host || ['data:', 'blob:'].includes(x.protocol); } catch { return true; } };
const pages = [];
const byRole = {};
for (const p of input.pages) (byRole[p.role] ||= []).push(p);

for (const [role, list] of Object.entries(byRole)) {
  const ctx = await browser.newContext();
  const cookie = input.cookies[role];
  if (cookie) await ctx.addCookies([{ name: input.cookieName, value: cookie, url: input.baseUrl, httpOnly: true }]);
  const blocked = new Set();
  await ctx.route('**/*', (r) => {
    const u = r.request().url();
    if (input.allowExternal || isLocal(u)) return r.continue();
    blocked.add(new URL(u).host);
    return r.abort('blockedbyclient');
  });
  for (const p of list) {
    const page = await ctx.newPage();
    const rec = { role, route: p.route, url: p.url, status: null, finalUrl: null, consoleErrors: [], pageErrors: [], failedRequests: [] };
    page.on('console', (m) => { if (m.type() === 'error') rec.consoleErrors.push(m.text().slice(0, 300)); });
    page.on('pageerror', (e) => rec.pageErrors.push(String(e.message).slice(0, 300)));
    page.on('requestfailed', (r) => {
      const f = r.failure()?.errorText || '';
      if (!f.includes('BLOCKED_BY_CLIENT')) rec.failedRequests.push(`${r.url()} (${f})`);
    });
    page.on('response', (r) => { if (r.status() >= 400 && r.url() !== page.url() && isLocal(r.url())) rec.failedRequests.push(`${r.url()} (HTTP ${r.status()})`); });
    try {
      const res = await page.goto(new URL(p.url, input.baseUrl).href, { waitUntil: 'load', timeout: 20000 });
      rec.status = res ? res.status() : null;
      await page.waitForTimeout(300);
      rec.finalUrl = page.url().replace(base.origin, '');
    } catch (e) {
      rec.pageErrors.push('navigation: ' + String(e.message).split('\n')[0]);
    }
    // a blocked CDN shows up as "Failed to load resource" — not the page's fault
    rec.consoleErrors = rec.consoleErrors.filter((t) => !t.includes('ERR_BLOCKED_BY_CLIENT'));
    rec.problem = rec.consoleErrors.length + rec.pageErrors.length + rec.failedRequests.length > 0;
    pages.push(rec);
    await page.close();
  }
  if (blocked.size) pages.push({ role, note: 'blocked other sites: ' + [...blocked].join(', ') });
  await ctx.close();
}
await browser.close();
const bad = pages.filter((p) => p.problem).length;
done({ notes: [`${pages.filter((p) => p.url).length} page loads, ${bad} with console errors / failed requests`], pages });
