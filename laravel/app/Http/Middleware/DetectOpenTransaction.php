<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;

/* A forgotten commit loses the work without a word: the connection closes at the end of
   the request and the database rolls it back. Say so: always in laravel.log, and with
   APP_DEBUG on as an error on the screen. Never opens a connection of its own. */
class DetectOpenTransaction
{
    public function handle(Request $request, Closure $next)
    {
        $alreadyOpen = $this->open();   // a test's own transaction is not this request's
        $response = $next($request);
        if (!$alreadyOpen && $this->open()) {
            $what = 'Unfinished transaction at the end of ' . $request->method() . ' ' . $request->path();
            Log::error($what);
            if (config('app.debug')) {
                DB::connection()->getRawPdo()->rollBack();
                if (!($response->exception ?? null)) {   // an error page already showing is the real cause
                    throw new RuntimeException($what);
                }
            }
        }
        return $response;
    }

    private function open(): bool
    {
        $pdo = DB::connection()->getRawPdo();
        return $pdo instanceof PDO && $pdo->inTransaction();
    }
}
