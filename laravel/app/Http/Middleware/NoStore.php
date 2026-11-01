<?php

namespace App\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;

/* After logout, the Back button must not show a cached signed-in page. */
class NoStore
{
    public static function apply(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
