<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/* =====================================================================
   ROOM PHOTOS API (was admin/room-photos-api.php) — backs the "Edit
   photos" / "Add / replace 360°" controls on room-form and
   hostel-room-form.

   GET   action=list                            room_id
   POST  action=upload        _token, room_id   files[] (one or more)
   POST  action=delete        _token, room_id   file
   POST  action=reorder       _token, room_id   order[] (filenames, new order)
   POST  action=upload_pano   _token, room_id   file
   POST  action=delete_pano   _token, room_id

   Every reply is JSON: { ok: true, photos: [...], pano: url|null } or
   { ok: false, error, message }. Listing is read-only, so it skips CSRF.
   ===================================================================== */
class RoomPhotosApiController extends Controller
{
    use RepliesJson;

    private function state($roomId): array
    {
        return ['photos' => rp_list_photos($roomId), 'pano' => rp_pano_url($roomId), 'maxPhotos' => ROOM_PHOTO_MAX_COUNT];
    }

    public function __invoke(Request $request)
    {
        $this->requireStaffJson();
        legacy('room-photos');

        $method = $request->method();
        $bag = $method === 'GET' ? $request->query : $request->request;
        $action = $bag->get('action', '') ?? '';
        $roomId = $bag->get('room_id', '') ?? '';

        if (!rp_room_id_valid($roomId)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_room', 'message' => 'Invalid or missing room id.']);
        }

        if ($method === 'GET') {
            if ($action !== 'list') {
                $this->reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']);
            }
            $this->reply(200, array_merge(['ok' => true], $this->state($roomId)));
        }

        if ($method !== 'POST') {
            throw new HttpResponseException(response()->json(['ok' => false, 'error' => 'method', 'message' => 'Use GET or POST.'], 405)
                ->header('Cache-Control', 'no-store')->header('Allow', 'GET, POST'));
        }

        $this->requireCsrfJson($request);

        $uploaderId = (int) session('user_id');

        switch ($action) {
            case 'upload':
                if (!$request->files->has('files')) {
                    $this->reply(400, ['ok' => false, 'error' => 'no_file', 'message' => 'Choose at least one photo.']);
                }
                $files = $request->file('files');
                $files = is_array($files) ? $files : [$files];
                $firstError = null;
                $added = 0;
                foreach ($files as $file) {
                    /* a slot PHP could not upload arrives as null / invalid */
                    if (!$file || !$file->isValid()) { $firstError = $firstError ?: 'One of the files failed to upload.'; continue; }
                    $err = null;
                    $ok = rp_add_photo($roomId, $file->getPathname(), $file->getClientOriginalName(), $uploaderId, $err);
                    if ($ok === null) { $firstError = $firstError ?: $err; continue; }
                    $added++;
                }
                if ($added === 0) {
                    $this->reply(400, ['ok' => false, 'error' => 'upload_failed', 'message' => $firstError ?: 'Nothing was uploaded.']);
                }
                $this->reply(200, array_merge(['ok' => true, 'warning' => $firstError], $this->state($roomId)));

            case 'delete':
                $file = $request->request->get('file', '');
                if (!is_string($file) || $file === '') {
                    $this->reply(400, ['ok' => false, 'error' => 'bad_file', 'message' => 'Missing file.']);
                }
                rp_delete_photo($roomId, $file);
                $this->reply(200, array_merge(['ok' => true], $this->state($roomId)));

            case 'reorder':
                $order = $request->request->all()['order'] ?? null;
                if (!is_array($order)) {
                    $this->reply(400, ['ok' => false, 'error' => 'bad_order', 'message' => 'Missing order.']);
                }
                rp_reorder_photos($roomId, $order);
                $this->reply(200, array_merge(['ok' => true], $this->state($roomId)));

            case 'upload_pano':
                $file = $request->file('file');
                if (!$file || is_array($file) || !$file->isValid()) {
                    $this->reply(400, ['ok' => false, 'error' => 'no_file', 'message' => 'Choose a 360° photo.']);
                }
                $err = null;
                $url = rp_save_pano($roomId, $file->getPathname(), $file->getClientOriginalName(), $uploaderId, $err);
                if ($url === null) {
                    $this->reply(400, ['ok' => false, 'error' => 'upload_failed', 'message' => $err ?: 'Upload failed.']);
                }
                $this->reply(200, array_merge(['ok' => true], $this->state($roomId)));

            case 'delete_pano':
                rp_delete_pano($roomId);
                $this->reply(200, array_merge(['ok' => true], $this->state($roomId)));

            default:
                $this->reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']);
        }
    }
}
