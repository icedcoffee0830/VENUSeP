// report.json + report.html (one section per flow, one card per step, screenshot inline).
import { writeFileSync } from 'node:fs';
import path from 'node:path';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

export function writeReport(outDir, report) {
  writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2));
  writeFileSync(path.join(outDir, 'report.html'), html(report));
}

function list(title, items, cls) {
  if (!items || !items.length) return '';
  return `<details class="${cls}"${cls === 'bad' ? ' open' : ''}><summary>${esc(title)} (${items.length})</summary><ul>${items.map((i) => `<li>${esc(i)}</li>`).join('')}</ul></details>`;
}

function html(r) {
  const counts = { pass: 0, fail: 0, skipped: 0 };
  r.flows.forEach((f) => f.steps.forEach((s) => { counts[s.status] = (counts[s.status] || 0) + 1; }));
  const flows = r.flows.map((f) => `
    <section class="flow ${f.status}">
      <h2><span class="badge ${f.status}">${f.status.toUpperCase()}</span> ${esc(f.title)} <small>${esc(f.name)} · ${(f.ms / 1000).toFixed(1)}s</small></h2>
      ${f.error ? `<pre class="err">${esc(f.error)}</pre>` : ''}
      ${f.steps.map((s) => `
        <article class="step ${s.status}">
          <h3><span class="badge ${s.status}">${s.status}</span> ${s.n}. ${esc(s.title)} <small>${(s.ms / 1000).toFixed(1)}s</small></h3>
          ${s.error ? `<pre class="err">${esc(s.error)}</pre>` : ''}
          ${list('JavaScript errors', s.pageErrors, 'bad')}
          ${list('Console errors', s.consoleErrors, 'warn')}
          ${list('Failed network requests', s.failedRequests, 'warn')}
          ${list('Notes', s.notes, 'info')}
          ${s.screenshot ? `<a href="${esc(s.screenshot)}" target="_blank"><img loading="lazy" src="${esc(s.screenshot)}" alt="screenshot after step ${s.n}"></a>` : ''}
        </article>`).join('')}
    </section>`).join('');
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>VENUSeP flows</title>
<style>
  :root{--bg:#f6f5f3;--fg:#1c1b19;--muted:#6e6a64;--card:#fff;--line:#e4e1dc;--ok:#1c7a4f;--bad:#b23a3a;--skip:#8a857d;--warn:#8a5a12}
  @media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--bg:#161514;--fg:#eceae6;--muted:#a5a19a;--card:#201f1d;--line:#34322f}}
  body{margin:0;background:var(--bg);color:var(--fg);font:14px/1.5 system-ui,Segoe UI,Arial,sans-serif}
  main{max-width:1100px;margin:0 auto;padding:24px 16px 64px}
  h1{margin:0 0 4px;font-size:24px} .meta{color:var(--muted);margin-bottom:20px}
  .flow{margin:24px 0} h2{font-size:18px;margin:0 0 10px} small{color:var(--muted);font-weight:400}
  .step{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin:10px 0}
  .step.fail{border-color:var(--bad)} .step.skipped{opacity:.6}
  h3{font-size:15px;margin:0 0 6px}
  .badge{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;color:#fff;background:var(--skip);text-transform:uppercase}
  .badge.pass{background:var(--ok)} .badge.fail{background:var(--bad)}
  pre.err{white-space:pre-wrap;background:rgba(178,58,58,.08);color:var(--bad);padding:8px 10px;border-radius:8px;margin:6px 0}
  details{margin:4px 0} details.bad summary{color:var(--bad)} details.warn summary{color:var(--warn)} details.info summary{color:var(--muted)}
  ul{margin:4px 0 4px 18px;padding:0;word-break:break-all}
  img{display:block;max-width:100%;max-height:520px;object-fit:contain;object-position:left top;border:1px solid var(--line);border-radius:8px;margin-top:8px}
</style></head><body><main>
<h1>VENUSeP flow tester</h1>
<div class="meta">${esc(r.startedAt)} · ${esc(r.baseUrl)} · database <b>${esc(r.db)}</b> · ${(r.ms / 1000).toFixed(1)}s ·
  <b style="color:var(--ok)">${counts.pass} passed</b> · <b style="color:var(--bad)">${counts.fail} failed</b> · ${counts.skipped} skipped</div>
${r.notes && r.notes.length ? `<ul>${r.notes.map((n) => `<li>${esc(n)}</li>`).join('')}</ul>` : ''}
${flows}
</main></body></html>`;
}
