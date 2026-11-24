<?php

namespace App\Http\Controllers\Admin;

use App\Failover\ServerFile;
use App\Failover\Servers;
use App\Http\Controllers\Admin\Concerns\JsonEndpoint;
use App\Queries\AccountQueries;
use App\Queries\ActivityQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/* =====================================================================
   SERVER (failover plan §2) — the super admin only. The page lists the laptops' database
   servers; its buttons post here (action=...), JSON back:
     test ip            the server's report (Servers::probe)
     find               addresses on this hotspot where MySQL answers (once per 10 s)
     add ip / remove ip the laptops list (the server file)
     switch ip          make it the main              } each asks for the super admin's
     copy ip            make it a copy of the main    } password AND a fresh code from
     rebuild ip         reload a copy from the main   } the authenticator app
     saving on=0|1      allow saving without a copy   }
   Every action re-checks who is asking: the page is never trusted.
   ===================================================================== */
class ServerController
{
    use JsonEndpoint;

    public function index()
    {
        return view('admin.server', self::panel());
    }

    /* What the panel shows (the Server page, and the emergency page in piece 7). */
    public static function panel(): array
    {
        $rows = ServerFile::laptops();
        if (!array_filter($rows, [Servers::class, 'isMain'])) {
            array_unshift($rows, Servers::main());   // the main is always a row
        }
        $mine = Servers::myAddresses();
        return [
            'svRows' => array_map(fn ($ip, $i) => ['ip' => $ip, 'name' => 'Laptop ' . chr(65 + $i), 'mine' => Servers::isLocal($ip),
                'listed' => in_array($ip, ServerFile::laptops(), true)], $rows, array_keys($rows)),
            'svUrl' => $mine ? 'http://' . $mine[0] . ':' . request()->getPort() : null,
            'svProtected' => Servers::protectedSaving(),
            'svCsrf' => csrf_token(),
        ];
    }

    public function action(Request $request): JsonResponse
    {
        legacy('auth', 'two-factor');
        $actor = $this->actor();
        if ($actor === null) {
            return $this->reply(session()->has('user_id') ? 403 : 401, ['ok' => false, 'message' => 'Only the super admin can manage the servers.']);
        }
        if (!$this->csrfOk($request)) {
            return $this->reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }
        $action = (string) $request->input('action', '');
        $ip = trim((string) $request->input('ip', ''));
        if ($action !== 'find' && $action !== 'saving' && !ServerFile::isHost($ip)) {
            return $this->reply(422, ['ok' => false, 'message' => 'Type an IP address, like 192.168.43.12.']);
        }
        $laptops = ServerFile::laptops();

        switch ($action) {
            case 'test':
                return $this->reply(200, ['ok' => true, 'report' => Servers::probe($ip)]);

            case 'find':
                if (!Cache::add('failover.find', 1, 10)) {
                    return $this->reply(429, ['ok' => false, 'message' => 'Wait a few seconds before searching again.']);
                }
                return $this->reply(200, ['ok' => true, 'found' => Servers::find()]);

            case 'add':
                if (Servers::isLoopback($ip)) {
                    return $this->reply(422, ['ok' => false, 'message' => 'Use the laptop’s network address (shown under “This laptop”), not 127.0.0.1: the other laptops can’t reach that one.']);
                }
                ServerFile::write(['laptops' => array_values(array_unique([...$laptops, $ip]))], $actor['email']);
                return $this->reply(200, ['ok' => true]);

            case 'remove':
                if (Servers::isMain($ip)) {
                    return $this->reply(409, ['ok' => false, 'message' => 'The main can’t be removed. Switch to another server first.']);
                }
                ServerFile::write(['laptops' => array_values(array_diff($laptops, [$ip]))], $actor['email']);
                return $this->reply(200, ['ok' => true]);

            case 'switch':
            case 'copy':
            case 'rebuild':
            case 'saving':
                if ($no = $this->confirm($request, $actor)) {
                    return $no;
                }
                return $this->{'do' . ucfirst($action)}($request, $ip, $laptops, $actor);
        }
        return $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }

