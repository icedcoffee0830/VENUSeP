// The `ctx` every flow receives. See "Flow tester" in LARAVEL-PORT.md.
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { phpTool } from './php.mjs';

const DEFAULT_STEP_TIMEOUT = 90_000;
const usedTotpSteps = new Map();   // email -> last 30-s step used (a code works once per step)

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const slug = (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 50);

export class StepError extends Error {}

export function makeCtx({ cfg, browser, flow, record }) {
  const shotDir = path.join(cfg.outDir, flow.name);
  mkdirSync(shotDir, { recursive: true });
  const contexts = [];
  let current = null;            // the step record being run (listeners write into it)
  let lastPage = null;           // the page screenshotted when a step returns nothing
  let stepNo = 0;
  const loose = { consoleErrors: [], pageErrors: [], failedRequests: [], notes: [] };   // between steps
  const bucket = () => current || loose;
  const origin = new URL(cfg.baseUrl).origin;

  function watch(page, label) {
    page.__flowLabel = label;
    const touch = () => { lastPage = page; };
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) touch(); });
    page.on('request', touch);
    page.on('console', (m) => { if (m.type() === 'error') bucket().consoleErrors.push(`[${label}] ${m.text().slice(0, 400)}`); });
    page.on('pageerror', (e) => bucket().pageErrors.push(`[${label}] ${String(e.message).slice(0, 400)}`));
    page.on('requestfailed', (r) => {
      const why = r.failure()?.errorText || '';
      if (why.includes('ERR_ABORTED') && r.resourceType() !== 'document') return;   // navigations cancel these
      bucket().failedRequests.push(`[${label}] ${r.method()} ${r.url()} (${why})`);
    });
    page.on('response', (r) => {
      if (r.status() >= 400 && r.url().startsWith(origin)) bucket().failedRequests.push(`[${label}] ${r.request().method()} ${r.url()} (HTTP ${r.status()})`);
    });
    page.on('dialog', (d) => { bucket().notes.push(`[${label}] ${d.type()} dialog accepted: ${d.message().slice(0, 200)}`); d.accept().catch(() => {}); });
    lastPage = page;
    return page;
  }

  /* A fresh browser context = a separate person (own cookies). */
  async function newPage(label = 'guest') {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
    context.setDefaultTimeout(15_000);
    context.setDefaultNavigationTimeout(30_000);
    contexts.push(context);
    return watch(await context.newPage(), label);
  }

  const url = (p) => new URL(p, cfg.baseUrl).href;

  async function totp(email) {
    for (;;) {
      const r = await phpTool(cfg, 'totp', { email });
      if (usedTotpSteps.get(email) !== r.step) {
        usedTotpSteps.set(email, r.step);
        return r.code;
      }
      await sleep(30000 - (Date.now() % 30000) + 300);   // this step's code is spent: wait for the next one
    }
  }

  /* After the password: the authenticator-code step, when the account has one. */
  async function passCodeStep(page, email) {
    const code = page.locator('#tfaCode');
    if (!(await code.isVisible().catch(() => false))) return;
    await code.fill(await totp(email));
    await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Verify' }).click()]);
  }

  async function loginCustomer(email, password = cfg.password) {
    const page = await newPage('customer ' + email);
    await page.goto(url('/customer/customer-login'));
    await page.locator('#customerEmail').fill(email);
    await page.locator('#customerPassword').fill(password);
    await Promise.all([page.waitForLoadState('load'), page.locator('#customerLoginForm button[type="submit"]').click()]);
    await page.waitForLoadState('load');
    await passCodeStep(page, email);
    if (page.url().includes('/customer/customer-login')) {
      const err = await page.locator('#loginServerError').textContent().catch(() => '');
      throw new StepError(`customer sign-in failed for ${email}: ${err || 'still on the sign-in page'}`);
    }
    return page;
  }

  async function loginStaffSide(email, password, label) {
    const page = await newPage(label + ' ' + email);
    await page.goto(url('/admin/admin-login'));
    await page.locator('#adminEmail').fill(email);
    await page.locator('#adminPassword').fill(password);
    await Promise.all([page.waitForLoadState('load'), page.locator('#adminLoginForm button[type="submit"]').click()]);
    await page.waitForLoadState('load');
    await passCodeStep(page, email);
    if (!page.url().includes('/admin/dashboard')) {
      const err = await page.locator('#loginSuccessMessage, .tfa-error, .validation-message').allTextContents().catch(() => []);
      throw new StepError(`${label} sign-in failed for ${email}: ended on ${page.url()} ${err.filter(Boolean).join(' ')}`);
    }
    return page;
  }

  /* ---- read-only database checks ---- */
  async function db(sql, params = []) {
    return (await phpTool(cfg, 'select', {}, JSON.stringify({ sql, params }))).rows;
  }

  /* ---- assertions: throw a StepError with a readable message ---- */
  const fail = (msg) => { throw new StepError(msg); };
  const expect = {
    ok: (cond, msg) => { if (!cond) fail(msg || 'expected a true condition'); },
    equal: (actual, expected, msg) => {
      if (String(actual) !== String(expected)) fail(`${msg ? msg + ': ' : ''}expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    },
    match: (actual, re, msg) => { if (!re.test(String(actual))) fail(`${msg ? msg + ': ' : ''}${JSON.stringify(String(actual).slice(0, 200))} does not match ${re}`); },
    /* waits until the locator is visible */
    visible: async (locator, msg, timeout = 15_000) => {
      try { await locator.first().waitFor({ state: 'visible', timeout }); }
      catch { fail(msg || `not visible: ${locator}`); }
    },
    /* waits until the page shows this text (string or RegExp) */
    text: async (page, text, timeout = 15_000) => {
      try { await page.getByText(text).first().waitFor({ state: 'visible', timeout }); }
      catch { fail(`the page (${page.url()}) never showed ${text instanceof RegExp ? text : JSON.stringify(text)}`); }
    },
    url: async (page, re, timeout = 15_000) => {
      try { await page.waitForURL(re, { timeout }); }
      catch { fail(`expected the URL to match ${re}, it is ${page.url()}`); }
    },
  };

  /* ---- one step: run, screenshot, record; after a failure the rest are skipped ---- */
  async function step(title, fn, { timeout = DEFAULT_STEP_TIMEOUT } = {}) {
    stepNo += 1;
    const rec = { n: stepNo, title, status: 'skipped', ms: 0, error: null, screenshot: null,
      consoleErrors: [], pageErrors: [], failedRequests: [], notes: [] };
    record.steps.push(rec);
    if (record.status === 'fail') return undefined;
    current = rec;
    const t0 = Date.now();
    let value;
    let timer;
    try {
      value = await Promise.race([
        Promise.resolve().then(fn),
        new Promise((_, rej) => { timer = setTimeout(() => rej(new StepError(`step timed out after ${timeout / 1000}s`)), timeout); }),
      ]);
      rec.status = 'pass';
    } catch (e) {
      rec.status = 'fail';
      rec.error = e instanceof StepError ? e.message : String(e && e.stack ? e.stack : e).split('\n').slice(0, 4).join('\n');
      record.status = 'fail';
    } finally {
      clearTimeout(timer);
    }
    rec.ms = Date.now() - t0;
    const page = value && typeof value.screenshot === 'function' ? value : lastPage;
    if (page && !page.isClosed()) {
      const file = `${String(stepNo).padStart(2, '0')}-${slug(title)}.png`;
      try {
        await page.screenshot({ path: path.join(shotDir, file), fullPage: true, timeout: 15_000 });
        rec.screenshot = `${flow.name}/${file}`;
        rec.notes.push(`screenshot of: ${page.__flowLabel} — ${page.url()}`);
      } catch (e) { rec.notes.push('no screenshot: ' + String(e.message).split('\n')[0]); }
    }
    current = null;
    return rec.status === 'pass' ? value : undefined;
  }

  /* ---- fixtures: real images, drawn in the browser (this PHP has no GD) ---- */
  let fixturePage = null;
  async function drawPng(name, html, type = 'png') {
    if (!fixturePage) {
      const c = await browser.newContext({ viewport: { width: 900, height: 1400 }, deviceScaleFactor: 1 });
      contexts.push(c);
      fixturePage = await c.newPage();
    }
    await fixturePage.setContent(`<!doctype html><html><body style="margin:0;background:#fff">${html}</body></html>`);
    const dir = path.join(cfg.outDir, 'fixtures');
    mkdirSync(dir, { recursive: true });
    const file = path.join(dir, `${flow.name}-${name}.${type === 'jpeg' ? 'jpg' : 'png'}`);
    await fixturePage.locator('#fx').screenshot({ path: file, type });
    return file;
  }
  const fixtures = {
    /* a fake ID card (PNG) for the valid-ID uploads */
    idImage: (name = 'Flow Tester') => drawPng('valid-id', `
      <div id="fx" style="width:560px;height:350px;border-radius:18px;background:linear-gradient(135deg,#7d0f1e,#a11626);color:#fff;font:600 22px Arial;padding:28px;box-sizing:border-box">
        <div style="font-size:18px;letter-spacing:.1em">REPUBLIC OF THE PHILIPPINES · SAMPLE ID</div>
        <div style="display:flex;gap:24px;margin-top:34px"><div style="width:140px;height:170px;background:#f2d0cb;border-radius:8px"></div>
        <div><div style="font-size:14px;opacity:.8">NAME</div><div style="font-size:28px">${name}</div>
        <div style="font-size:14px;opacity:.8;margin-top:16px">ID NO.</div><div>FLOW-${Date.now() % 1e8}</div></div></div></div>`),
    /* a GCash "Sent via GCash" receipt that the in-browser OCR can read:
       { amount: 8000, name: 'Mika J.', number: '09951941234', ref: '1234567890123' } */
    gcashReceipt: ({ amount, name, number, ref, when = new Date() }) => {
      const peso = Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const r = String(ref);
      const refShown = `${r.slice(0, 4)} ${r.slice(4, 7)} ${r.slice(7)}`;
      // "Oct 30, 2026 4:32 PM" — the form the checker reads (no comma after the year)
      const dt = when.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' '
        + when.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
      const row = (k, v) => `<div style="display:flex;justify-content:space-between;margin:14px 0"><span>${k}</span><span>${v}</span></div>`;
      return drawPng('gcash-receipt', `
        <div id="fx" style="width:520px;padding:40px 36px;background:#fff;color:#111;font:500 26px Arial">
          <div style="text-align:center;font-size:30px;font-weight:700;margin-bottom:26px">Sent via GCash</div>
          <div style="margin:10px 0">${name}</div>
          <div style="margin:10px 0 28px">${number}</div>
          ${row('Amount', peso)}
          ${row('Total Amount Sent', 'PHP ' + peso)}
          <div style="margin-top:28px">Ref No. ${refShown}</div>
          <div style="margin-top:12px">${dt}</div>
        </div>`);
    },
  };

  return {
    name: flow.name,
    baseUrl: cfg.baseUrl,
    password: cfg.password,
    outDir: shotDir,
    uniq: cfg.runId,
    state: {},
    url,
    step,
    expect,
    db,
    dbOne: async (sql, params) => (await db(sql, params))[0] ?? null,
    newPage,
    loginCustomer,
    loginStaff: (email = 'staff@gmail.com', password = cfg.password) => loginStaffSide(email, password, 'staff'),
    loginAdmin: (email = 'admin@gmail.com', password = cfg.password) => loginStaffSide(email, password, 'admin'),
    fixtures,
    note: (msg) => bucket().notes.push(String(msg)),
    fail,
    sleep,
    loose,
    close: async () => { for (const c of contexts) await c.close().catch(() => {}); },
  };
}
