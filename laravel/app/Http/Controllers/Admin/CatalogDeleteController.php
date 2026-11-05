<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/* CATALOG DELETE (was admin/catalog-delete.php) — deletes an eligible room
   from Venue Management (the offices are fixed, never deleted). Booking/history rows are never deleted
   here. Admin only (re-read from the database, not the session). */
class CatalogDeleteController extends Controller
{
    use RepliesJson;

    public function __invoke(Request $request)
    {
        $this->requirePost($request);
        if (!session()->has('user_id')) {
            $this->reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
        }

        legacy('db', 'auth', 'venue-photos');   /* + room-photos: rp_room_dir() */
        $pdo = venusep_db();
        if ($pdo === null) {
            $this->reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so nothing was deleted.']);
        }

        /* Re-read the account instead of trusting a possibly stale session role. */
        $account = $pdo->prepare('SELECT account_type, is_active FROM users WHERE id = :id');
        $account->execute([':id' => (int) session('user_id')]);
        $accountRow = $account->fetch();
        if (!$accountRow || !(bool) $accountRow['is_active'] || $accountRow['account_type'] !== 'admin') {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only administrators can delete rooms or venues.']);
        }
        session(['account_type' => $accountRow['account_type']]);

        $post = $request->request;
        if ((string) $post->get('confirm', '') !== 'delete') {
            $this->reply(400, ['ok' => false, 'error' => 'confirmation', 'message' => 'Deletion was not confirmed.']);
        }

        $entity = (string) $post->get('entity', '');

        try {
            if ($entity === 'room') {
                $roomCode = trim((string) $post->get('room_id', ''));
                if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $roomCode)) {
                    $this->reply(400, ['ok' => false, 'error' => 'bad_room', 'message' => 'Invalid room.']);
                }

                $pdo->beginTransaction();
                $room = $pdo->prepare('SELECT id, name, venue_id FROM rooms WHERE room_code = :code FOR UPDATE');
                $room->execute([':code' => $roomCode]);
                $roomRow = $room->fetch();
                if (!$roomRow) {
                    $pdo->rollBack();
                    $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
                }
                if (!staff_may_handle_venue($roomRow['venue_id'])) {   // a normal admin: its own offices only
                    $pdo->rollBack();
                    $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'This room belongs to another office.']);
                }
                $roomId = (int) $roomRow['id'];

                $bookingCount = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE room_id = :id');
                $bookingCount->execute([':id' => $roomId]);
                if ((int) $bookingCount->fetchColumn() > 0) {
                    $pdo->rollBack();
                    $this->reply(409, ['ok' => false, 'error' => 'booking_history',
                        'message' => 'This room cannot be deleted because it has booking history.']);
                }

                /* These references should accompany a booking, but check them
                   independently so malformed legacy data can never be erased. */
                $slotCount = $pdo->prepare('SELECT COUNT(*) FROM venue_booking_slots WHERE room_id = :id');
                $slotCount->execute([':id' => $roomId]);
                $bedHistory = $pdo->prepare(
                    'SELECT (SELECT COUNT(*) FROM bed_reservations br JOIN hostel_beds hb ON hb.id = br.bed_id WHERE hb.room_id = :room1)
                          + (SELECT COUNT(*) FROM bed_reservation_nights bn JOIN hostel_beds hb ON hb.id = bn.bed_id WHERE hb.room_id = :room2)'
                );
                $bedHistory->execute([':room1' => $roomId, ':room2' => $roomId]);
                if ((int) $slotCount->fetchColumn() > 0 || (int) $bedHistory->fetchColumn() > 0) {
                    $pdo->rollBack();
                    $this->reply(409, ['ok' => false, 'error' => 'booking_history',
                        'message' => 'This room cannot be deleted because it has booking history.']);
                }

                /* Only room-owned configuration reaches this point. */
                $pdo->prepare('DELETE FROM maintenance_windows WHERE room_id = :id')->execute([':id' => $roomId]);
                $pdo->prepare('DELETE FROM room_media WHERE room_id = :id')->execute([':id' => $roomId]);
                $pdo->prepare('DELETE FROM event_room_details WHERE room_id = :id')->execute([':id' => $roomId]);
                $pdo->prepare('DELETE FROM hostel_room_details WHERE room_id = :id')->execute([':id' => $roomId]);
                $pdo->prepare('DELETE FROM hostel_beds WHERE room_id = :id')->execute([':id' => $roomId]);
                $deleted = $pdo->prepare('DELETE FROM rooms WHERE id = :id');
                $deleted->execute([':id' => $roomId]);
                $pdo->commit();
                /* the files those room_media rows pointed at (photos + 360°); the code
                   passed the regex above, so this stays inside the rooms folder */
                File::deleteDirectory(rp_room_dir($roomCode));

                $this->reply(200, ['ok' => true, 'entity' => 'room', 'name' => $roomRow['name'],
                    'message' => $roomRow['name'] . ' was deleted.']);
            }

            /* The three offices are fixed (DB-DECISIONS 28): nobody deletes one, the super admin included. */
            if ($entity === 'venue') {
                $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'The offices are fixed: an office cannot be deleted.']);
            }

            $this->reply(400, ['ok' => false, 'error' => 'bad_entity', 'message' => 'Choose a room or venue to delete.']);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Nothing was deleted because the request could not be completed safely.']);
        }
    }
}