    private function doSwitch(Request $request, string $ip, array $laptops, array $actor): JsonResponse
    {
        if (Servers::isLoopback($ip) && count($laptops) > 1) {
            return $this->reply(422, ['ok' => false, 'message' => 'Use the laptop’s network address, not 127.0.0.1: the other laptops can’t reach that one.']);
        }
        $from = Servers::main();
        $result = Servers::switchTo($ip, $laptops);
        if ($result['ok']) {
            /* this request already talks to the new main; the next ones read the server file */
            config(['database.connections.mysql.host' => $ip]);
            DB::purge('mysql');
            $how = ['planned' => 'Switched the database server', 'emergency' => 'Emergency switch of the database server',
                'unlock' => 'Unlocked saving on the database server', 'point' => 'Pointed this system at the database server'][$result['mode']] ?? 'Switched the database server';
            ActivityQueries::add('server_switched', 'Database server', $how . ($result['mode'] === 'unlock' ? ' ' . $ip : ' from ' . $from . ' to ' . $ip), $actor['id'], 'admin');
        }
        return $this->reply($result['ok'] ? 200 : 409, $result + ['message' => $result['ok'] ? 'Switched. Every save now goes to ' . $ip . '.' : 'The switch stopped. Nothing after the failed step was done.']);
    }

    private function doCopy(Request $request, string $ip, array $laptops, array $actor): JsonResponse
    {
        $r = Servers::makeCopy($ip);
        if ($r['ok']) {
            ActivityQueries::add('server_copy', 'Database server', 'Made ' . $ip . ' a copy of ' . Servers::main(), $actor['id'], 'admin');
        }
        return $this->reply($r['ok'] ? 200 : 409, $r);
    }

    private function doRebuild(Request $request, string $ip, array $laptops, array $actor): JsonResponse
    {
        $r = Servers::rebuild($ip);
        if ($r['ok']) {
            ActivityQueries::add('server_rebuilt', 'Database server', 'Rebuilt the copy on ' . $ip . ' from ' . Servers::main(), $actor['id'], 'admin');
        }
        return $this->reply($r['ok'] ? 200 : 409, $r);
    }

    private function doSaving(Request $request, string $ip, array $laptops, array $actor): JsonResponse
    {
        $on = $request->boolean('on');   // on = protected again
        Servers::setProtected($on);       // first: an unprotected save must not wait for the log line below
        ActivityQueries::add($on ? 'saving_protected' : 'saving_unprotected', 'Database server',
            $on ? 'Saving waits for a copy again' : 'Allowed saving without a copy', $actor['id'], 'admin');
        return $this->reply(200, ['ok' => true, 'protected' => $on]);
    }

    /* The signed-in super admin, or null. */
    private function actor(): ?array
    {
        if (!admin_is_super()) {
            return null;
        }
        $id = (int) session('user_id');
        return ['id' => $id, 'email' => (string) (AccountQueries::findEmailAndHash($id)['email'] ?? '')];
    }

    /* The password (the shared reauth lockout), then a fresh authenticator code. Null = both right. */
    private function confirm(Request $request, array $actor): ?JsonResponse
    {
        $password = (string) $request->input('password', '');
        $code = (string) $request->input('code', '');
        if ($password === '' || trim($code) === '') {
            return $this->reply(400, ['ok' => false, 'message' => 'Type your password and the code from your authenticator app.']);
        }
        $pdo = venusep_db_or_fail();
        $pdo->beginTransaction();
        $user = AccountQueries::lockForPasswordCheck($actor['id']);
        if (!$user || !(bool) $user['is_active'] || $user['account_type'] !== 'admin' || !(bool) $user['is_super_admin']) {
            $pdo->rollBack();
            return $this->reply(403, ['ok' => false, 'message' => 'Only the super admin can manage the servers.']);
        }
        if ($no = reauth_verify($pdo, $user, $password)) {   // the shared lockout (auth.php)
            return isset($no['locked'])
                ? $this->reply(423, ['ok' => false, 'field' => 'password', 'message' => 'Too many wrong passwords. Try again in ' . $no['locked'] . ' seconds.'])
                : $this->reply(401, ['ok' => false, 'field' => 'password', 'message' => 'Wrong password. ' . $no['attemptsLeft'] . ($no['attemptsLeft'] === 1 ? ' try' : ' tries') . ' left.']);
        }
        $pdo->commit();
        $check = tfa_verify($pdo, $actor['id'], $code);
        if (!$check['ok']) {
            return $this->reply($check['error'] === 'locked' ? 423 : 401, ['ok' => false, 'field' => 'code', 'message' => tfa_error_message($check)]);
        }
        return null;
    }
}
