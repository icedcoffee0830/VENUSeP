<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use App\Queries\RoomQueries;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/* CATALOG DELETE (was admin/catalog-delete.php) — deletes a room (hall or hostel room)
   from Venue Management: hidden from users, its row kept for the records (RoomQueries). The
   offices are fixed, never deleted. Booking/history rows are never deleted
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

                /* Deleted for users, kept as a hidden row (Aron 2026-11-05): past bookings,
                   receipts, History and reports keep its name. No undo. Refused while any
                   booking is pending, still to come or going on (trg_rooms_deleted_upd too). */
                $roomRow = null;
                $live = 0;
                DB::transaction(function () use ($roomCode, &$roomRow, &$live) {
                    $roomRow = RoomQueries::findLiveForUpdate($roomCode);
                    if ($roomRow === null || !staff_may_handle_venue($roomRow['venue_id'])) {   // a normal admin: its own offices only
                        return;
                    }
                    $live = RoomQueries::liveBookings((int) $roomRow['id']);
                    if ($live === 0) {
                        RoomQueries::delete((int) $roomRow['id'], (int) session('user_id'));
                    }
                });
                if ($roomRow === null) {
                    $this->reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
                }
                if (!staff_may_handle_venue($roomRow['venue_id'])) {
                    $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'This room belongs to another office.']);
                }
                if ($live > 0) {
                    $this->reply(409, ['ok' => false, 'error' => 'live_bookings', 'count' => $live,
                        'message' => $roomRow['name'] . ' has ' . $live . ($live === 1 ? ' booking' : ' bookings')
                            . ' still pending, coming up or going on, so it cannot be deleted. Finish, cancel or reject '
                            . ($live === 1 ? 'it' : 'them') . ' first.']);
                }
                /* the photos are not records: their files go (the code passed the regex
                   above, so this stays inside the rooms folder) */
                File::deleteDirectory(rp_room_dir($roomCode));

                $this->reply(200, ['ok' => true, 'entity' => 'room', 'name' => $roomRow['name'],
                    'message' => $roomRow['name'] . ' was deleted. Past bookings and receipts keep its name.']);
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
