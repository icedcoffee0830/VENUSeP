<?php

namespace App\Failover;

use Closure;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/* THE ONE "database down" reply (failover plan §3). Three ways in, one page:
   - handle(): the web middleware. The database can't be reached when a request starts.
   - render(): a query failed mid-request because the connection was lost, or because the
     server is read-only (error 1290: a copy, or a switch is running).
   - venusep_db_or_fail(): the legacy pages' own check (legacy/includes/db.php).
   The emergency sign-in and the laptops' inbox never need the database, so they are let
   through. (Privacy and terms are not: their pages read the offices.) */
final class Outage
{
    use DetectsLostConnections;

    private const LET_THROUGH = ['failover.emergency', 'failover.emergency-action', 'failover.inbox'];

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->routeIs(...self::LET_THROUGH)) {
            try {
                DB::connection()->getPdo();
            } catch (Throwable) {
                return self::page(false, $request);
            }
        }
        return $next($request);
    }

    /* For bootstrap/app.php's withExceptions: null = not an outage, let the next handler reply. */
    public static function render(Throwable $e, Request $request): ?Response
    {
        if (!$e instanceof QueryException) {
            return null;
        }
        if ((int) ($e->errorInfo[1] ?? 0) === 1290) {   // ER_OPTION_PREVENTS_STATEMENT: --read-only
            return self::page(true, $request);
        }
        return (new self)->causedByLostConnection($e->getPrevious() ?? $e) ? self::page(false, $request) : null;
    }

    /* 503. A page for a browser; JSON for the pages' fetch() calls (the same Accept test
       bootstrap/app.php uses), so their scripts show a message instead of "could not reach". */
    public static function page(bool $readOnly = false, ?Request $request = null): Response
    {
        $request ??= request();
        $message = $readOnly
            ? 'The system is switching database servers. Your change was not saved. Try again in a minute.'
            : 'The database can’t be reached right now. Please try again in a minute.';
        if (!str_contains((string) $request->header('Accept'), 'text/html')) {
            return response()->json(['ok' => false, 'error' => 'db_down', 'message' => $message], 503, ['Retry-After' => '30']);
        }
        return response()->view('errors.db-unavailable', ['readOnly' => $readOnly], 503)->header('Retry-After', '30');
    }
}
