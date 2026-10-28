<?php

namespace App\Console\Commands;

use App\Console\VenusepCheck\BrowserPass;
use App\Console\VenusepCheck\PageProbe;
use App\Console\VenusepCheck\Params;
use App\Console\VenusepCheck\Report;
use App\Console\VenusepCheck\Targets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/* php artisan venusep:check — requests every page as guest, customer, staff
   and admin, internally through the HTTP kernel, and reports what is broken.
   See "Auto-tester" in LARAVEL-PORT.md.

   Each role runs in its own PHP process (the legacy includes keep per-process
   state, and a page that calls exit() or dies must not end the run); a dead
   worker is restarted after the page that killed it. --in-process runs
   everything here instead (the feature tests use it). */
class VenusepCheck extends Command
{
    protected $signature = 'venusep:check
        {--only= : only routes whose name starts with this (e.g. admin.booking)}
        {--role= : only this role: guest, customer, staff or admin}
        {--db= : run against this database instead of the one in .env}
        {--in-process : do not start one worker process per role}
        {--browser : also load the pages in a real browser (needs a running php artisan serve)}
        {--base-url=http://127.0.0.1:8000 : where php artisan serve is listening, for --browser}
        {--browser-external : let the browser load other sites (CDNs, fonts); blocked by default}
        {--worker : internal: run one role and print results for the parent}
        {--routes= : internal: comma-separated route names for --worker}';

    protected $description = 'Request every VENUSeP page as each role and report errors, broken links and unported pages';

    private const REFUSED_DB = 'venusep';

    private const WORKER_TIMEOUT = 900;

    public function handle(): int
    {
        $db = $this->useDatabase();
        if ($db === null) {
            return 2;
        }
        $serverSessionDriver = (string) config('session.driver');   // what php artisan serve uses (--browser)
        config(['session.driver' => 'array']);      // nothing on disk; a fresh session per request
        app('session')->forgetDrivers();

        if ($this->option('worker')) {
            return $this->worker($db);
        }

        $roles = $this->roles();
        if ($roles === null) {
            return 2;
        }
        $routes = Targets::routes((string) $this->option('only'));
        if (!$routes) {
            $this->error('No routes match --only=' . $this->option('only'));
            return 2;
        }

        $notes = [];
        $sessions = [];
        foreach ($roles as $role) {
            $why = $role === 'admin' ? Targets::prepareAdmin($db) : null;
            $session = $why ?? Targets::session($role);
            if (is_string($session)) {
                $notes[] = "{$role}: skipped — {$session}";
                continue;
            }
            $sessions[$role] = $session;
        }

        $this->line(sprintf('Checking %d routes × %d roles on <info>%s</info>…', count($routes), count($roles), $db));
        $started = microtime(true);
        $byRole = $this->option('in-process')
            ? $this->runHere($routes, $sessions)
            : $this->runWorkers(array_keys($routes), array_keys($sessions), $db);

        $results = [];
        foreach ($routes as $name => $route) {
            foreach ($roles as $role) {
                $results[] = $byRole[$role][$name] ?? [
                    'route' => $name, 'role' => $role, 'url' => '/' . ltrim($route->uri(), '/'), 'status' => null,
                    'redirect' => null, 'ms' => 0.0, 'result' => 'skipped',
                    'problems' => [['warn', preg_replace('/^\w+: skipped — /', '', $this->noteFor($notes, $role) ?? 'not run')]],
                ];
            }
        }

        $browser = null;
        if ($this->option('browser')) {
            $browser = (new BrowserPass($this, $serverSessionDriver))->run(
                $results, $sessions, rtrim((string) $this->option('base-url'), '/'), (bool) $this->option('browser-external')
            );
            foreach ($browser['notes'] ?? [] as $note) {
                $notes[] = 'browser: ' . $note;
            }
            $results = BrowserPass::merge($results, $browser['pages'] ?? []);
        }

        $counts = Report::summary($results);
        $exit = $counts['fail'] > 0 ? 1 : 0;
        $this->printTable($results);
        $line = Report::summaryLine($counts);
        foreach ($notes as $note) {
            $this->warn($note);
        }
        [$json, $html] = Report::write([
            'generated_at' => date('Y-m-d H:i:s'), 'database' => $db, 'roles' => $roles,
            'only' => (string) $this->option('only'), 'seconds' => round(microtime(true) - $started, 1),
            'summary' => $counts, 'summary_line' => $line, 'exit_code' => $exit, 'notes' => $notes,
            'results' => $results, 'browser' => $browser,
        ]);
        $this->newLine();
        $this->line(($exit ? '<error> FAIL </error> ' : '<info> PASS </info> ') . $line
            . sprintf('  (%.1fs)', microtime(true) - $started));
        $this->line('Report: ' . $html);
        $this->line('        ' . $json);

        return $exit;
    }

    /* --db switches the connection for this run. The real database is never a target. */
    private function useDatabase(): ?string
    {
        $conn = config('database.default');
        $db = (string) ($this->option('db') ?: config("database.connections.{$conn}.database"));
        if (self::isRefused($db)) {
            $this->error("Refusing to run against the database '{$db}': that is the real VENUSeP database. Use a venusep_laravel* copy.");
            return null;
        }
        if ($this->option('db')) {
            config(["database.connections.{$conn}.database" => $db]);
            DB::purge($conn);
        }
        try {
            $actual = (string) DB::connection()->selectOne('SELECT DATABASE() AS d')->d;
        } catch (\Throwable $e) {
            $this->error("Cannot open the database '{$db}': " . $e->getMessage());
            return null;
        }
        if (self::isRefused($actual)) {
            $this->error("Refusing to run: the connection points at '{$actual}'.");
            return null;
        }

        return $actual;
    }

    public static function isRefused(string $db): bool
    {
        return strtolower(trim($db, " \t\n\r\0\x0B`")) === self::REFUSED_DB;
    }

    private function roles(): ?array
    {
        $role = (string) $this->option('role');
        if ($role === '') {
            return Targets::ROLES;
        }
        if (!in_array($role, Targets::ROLES, true)) {
            $this->error("Unknown --role={$role}. Use one of: " . implode(', ', Targets::ROLES));
            return null;
        }

        return [$role];
    }

    private function noteFor(array $notes, string $role): ?string
    {
        foreach ($notes as $note) {
            if (str_starts_with($note, $role . ':')) {
                return $note;
            }
        }

        return null;
    }

    /** @return array<string, array<string, array>> role => route => result */
    private function runHere(array $routes, array $sessions): array
    {
        $out = [];
        foreach ($sessions as $role => $session) {
            $params = new Params($role, $session);
            foreach ($routes as $name => $route) {
                [$query, $missing] = $params->for($name);
                $out[$role][$name] = PageProbe::run($name, $route, $role, $session, $query, $missing);
            }
        }

        return $out;
    }

    /* --worker: one role, the routes the parent named; one line per page. */
    private function worker(string $db): int
    {
        $role = (string) $this->option('role');
        $session = in_array($role, Targets::ROLES, true) ? Targets::session($role) : "unknown role {$role}";
        if (is_string($session)) {
            fwrite(STDERR, $session . PHP_EOL);
            return 2;
        }
        $all = Targets::routes();
        $params = new Params($role, $session);
        foreach (array_filter(explode(',', (string) $this->option('routes'))) as $name) {
            fwrite(STDOUT, '@@VSTART ' . $name . PHP_EOL);
            $result = isset($all[$name])
                ? (function () use ($params, $name, $all, $role, $session) {
                    [$query, $missing] = $params->for($name);
                    return PageProbe::run($name, $all[$name], $role, $session, $query, $missing);
                })()
                : ['route' => $name, 'role' => $role, 'url' => '', 'status' => null, 'redirect' => null, 'ms' => 0.0,
                    'result' => 'fail', 'problems' => [['fail', 'route not found in the worker']]];
            fwrite(STDOUT, '@@VRESULT ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        }
        fwrite(STDOUT, '@@VDONE' . PHP_EOL);

        return 0;
    }

    /** @return array<string, array<string, array>> role => route => result */
    private function runWorkers(array $names, array $roles, string $db): array
    {
        /* compile every Blade view once, here: parallel workers compiling the same
           view at the same moment collide on Windows (rename: Access is denied) */
        try {
            \Illuminate\Support\Facades\Artisan::call('view:cache');
        } catch (\Throwable $e) {
            // a view that does not compile is then reported by the page that uses it
        }
        $results = array_fill_keys($roles, []);
        $procs = [];
        foreach ($roles as $role) {
            $procs[$role] = $this->startWorker($role, $names, $db);
        }
        while ($procs) {
            foreach ($procs as $role => $proc) {
                $timedOut = false;
                try {
                    $proc->checkTimeout();
                } catch (ProcessTimedOutException $e) {
                    $timedOut = true;
                }
                if (!$timedOut && $proc->isRunning()) {
                    continue;
                }
                unset($procs[$role]);
                [$got, $done, $current] = self::parseWorker($proc->getOutput());
                foreach ($got as $r) {
                    $results[$role][$r['route']] = $r;
                }
                if ($done) {
                    continue;
                }
                $remaining = array_values(array_filter($names, fn ($n) => !isset($results[$role][$n])));
                $why = $timedOut ? 'timed out after ' . self::WORKER_TIMEOUT . 's'
                    : 'the PHP process ended (exit code ' . $proc->getExitCode() . '): ' . self::tail($proc);
                if ($current === null) {   // died before its first page: give up on the rest
                    foreach ($remaining as $n) {
                        $results[$role][$n] = self::deadRow($n, $role, 'worker could not start — ' . $why);
                    }
                    continue;
                }
                $results[$role][$current] = self::deadRow($current, $role, 'request killed the worker — ' . $why);
                $remaining = array_values(array_diff($remaining, [$current]));
                if ($remaining) {
                    $procs[$role] = $this->startWorker($role, $remaining, $db);
                }
            }
            usleep(30000);
        }

        return $results;
    }

    private function startWorker(string $role, array $names, string $db): Process
    {
        $proc = new Process([
            PHP_BINARY, base_path('artisan'), 'venusep:check', '--worker', '--no-ansi',
            '--env=' . app()->environment(), '--role=' . $role, '--db=' . $db, '--routes=' . implode(',', $names),
        ], base_path(), null, null, self::WORKER_TIMEOUT);
        $proc->start();

        return $proc;
    }

    /** @return array{0: list<array>, 1: bool, 2: ?string} results, finished, route in progress */
    private static function parseWorker(string $out): array
    {
        $got = [];
        $done = false;
        $current = null;
        foreach (preg_split('/\R/', $out) as $line) {
            if (str_starts_with($line, '@@VSTART ')) {
                $current = substr($line, 9);
            } elseif (str_starts_with($line, '@@VRESULT ')) {
                $r = json_decode(substr($line, 10), true);
                if (is_array($r)) {
                    $got[] = $r;
                }
                $current = null;
            } elseif ($line === '@@VDONE') {
                $done = true;
            }
        }

        return [$got, $done, $current];
    }

    private static function tail(Process $proc): string
    {
        $text = preg_replace('/^@@V.*$/m', '', $proc->getOutput()) . "\n" . $proc->getErrorOutput();
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));

        return $text === '' ? '(no output)' : mb_substr($text, -400);
    }

    private static function deadRow(string $name, string $role, string $why): array
    {
        return ['route' => $name, 'role' => $role, 'url' => '', 'status' => null, 'redirect' => null, 'ms' => 0.0,
            'result' => 'fail', 'problems' => [['fail', $why]]];
    }

    private function printTable(array $results): void
    {
        $rows = [];
        foreach ($results as $r) {
            $status = $r['status'] === null ? '—' : (string) $r['status'];
            if ($r['redirect']) {
                $status .= ' → ' . mb_strimwidth($r['redirect'], 0, 40, '…');
            }
            $first = $r['problems'][0][1] ?? '';
            $more = count($r['problems']) > 1 ? ' (+' . (count($r['problems']) - 1) . ' more)' : '';
            $rows[] = [$r['route'], $r['role'], $status, Report::LABELS[$r['result']] ?? $r['result'],
                mb_strimwidth($first, 0, 90, '…') . $more];
        }
        $this->table(['Route', 'Role', 'Status', 'Result', 'Problems'], $rows);
    }
}
