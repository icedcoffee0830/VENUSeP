<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* customer_require_login(): a signed-in customer (or a remembered device),
   else the customer sign-in page — always that page, never ?next=. */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        legacy('auth');
        customer_require_login();

        return NoStore::apply($next($request));
    }
}
