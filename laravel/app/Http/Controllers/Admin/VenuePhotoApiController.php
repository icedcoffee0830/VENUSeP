<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/* =====================================================================
   VENUE PHOTO API (was admin/venue-photo-api.php) — backs the "Change
   photo" control on venue-form.

   GET   action=list                       venue_id
   POST  action=upload   _token, venue_id  file
   POST  action=delete   _token, venue_id

   Every reply is JSON: { ok: true, cover: url|null } or
   { ok: false, error, message }.
   ===================================================================== */
class VenuePhotoApiController extends Controller
{
    use RepliesJson;

    public function __invoke(Request $request)
    {
        $this->requireStaffJson();
        legacy('venue-photos');

        $method = $request->method();
        $bag = $method === 'GET' ? $request->query : $request->request;
        $action = (string) $bag->get('action', '');
        $venueId = (string) $bag->get('venue_id', '');

        if (!ctype_digit($venueId)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Invalid or missing venue id.']);
        }
        $venueId = (int) $venueId;

        if ($method === 'GET') {
            if ($action !== 'list') {
                $this->reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']);
            }
            $this->reply(200, ['ok' => true, 'cover' => vp_cover_url($venueId)]);
        }

        if ($method !== 'POST') {
            throw new HttpResponseException(response()->json(['ok' => false, 'error' => 'method', 'message' => 'Use GET or POST.'], 405)
                ->header('Cache-Control', 'no-store')->header('Allow', 'GET, POST'));
        }

        $this->requireCsrfJson($request);

        switch ($action) {
            case 'upload':
                $file = $request->file('file');
                if (!$file || is_array($file) || !$file->isValid()) {
                    $this->reply(400, ['ok' => false, 'error' => 'no_file', 'message' => 'Choose a photo.']);
                }
                $err = null;
                $url = vp_save_cover($venueId, $file->getPathname(), $file->getClientOriginalName(), $err);
                if ($url === null) {
                    $this->reply(400, ['ok' => false, 'error' => 'upload_failed', 'message' => $err ?: 'Upload failed.']);
                }
                $this->reply(200, ['ok' => true, 'cover' => $url]);

            case 'delete':
                vp_delete_cover($venueId);
                $this->reply(200, ['ok' => true, 'cover' => null]);

            default:
                $this->reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']);
        }
    }
}
