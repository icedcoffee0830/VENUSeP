<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use App\Queries\RoomQueries;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* =====================================================================
   ROOM TOGGLE ACTIVE (was admin/room-toggle-active.php) — flips
   rooms.is_active, called from the Enable/Disable button on each room
   card in Venue Management.

   POST  _token, room_id (room_code, e.g. "r1")
   Replies with JSON: { ok, active, name, message }

   This is NOT the delete flow (catalog-delete): it never removes a row,
   so it works on a room with real booking history, and it is reversible
   with the same click. FALSE just means "not offered right now". Existing
   bookings on the room are untouched; this only controls whether NEW
   customers can see or book it — the same filter every catalog read
   already applies (venue-rooms.php, hostel-rooms.php), and booking-submit
   re-checks it server side regardless of what the page showed.
   ===================================================================== */
class RoomToggleActiveController extends Controller
{
    use RepliesJson;

    public function __invoke(Request $request)
    {
        $this->requirePost($request);
        $this->requireStaffJson();

        $roomCode = trim((string) $request->request->get('room_id', ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $roomCode)) {
            $this->reply(400, ['ok' => false, 'error' => 'bad_room', 'message' => 'Invalid room.']);
        }

        legacy('db', 'bookings');   /* booking_reference() */
        if (venusep_db() === null) {
            $this->reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so nothing changed.']);
        }

        try {
            [$roomRow, $newActive, $notes] = DB::transaction(function () use ($roomCode) {
                $roomRow = RoomQueries::lockForToggle($roomCode);
                if (!$roomRow) {
                    $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
                }
                $this->requireRoomScope($roomRow['id'], $roomRow['venue_id']);

                $newActive = $roomRow['is_active'] ? 0 : 1;
                RoomQueries::setActive((int) $roomRow['id'], $newActive);
                /* Disabling keeps the room's upcoming bookings, but names them (the
                   same way room-save names bookings over a lowered capacity). */
                $notes = [];
                if (!$newActive) {
                    $refs = array_map(function ($b) { return booking_reference($b['id'], $b['submitted_at']); }, RoomQueries::upcomingBookings((int) $roomRow['id']));
                    if ($refs) {
                        $notes[] = 'These upcoming bookings are kept as booked: ' . implode(', ', $refs) . '.';
                    }
                }
                return [$roomRow, $newActive, $notes];
            });

            $this->reply(200, [
                'ok' => true, 'active' => (bool) $newActive, 'name' => $roomRow['name'], 'notes' => $notes,
                'message' => $roomRow['name'] . ($newActive ? ' is visible to customers again.' : ' is hidden from customers now.')
                    . ($notes ? "\n\n" . implode("\n\n", $notes) : ''),
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Nothing changed because the request could not be completed safely.']);
        }
    }
}
