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
        $middleware->web(append: [
            \App\Http\Middleware\EnforceTwoFactor::class,
        ]);
        // "Remember me" (#23) writes and reads its own cookie (legacy/includes/remember-me.php)
        $middleware->encryptCookies(except: ['venusep_remember']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
    })->create();
