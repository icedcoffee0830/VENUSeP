// Finds a Playwright already on the machine (local node_modules, or the global
// @playwright/cli package) and starts an installed Edge or Chrome. Installs and
// downloads nothing — same approach as tests/browser/check-pages.mjs.
import { execSync } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';

export function findPlaywright() {
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

export async function launchBrowser({ headed = false, slowMo = 0 } = {}) {
  const pw = findPlaywright();
  if (!pw) throw new Error('No playwright / playwright-core package found (local node_modules or global npm, e.g. @playwright/cli).');
  const tried = [];
  for (const channel of ['msedge', 'chrome', undefined]) {
    try { return await pw.chromium.launch({ channel, headless: !headed, slowMo }); }
    catch (e) { tried.push((channel || 'bundled chromium') + ': ' + String(e.message).split('\n')[0]); }
  }
  throw new Error('No browser could be started (' + tried.join('; ') + ')');
}
