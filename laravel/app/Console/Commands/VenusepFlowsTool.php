<?php

namespace App\Console\Commands;

use App\Console\VenusepFlows\FlowsDb;
use Illuminate\Console\Command;

/* The flow runner's (tests/flows/run.mjs) small door into PHP. Prints ONE JSON
   object on stdout; on error {"error": "..."} and exit 1. Only ever touches a
   database FlowsDb::allowed() accepts.

     reset  --db=… [--settings='{"refunds_enabled":"1"}'] [--snapshot=…] [--mysql=…]
     select --db=…   reads {"sql": "SELECT …", "params": [...]} from stdin  -> {"rows": [...]}
     totp   --db=… --email=…                                                 -> {"code": "123456", "step": n} */
class VenusepFlowsTool extends Command
{
    protected $signature = 'venusep:flows-tool
        {action : reset, select or totp}
        {--db= : the flows database}
        {--email= : totp: whose code}
        {--settings= : reset: JSON object of system_settings to set after loading}
        {--snapshot= : reset: the SQL snapshot to load}
        {--mysql= : reset: the mysql client}';

    protected $description = 'Internal helper for php artisan venusep:flows (reset the flows DB, read-only SELECT, TOTP code)';

    protected $hidden = true;

    public function handle(): int
    {
        $db = (string) $this->option('db');
        try {
            FlowsDb::guard($db);
            $out = match ((string) $this->argument('action')) {
                'reset' => $this->reset($db),
                'select' => $this->select($db),
                'totp' => FlowsDb::totp($db, (string) $this->option('email')),
                default => throw new \RuntimeException('Unknown action: ' . $this->argument('action')),
            };
        } catch (\Throwable $e) {
            $this->output->writeln(json_encode(['error' => $e->getMessage()]));
            return 1;
        }
        $this->output->writeln(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return 0;
    }

    private function reset(string $db): array
    {
        $settings = json_decode((string) ($this->option('settings') ?: '{}'), true);
        if (!is_array($settings)) {
            throw new \RuntimeException('--settings must be a JSON object.');
        }
        $started = microtime(true);
        FlowsDb::reset($db,
            (string) ($this->option('snapshot') ?: env('FLOWS_SNAPSHOT', FlowsDb::DEFAULT_SNAPSHOT)),
            (string) ($this->option('mysql') ?: env('FLOWS_MYSQL', FlowsDb::DEFAULT_MYSQL)),
            $settings);

        return ['ok' => true, 'seconds' => round(microtime(true) - $started, 2)];
    }

    private function select(string $db): array
    {
        $req = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($req) || !is_string($req['sql'] ?? null)) {
            throw new \RuntimeException('select reads {"sql": "...", "params": [...]} from stdin.');
        }

        return ['rows' => FlowsDb::select($db, $req['sql'], array_values((array) ($req['params'] ?? [])))];
    }
}
