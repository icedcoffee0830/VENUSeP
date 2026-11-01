<?php

namespace App\Console\Commands;

use App\Console\VenusepFlows\FlowsDb;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/* php artisan venusep:flows — walks whole PROCESSES (register → book → approve →
   pay → receipt …) in a real browser, like a person would. See "Flow tester" in
   LARAVEL-PORT.md. The flows themselves are tests/flows/flows/*.mjs.

   It resets its own database (venusep_laravel_flows by default) from the
   snapshot before every flow, starts `php artisan serve` on port 8120 against
   it, runs the Node runner and stops the server again. */
class VenusepFlows extends Command
{
    protected $signature = 'venusep:flows
        {--only= : only this flow (its name or file name; comma-separated for several)}
        {--headed : show the browser and slow it down, to watch}
        {--keep : leave the server running and the database as the last flow left it}
        {--reuse : use a server already listening on --port instead of starting one}
        {--db=venusep_laravel_flows : the database the flows run on (must start with venusep_laravel_flows or venusep_laravel_test)}
        {--port=8120 : where php artisan serve listens}
        {--snapshot= : the SQL snapshot to reset from (default: FLOWS_SNAPSHOT or the helpers _tools copy)}
        {--node=node : the Node.js binary}';

    protected $description = 'Walk VENUSeP processes end to end in a real browser and report per step';

    public function handle(): int
    {
        $db = strtolower((string) $this->option('db'));
        if (!FlowsDb::allowed($db)) {
            $this->error("Refusing to run against the database '{$db}': use one whose name starts with " . implode(' or ', FlowsDb::ALLOWED) . '.');
            return 2;
        }
        $port = (int) $this->option('port');
        if ($port === 8000) {
            $this->error('Port 8000 is kept free for the developer. Pick another --port.');
            return 2;
        }
        $snapshot = (string) ($this->option('snapshot') ?: env('FLOWS_SNAPSHOT', FlowsDb::DEFAULT_SNAPSHOT));
        if (!is_file($snapshot)) {
            $this->error("Snapshot not found: {$snapshot}. Pass --snapshot=<file.sql> or set FLOWS_SNAPSHOT.");
            return 2;
        }
        try {
            FlowsDb::connection($db);
        } catch (\Throwable $e) {
            $this->error("Cannot open the database '{$db}': " . $e->getMessage());
            return 2;
        }

        $runId = date('Ymd-His');
        $outDir = storage_path('app/venusep-flows/' . $runId);
        @mkdir($outDir, 0777, true);
        $baseUrl = "http://127.0.0.1:{$port}";
        /* Every child (server, runner, flows-tool) sees the flows database. A real
           environment variable wins over .env (Dotenv does not overwrite it). */
        $env = ['DB_DATABASE' => $db, 'FLOWS_SNAPSHOT' => $snapshot, 'APP_URL' => $baseUrl];

        $server = null;
        if ($this->option('reuse')) {
            if (!self::listening($port)) {
                $this->error("--reuse: nothing is listening on port {$port}.");
                return 2;
            }
            $this->warn("Reusing the server on port {$port} — make sure it was started with DB_DATABASE={$db}.");
        } else {
            if (self::listening($port)) {
                $this->error("Port {$port} is already in use. Stop that server, or pass --reuse if it runs on {$db}.");
                return 2;
            }
            $server = new Process([PHP_BINARY, base_path('artisan'), 'serve', '--host=127.0.0.1', "--port={$port}", '--no-reload'],
                base_path(), $env, null, null);
            if ($this->option("keep")) {
                $server->setOptions(["create_new_console" => true]);   // outlives this command (Windows)
            }
            $server->start();
            if (!self::waitFor($port, 20)) {
                $this->error('The server did not start: ' . trim($server->getErrorOutput() . $server->getOutput()));
                $server->stop(2);
                return 2;
            }
        }

        $cfgFile = $outDir . DIRECTORY_SEPARATOR . 'run-config.json';
        file_put_contents($cfgFile, json_encode([
            'baseUrl' => $baseUrl, 'db' => $db, 'php' => PHP_BINARY, 'artisan' => base_path('artisan'),
            'laravelDir' => base_path(), 'outDir' => $outDir, 'only' => (string) $this->option('only'),
            'headed' => (bool) $this->option('headed'), 'password' => FlowsDb::PASSWORD, 'runId' => $runId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line("Flows on <info>{$db}</info> at {$baseUrl} …");
        $runner = new Process([(string) $this->option('node'), base_path('tests/flows/run.mjs'), $cfgFile], base_path(), $env, null, null);
        try {
            $runner->run(function ($type, $buffer) {
                $this->output->write($buffer);
            });
        } finally {
            if ($server && !$this->option('keep')) {
                $server->stop(3);
            }
        }

        $report = json_decode((string) @file_get_contents($outDir . '/report.json'), true);
        if (!is_array($report)) {
            $this->error('The runner wrote no report (exit code ' . $runner->getExitCode() . ').');
            return 1;
        }
        $failed = $this->summary($report);
        $this->line('Report: ' . $outDir . DIRECTORY_SEPARATOR . 'report.html');
        if ($this->option('keep')) {
            $this->warn("--keep: " . ($server ? "the server is still running on {$baseUrl} (pid {$server->getPid()}); stop it yourself. " : '')
                . "The database {$db} keeps the last flow's rows. Sign in with password " . FlowsDb::PASSWORD);
            if ($server) {
                /* let this command end without killing the server */
                $server = null;
            }
        }

        return $failed || $runner->getExitCode() !== 0 ? 1 : 0;
    }

    /* the table, and true when anything failed */
    private function summary(array $report): bool
    {
        $rows = [];
        $count = ['pass' => 0, 'fail' => 0, 'skipped' => 0];
        foreach ($report['flows'] as $f) {
            if (!$f['steps']) {
                $rows[] = [$f['name'], '—', 'FAIL', '', mb_strimwidth((string) $f['error'], 0, 90, '…')];
                $count['fail']++;
            }
            foreach ($f['steps'] as $s) {
                $count[$s['status']] = ($count[$s['status']] ?? 0) + 1;
                $rows[] = [$f['name'], $s['n'] . '. ' . mb_strimwidth($s['title'], 0, 60, '…'), strtoupper($s['status']),
                    sprintf('%.1fs', $s['ms'] / 1000), mb_strimwidth((string) $s['error'], 0, 90, '…')];
            }
        }
        $this->newLine();
        $this->table(['Flow', 'Step', 'Result', 'Time', 'Error'], $rows);
        foreach ($report['notes'] ?? [] as $n) {
            $this->warn($n);
        }
        $failed = $count['fail'] > 0 || !empty($report['notes']) || !$report['flows'];
        $this->line(($failed ? '<error> FAIL </error> ' : '<info> PASS </info> ')
            . sprintf('%d passed, %d failed, %d skipped in %.1fs', $count['pass'], $count['fail'], $count['skipped'], $report['ms'] / 1000));

        return $failed;
    }

    private static function listening(int $port): bool
    {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($s) {
            fclose($s);
            return true;
        }

        return false;
    }

    private static function waitFor(int $port, int $seconds): bool
    {
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            if (self::listening($port)) {
                return true;
            }
            usleep(200000);
        }

        return false;
    }
}
