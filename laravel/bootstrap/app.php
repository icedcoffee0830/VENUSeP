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
        //
    })->create();
