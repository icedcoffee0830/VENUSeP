<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* No other site may show these pages in a frame: a hidden frame over a decoy page could get a
   signed-in admin to click Approve, Mark refunded or Restore. Our own pages may (the ID viewer). */
class NoFraming
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);

        return $response;
    }
}
