// Calls `php artisan venusep:flows-tool` (app/Console/Commands/VenusepFlowsTool.php).
// It only ever touches a venusep_laravel_flows* / venusep_laravel_test* database.
import { spawn } from 'node:child_process';

export function phpTool(cfg, action, opts = {}, stdin = null) {
  const args = [cfg.artisan, 'venusep:flows-tool', action, '--no-ansi', '--db=' + cfg.db];
  for (const [k, v] of Object.entries(opts)) args.push(`--${k}=${v}`);
  return new Promise((resolve, reject) => {
    const p = spawn(cfg.php, args, { cwd: cfg.laravelDir, env: process.env, windowsHide: true });
    let out = '', err = '';
    p.stdout.on('data', (d) => { out += d; });
    p.stderr.on('data', (d) => { err += d; });
    p.on('error', reject);
    p.on('close', () => {
      const line = out.trim().split(/\r?\n/).pop() || '';
      let json = null;
      try { json = JSON.parse(line); } catch {}
      if (!json) return reject(new Error(`venusep:flows-tool ${action}: unreadable reply: ${(out + err).trim().slice(0, 400)}`));
      if (json.error) return reject(new Error(json.error));
      resolve(json);
    });
    if (stdin !== null) p.stdin.end(stdin); else p.stdin.end();
  });
}
