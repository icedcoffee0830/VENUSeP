<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureStaff::class,
            'customer' => \App\Http\Middleware\EnsureCustomer::class,
            'booking.access' => \App\Http\Middleware\BookingAccess::class,
        ]);
        /* Laravel exempts only "password", so a pasted "abc " was saved as "abc" and failed at sign-in. */
        $middleware->trimStrings(except: ['new_password', 'confirm_new_password', 'confirm_password', 'creator_password']);
        $middleware->prepend(\App\Http\Middleware\DetectOpenTransaction::class);
        $middleware->web(append: [
            \App\Http\Middleware\EnforceTwoFactor::class,
        ]);
        // "Remember me" (#23) writes and reads its own cookie (legacy/includes/remember-me.php)
        $middleware->encryptCookies(except: ['venusep_remember']);
        /* The Cloudflare tunnel runs on this machine: believe its "this was https" header, so links and styles are built as https.
           Only that header: a forwarded host or port could point the links somewhere else. */
        $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /* A failed query keeps its SQL and its ? marks and loses its values, on the page, in the
           JSON and in the log: a value could be a password hash, and APP_DEBUG pages have been
           served publicly. The same exception object, so the real file and line stay. */
        $exceptions->map(\Illuminate\Database\QueryException::class, fn ($e) => (function () {
            $this->bindings = [];
            $this->message = $this->formatMessage($this->connectionName, $this->sql, [], $this->getPrevious());
            return $this;
        })->call($e));
        /* An expired page (bad CSRF token). Laravel's check stays on EVERY route;
           this only keeps the old replies: the JSON endpoints' scripts expect
           400 {"ok":false,"error":"csrf",...} (or 401 when the session is gone),
           never an HTML 419. A normal form post (Accept: text/html) still gets
           Laravel's 419 page. */
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            /* Laravel has already turned the TokenMismatchException into this 419 */
            if ($e->getStatusCode() !== 419 || str_contains((string) $request->header('Accept'), 'text/html')) {
                return null;
            }
            return $request->hasSession() && $request->session()->has('user_id')
                ? response()->json(['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.'], 400)
                : response()->json(['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.'], 401);
        });
        /* APP_DEBUG only: the pages' fetch() calls read JSON, so an error sql_failed() rethrew
           must reach them as JSON or they say "could not reach the server". Same test as above. */
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (!config('app.debug') || str_contains((string) $request->header('Accept'), 'text/html')
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }
            return response()->json(['ok' => false, 'error' => 'server', 'message' => $e->getMessage(),
                'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        });
    })->create();
