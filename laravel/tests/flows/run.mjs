// VENUSeP flow runner — started by `php artisan venusep:flows` (see "Flow tester"
// in LARAVEL-PORT.md). argv[2] is a JSON file:
//   { baseUrl, db, php, artisan, laravelDir, outDir, only, headed, password, runId }
// Every file in ./flows/*.mjs is one flow (auto-discovered, run in name order).
// Before each flow the flows database is reset from the snapshot, so flows never
// see each other's rows. Writes report.json + report.html into outDir.
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { launchBrowser } from './lib/browser.mjs';
import { makeCtx } from './lib/ctx.mjs';
import { phpTool } from './lib/php.mjs';
import { writeReport } from './lib/report.mjs';

const cfg = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const here = path.dirname(fileURLToPath(import.meta.url));
const flowDir = path.join(here, 'flows');

async function loadFlows() {
  const out = [];
  for (const file of readdirSync(flowDir).filter((f) => f.endsWith('.mjs')).sort()) {
    const mod = await import(pathToFileURL(path.join(flowDir, file)).href);
    const def = typeof mod.default === 'function' ? { run: mod.default } : (mod.default || {});
    const name = def.name || file.replace(/\.mjs$/, '');
    out.push({ file, name, title: def.title || name, settings: def.settings || {}, run: def.run, loadError: typeof def.run === 'function' ? null : 'exports no run(ctx) function' });
  }
  return out;
}

const report = { startedAt: new Date().toISOString(), baseUrl: cfg.baseUrl, db: cfg.db, ms: 0, notes: [], flows: [] };
const t0 = Date.now();
let flows = await loadFlows();
if (cfg.only) {
  const wanted = String(cfg.only).split(',').map((s) => s.trim());
  flows = flows.filter((f) => wanted.includes(f.name) || wanted.includes(f.file.replace(/\.mjs$/, '')));
  if (!flows.length) report.notes.push(`--only=${cfg.only} matched no flow`);
}

let browser = null;
try {
  browser = await launchBrowser({ headed: !!cfg.headed, slowMo: cfg.headed ? 300 : 0 });
} catch (e) {
  report.notes.push(String(e.message));
}

for (const flow of flows) {
  const record = { name: flow.name, title: flow.title, file: flow.file, status: 'pass', ms: 0, error: null, steps: [] };
  report.flows.push(record);
  const f0 = Date.now();
  console.log(`\n▶ ${flow.name} — ${flow.title}`);
  if (!browser || flow.loadError) {
    record.status = 'fail';
    record.error = flow.loadError || 'no browser';
    continue;
  }
  let ctx = null;
  try {
    const r = await phpTool(cfg, 'reset', Object.keys(flow.settings).length ? { settings: JSON.stringify(flow.settings) } : {});
    console.log(`  database reset (${r.seconds}s)`);
    ctx = makeCtx({ cfg, browser, flow, record });
    await flow.run(ctx);
  } catch (e) {
    record.status = 'fail';
    record.error = String(e && e.stack ? e.stack : e).split('\n').slice(0, 5).join('\n');
  } finally {
    if (ctx) {
      const l = ctx.loose;
      if (l.pageErrors.length || l.consoleErrors.length || l.failedRequests.length) {
        record.steps.push({ n: record.steps.length + 1, title: '(between steps)', status: 'pass', ms: 0, error: null, screenshot: null, ...l });
      }
      await ctx.close();
    }
  }
  record.ms = Date.now() - f0;
  for (const s of record.steps) {
    const mark = s.status === 'pass' ? '✔' : s.status === 'fail' ? '✘' : '·';
    console.log(`  ${mark} ${s.n}. ${s.title} (${(s.ms / 1000).toFixed(1)}s)${s.error ? '\n      ' + s.error.split('\n')[0] : ''}`);
  }
  if (record.error) console.log('  ✘ ' + record.error.split('\n')[0]);
}

if (browser) await browser.close().catch(() => {});
report.ms = Date.now() - t0;
writeReport(cfg.outDir, report);
const failed = report.flows.some((f) => f.status === 'fail') || report.notes.length > 0;
process.exit(failed ? 1 : 0);
