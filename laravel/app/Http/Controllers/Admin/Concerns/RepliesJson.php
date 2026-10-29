<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;

/* The staff JSON endpoints' reply: status + body, then the request ends
   (the old ba_reply() / be_reply(), which echoed json_encode() and exit()ed). */
trait RepliesJson
{
    private function reply(int $status, array $data, array $headers = []): never
    {
        throw new HttpResponseException(
            response()->json($data, $status, ['Cache-Control' => 'no-store'] + $headers)
        );
    }
}
