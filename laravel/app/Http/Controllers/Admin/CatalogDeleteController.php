<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/* CATALOG DELETE (was admin/catalog-delete.php) — deletes an eligible room
   or venue from Venue Management. Booking/history rows are never deleted
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

        legacy('db', 'venue-photos');   /* + room-photos: rp_room_dir(), vp_delete_cover_file() */
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
                $room = $pdo->prepare('SELECT id, name FROM rooms WHERE room_code = :code FOR UPDATE');
                $room->execute([':code' => $roomCode]);
                $roomRow = $room->fetch();
                if (!$roomRow) {
                    $pdo->rollBack();
                    $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
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

            if ($entity === 'venue') {
                $rawVenueId = (string) $post->get('venue_id', '');
                if (!ctype_digit($rawVenueId) || (int) $rawVenueId < 1) {
                    $this->reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Invalid venue.']);
                }
                $venueId = (int) $rawVenueId;

                $pdo->beginTransaction();
                $venue = $pdo->prepare('SELECT id, name FROM venues WHERE id = :id FOR UPDATE');
                $venue->execute([':id' => $venueId]);
                $venueRow = $venue->fetch();
                if (!$venueRow) {
                    $pdo->rollBack();
                    $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Venue not found.']);
                }

                $roomCount = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE venue_id = :id');
                $roomCount->execute([':id' => $venueId]);
                if ((int) $roomCount->fetchColumn() > 0) {
                    $pdo->rollBack();
                    $this->reply(409, ['ok' => false, 'error' => 'has_rooms',
                        'message' => 'This venue cannot be deleted while it still contains rooms.']);
                }

                $businessCount = $pdo->prepare(
                    'SELECT (SELECT COUNT(*) FROM gcash_accounts WHERE venue_id = :venue1)
                          + (SELECT COUNT(*) FROM staff WHERE venue_id = :venue2)'
                );
                $businessCount->execute([':venue1' => $venueId, ':venue2' => $venueId]);
                if ((int) $businessCount->fetchColumn() > 0) {
                    $pdo->rollBack();
                    $this->reply(409, ['ok' => false, 'error' => 'business_dependencies',
                        'message' => 'This venue cannot be deleted because it has dependent business records.']);
                }

                $deleted = $pdo->prepare('DELETE FROM venues WHERE id = :id');
                $deleted->execute([':id' => $venueId]);
                $pdo->commit();
                vp_delete_cover_file($venueId);   /* venues.cover_photo's file */

                $this->reply(200, ['ok' => true, 'entity' => 'venue', 'name' => $venueRow['name'],
                    'message' => $venueRow['name'] . ' was deleted.']);
            }

            $this->reply(400, ['ok' => false, 'error' => 'bad_entity', 'message' => 'Choose a room or venue to delete.']);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('VENUSeP catalog-delete: ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Nothing was deleted because the request could not be completed safely.']);
        }
    }
}
