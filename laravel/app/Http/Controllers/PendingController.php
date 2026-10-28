<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/* Stand-in for a page that has not been ported to Laravel yet. 501 so the
   auto-tester (php artisan venusep:check) counts it as "not ported", never
   as working. */
class PendingController
{
    public function __invoke(Request $request)
    {
        return response()->view('pending', ['route' => $request->route()?->getName()], 501);
    }
}
