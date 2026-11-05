<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for deleting a hall or hostel room (Aron 2026-11-05). Columns:
   venusep_migration_18.sql.

   rooms: deleted_at / deleted_by_user_id set = deleted. The row stays, hidden, so past
     bookings, receipts, History and reports keep its name; is_active is 0 with it
     (chk_rooms_deleted_inactive), so nothing books it, and trg_bookings_room_deleted_ins
     refuses a new booking too. trg_rooms_deleted_upd refuses an undo, and a delete while
     fn_room_live_bookings() counts any booking still to come or going on.
   room_media: the photos go (they are not records); the files are the caller's.
   Read only: bookings, hostel_booking_details (through fn_room_live_bookings). */
class RoomQueries
{
    /* The room, not deleted, locked until the caller's transaction ends; or null. */
    public static function findLiveForUpdate(string $roomCode): ?array
    {
        $row = DB::selectOne("
            SELECT r.id, r.name, r.venue_id, r.room_type
            FROM rooms r
            WHERE r.room_code = ? AND r.deleted_at IS NULL
            FOR UPDATE
        ", [$roomCode]);
        return $row ? (array) $row : null;
    }

    /* How many of its bookings are pending, still to come or going on. */
    public static function liveBookings(int $roomId): int
    {
        return (int) DB::selectOne('SELECT fn_room_live_bookings(?) AS n', [$roomId])->n;
    }

    /* Delete the room for users, inside the caller's transaction. */
    public static function delete(int $roomId, int $actor): void
    {
        DB::update("
            UPDATE rooms
            SET deleted_at = NOW(),
                deleted_by_user_id = ?,
                is_active = 0
            WHERE id = ? AND deleted_at IS NULL
        ", [$actor, $roomId]);
        DB::delete('DELETE FROM room_media WHERE room_id = ?', [$roomId]);
    }
}
