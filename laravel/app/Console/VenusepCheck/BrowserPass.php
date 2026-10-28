<?php

namespace App\Console\VenusepCheck;

use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Symfony\Component\Process\Process;

/* --browser: every page that answered 200 HTML in the HTTP pass is loaded in a
   real browser (Edge or Chrome through Playwright, signed in as the same role)
   against a running `php artisan serve`, recording console errors, page errors
   and failed requests. Nothing is downloaded or installed: it uses a playwright
   (or playwright-core) package already on the machine — local node_modules or
   the global npm folder — and an installed Edge/Chrome. Requests to other sites
   (CDNs, fonts) are blocked unless --browser-external is given.

   Signing in: a session is written to the server's own session store (the
   driver in .env) and handed to the browser as Laravel's encrypted cookie. */
final class BrowserPass
{
    public const SCRIPT = 'tests/browser/check-pages.mjs';

    public function __construct(private readonly Command $cmd, private readonly string $sessionDriver)
    {
    }

    /** @return array{notes: list<string>, pages?: list<array>} */
    public function run(array $results, array $sessions, string $baseUrl, bool $allowExternal): array
    {
        $node = $this->probe(['node', '--version']);
        if ($node === null) {
            return ['notes' => ['skipped — Node.js is not on PATH. ' . self::HOW_TO]];
        }
        if (in_array($this->sessionDriver, ['array', 'cookie'], true)) {
            return ['notes' => ["skipped — SESSION_DRIVER={$this->sessionDriver} cannot be shared with the server; use file or database."]];
        }

        $pages = [];
        foreach ($results as $r) {
            if ($r['status'] === 200 && isset($sessions[$r['role']]) && in_array($r['result'], ['ok', 'warn', 'fail', 'needs_params'], true)) {
                $pages[] = ['role' => $r['role'], 'route' => $r['route'], 'url' => $r['url']];
            }
        }
        if (!$pages) {
            return ['notes' => ['nothing to load — no page answered 200 in the HTTP pass.']];
        }

        $cookieName = (string) config('session.cookie');
        $cookies = [];
        foreach (array_unique(array_column($pages, 'role')) as $role) {
            $cookies[$role] = $role === 'guest' ? null : $this->cookieFor($sessions[$role], $cookieName);
        }

        $input = tempnam(sys_get_temp_dir(), 'vcheck');
        file_put_contents($input, json_encode([
            'baseUrl' => $baseUrl, 'cookieName' => $cookieName, 'cookies' => $cookies,
            'pages' => $pages, 'allowExternal' => $allowExternal,
            'channel' => getenv('VENUSEP_BROWSER_CHANNEL') ?: null,
        ], JSON_UNESCAPED_SLASHES));
        $this->cmd->line('Browser pass: ' . count($pages) . " page loads against {$baseUrl}…");
        $proc = new Process(['node', base_path(self::SCRIPT), $input], base_path(), null, null, 900);
        try {
            $proc->run();
        } catch (\Throwable $e) {
            @unlink($input);
            return ['notes' => ['the browser script did not finish: ' . $e->getMessage()]];
        }
        @unlink($input);
        $out = json_decode(trim($proc->getOutput()), true);
        if (!is_array($out)) {
            return ['notes' => ['the browser script failed: ' . trim($proc->getErrorOutput() ?: $proc->getOutput()) . ' ' . self::HOW_TO]];
        }

        return $out + ['notes' => []];
    }

    public const HOW_TO = 'To enable the browser pass: install Node.js, have playwright (or @playwright/cli) installed '
        . '(npm i -g @playwright/cli), an installed Edge or Chrome (set VENUSEP_BROWSER_CHANNEL=chrome to pick Chrome), '
        . 'and start the site first: php artisan serve --port=8000.';

    /* a session the running server will accept: stored in its store, sent as its encrypted cookie */
    private function cookieFor(array $session, string $cookieName): string
    {
        $store = app('session')->driver($this->sessionDriver);
        $store->flush();
        $store->setId(null);
        foreach ($session as $k => $v) {
            $store->put($k, $v);
        }
        $store->put('_token', \Illuminate\Support\Str::random(40));
        $store->save();
        $encrypter = app('encrypter');

        return $encrypter->encrypt(CookieValuePrefix::create($cookieName, $encrypter->getKey()) . $store->getId(), false);
    }

    private function probe(array $cmd): ?string
    {
        try {
            $p = new Process($cmd, null, null, null, 20);
            $p->run();
            return $p->isSuccessful() ? trim($p->getOutput()) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
