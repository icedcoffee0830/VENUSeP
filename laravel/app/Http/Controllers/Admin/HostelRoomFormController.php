<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PDOException;

/* HOSTEL ROOM FORM (was admin/hostel-room-form.php) — Add / Edit a HOSTEL room.
   Venues come from the ONE shared source so the Location dropdown lists
   every venue that exists. Room photos are keyed by the room's id — see
   room-photos.php (DB row in room_media + the file on disk). */
class HostelRoomFormController extends Controller
{
    public function __invoke(Request $request)
    {
        /* a DISABLED room can be edited too (Venue Management links to it) */
        extract(legacy(['hrIncludeInactive' => true], 'venues', 'room-photos', 'hostel-rooms', 'amenities'));

        $roomId = $request->query('id') ?? '';
        $roomFormRoom = null;
        if (rp_room_id_valid($roomId)) {
            foreach ($hostelRooms as $r) {
                if ($r['id'] === $roomId) { $roomFormRoom = $r; break; }
            }
        }
        if ($roomFormRoom === null) $roomId = '';   // unknown id — behaves like Add

        /* THE AMENITY PICKER, built from the real vocabulary (amenities.php)
           rather than a list of its own. The vocabulary stays PHP-coded
           (DB-DECISIONS #9); only WHICH keys a room has is stored, in
           rooms.amenities. */
        $rfCurrent = [];
        if ($roomId !== '') {
            try {
                $rfStmt = venusep_db_or_fail()->prepare('SELECT amenities FROM rooms WHERE room_code = :c');
                $rfStmt->execute([':c' => $roomId]);
                $rfDecoded = json_decode((string) $rfStmt->fetchColumn(), true);
                $rfCurrent = is_array($rfDecoded) ? $rfDecoded : [];
            } catch (PDOException $e) {
                $rfCurrent = [];
            }
        }
        /* The hostel groups this time — "Load-in access" is not a thing a bed has. */
        $rfPool = [];
        foreach ($AMENITY_GROUPS_HOSTEL as $rfGroup) {
            $rfKeys = [];
            foreach ($AMENITY_GROUPS[$rfGroup] as $rfKey) {
                $rfKeys[] = ['key' => $rfKey, 'label' => $AMENITY_LABELS[$rfKey],
                             'on' => in_array($rfKey, $rfCurrent, true)];
            }
            $rfPool[] = ['group' => $rfGroup, 'items' => $rfKeys];
        }

        return view('admin.hostel-room-form', [
            'roomId' => $roomId,
            'roomFormRoom' => $roomFormRoom,
            'roomFormCsrf' => csrf_token(),
            'venues' => $venues,
            'rfPool' => $rfPool,
        ]);
    }
}
