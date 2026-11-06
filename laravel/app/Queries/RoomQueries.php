<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for adding, saving, switching off and deleting a hall or hostel room.
   Columns: venusep_schema.sql (search "CREATE TABLE <name>") and venusep_migration_18.sql.

   rooms: one row per hall or hostel room. room_code (r9, h6) is generated, never typed.
     deleted_at / deleted_by_user_id set = deleted. The row stays, hidden, so past
     bookings, receipts, History and reports keep its name; is_active is 0 with it
     (chk_rooms_deleted_inactive), so nothing books it, and trg_bookings_room_deleted_ins
     refuses a new booking too. trg_rooms_deleted_upd refuses an undo, and a delete while
     fn_room_live_bookings() counts any booking still to come or going on.
     trg_rooms_type_ins: a room must suit its venue. trg_rooms_halls_follow_upd: a hall moved
     to another office goes to that office's staff.
   event_room_details: a hall's capacity and fee per day.
   hostel_room_details: a hostel room's rate, CR type, check-in and check-out times.
   hostel_beds: one row per bed. A bed is retired (is_active = 0), never deleted: past stays
     still point at it.
   maintenance_windows: a room's closures; blocks_booking = 1 is a hard closure.
   room_media: the photos go on a delete (they are not records); the files are the caller's.
   Read only: bookings, venue_booking_details, venue_booking_slots, bed_reservation_nights,
     hostel_booking_details (through fn_room_live_bookings). */
class RoomQueries
{
    /* The next room code of a series: "r" for halls, "h" for hostel rooms. */
    public static function nextCode(string $prefix): string
    {
        return $prefix . (int) DB::selectOne("
            SELECT COALESCE(MAX(CAST(SUBSTRING(room_code, 2) AS UNSIGNED)), 0) + 1 AS next_number
            FROM rooms
            WHERE room_code REGEXP CONCAT('^', ?, '[0-9]+$')
        ", [$prefix])->next_number;
    }

    /* The room's row; $amenities is already JSON. */
    public static function add(int $venueId, string $code, string $name, string $type, ?string $description, string $amenities): int
    {
        DB::insert("
            INSERT INTO rooms (venue_id, room_code, name, room_type, description, amenities)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$venueId, $code, $name, $type, $description, $amenities]);
        return (int) DB::getPdo()->lastInsertId();
    }

    /* A new hostel room's details, and its beds: the beds ARE its capacity. */
    public static function addHostelRoom(int $roomId, string $code, string $crType, float $rate, int $beds): void
    {
        DB::insert("
            INSERT INTO hostel_room_details (room_id, cr_type, rate_per_head_per_night)
            VALUES (?, ?, ?)
        ", [$roomId, $crType, $rate]);
        for ($i = 1; $i <= $beds; $i++) {
            self::addBed($roomId, $code, $i);
        }
    }

    private static function addBed(int $roomId, string $roomCode, int $number): void
    {
        DB::insert("
            INSERT INTO hostel_beds (room_id, bed_code, bed_label, display_order)
            VALUES (?, ?, ?, ?)
        ", [$roomId, $roomCode . '-b' . $number, 'Bed ' . $number, $number]);
    }

    /* A hall's capacity and fee, new or changed. */
    public static function saveEventDetails(int $roomId, int $capacity, float $fee): void
    {
        DB::insert("
            INSERT INTO event_room_details (room_id, attendee_capacity, fee_per_day)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE attendee_capacity = VALUES(attendee_capacity),
                                    fee_per_day = VALUES(fee_per_day)
        ", [$roomId, $capacity, $fee]);
    }

    /* The "at a glance" lines; a blank one is stored as nothing. */
    public static function saveGlance(int $roomId, array $glance): void
    {
        DB::update("
            UPDATE rooms
            SET best_for = ?,
                catering = ?,
                accessibility = ?
            WHERE id = ?
        ", [$glance['best_for'] ?: null, $glance['catering'] ?: null, $glance['accessibility'] ?: null, $roomId]);
    }

    /* A new hostel room's check-in and check-out times, as HH:MM. */
    public static function saveStayTimes(int $roomId, string $checkIn, string $checkOut): void
    {
        DB::update("
            UPDATE hostel_room_details
            SET check_in_time = ?,
                check_out_time = ?
            WHERE room_id = ?
        ", [$checkIn . ':00', $checkOut . ':00', $roomId]);
    }

    /* A room to edit, not deleted, or null. */
    public static function findForEdit(string $roomCode): ?array
    {
        $row = DB::selectOne("
            SELECT id, room_type, venue_id
            FROM rooms
            WHERE room_code = ? AND deleted_at IS NULL
        ", [$roomCode]);
        return $row ? (array) $row : null;
    }

    /* The office the room is under now, locked until the caller's transaction ends. */
    public static function lockVenueId(int $roomId): int
    {
        return (int) DB::selectOne('SELECT venue_id FROM rooms WHERE id = ? FOR UPDATE', [$roomId])->venue_id;
    }

    /* Pending or approved bookings still to come: id, submitted_at. */
    public static function upcomingBookings(int $roomId): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT b.id, b.submitted_at
            FROM bookings b
            WHERE b.room_id = ?
              AND b.reservation_status IN ('pending', 'approved')
              AND fn_booking_last_day(b.id) >= CURDATE()
            ORDER BY b.id
        ", [$roomId]));
    }

    public static function saveNameAndVenue(int $roomId, string $name, int $venueId): void
    {
        DB::update('UPDATE rooms SET name = ?, venue_id = ? WHERE id = ?', [$name, $venueId, $roomId]);
    }

    /* $amenities is already JSON. */
    public static function saveAmenities(int $roomId, string $amenities): void
    {
        DB::update('UPDATE rooms SET amenities = ? WHERE id = ?', [$amenities, $roomId]);
    }

    public static function saveDescription(int $roomId, ?string $description): void
    {
        DB::update('UPDATE rooms SET description = ? WHERE id = ?', [$description, $roomId]);
    }

    /* A hall's capacity and fee, or null. */
    public static function findEventDetails(int $roomId): ?array
    {
        $row = DB::selectOne("
            SELECT attendee_capacity, fee_per_day
            FROM event_room_details
            WHERE room_id = ?
        ", [$roomId]);
        return $row ? (array) $row : null;
    }

    /* Upcoming bookings expecting more people than $capacity: id, submitted_at. */
    public static function bookingsOverCapacity(int $roomId, int $capacity): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT b.id, b.submitted_at
            FROM bookings b
            JOIN venue_booking_details v ON v.booking_id = b.id
            WHERE b.room_id = ?
              AND b.reservation_status IN ('pending', 'approved')
              AND v.end_date >= CURDATE()
              AND v.attendee_count > ?
            ORDER BY b.id
        ", [$roomId, $capacity]));
    }

    public static function findHostelRate(int $roomId): float
    {
        return (float) (DB::selectOne('SELECT rate_per_head_per_night AS rate FROM hostel_room_details WHERE room_id = ?', [$roomId])->rate ?? 0);
    }

    public static function saveHostelDetails(int $roomId, float $rate, string $crType, string $checkIn, string $checkOut): void
    {
        DB::update("
            UPDATE hostel_room_details
            SET rate_per_head_per_night = ?,
                cr_type = ?,
                check_in_time = ?,
                check_out_time = ?
            WHERE room_id = ?
        ", [$rate, $crType, $checkIn . ':00', $checkOut . ':00', $roomId]);
    }

    /* Every bed of the room, retired ones too, in order. */
    public static function beds(int $roomId): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT id, bed_code, display_order, is_active
            FROM hostel_beds
            WHERE room_id = ?
            ORDER BY display_order, id
        ", [$roomId]));
    }

    public static function reactivateBed(int $bedId): void
    {
        DB::update('UPDATE hostel_beds SET is_active = 1 WHERE id = ?', [$bedId]);
    }

    /* New beds, numbered on from $lastOrder. */
    public static function addBeds(int $roomId, string $roomCode, int $lastOrder, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            self::addBed($roomId, $roomCode, $lastOrder + $i);
        }
    }

    /* The bookings with an upcoming night on these beds: id, submitted_at. */
    public static function bookingsOnBeds(array $bedIds): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT DISTINCT b.id, b.submitted_at
            FROM bed_reservation_nights n
            JOIN bookings b ON b.id = n.booking_id
            WHERE n.bed_id IN (" . implode(',', array_map('intval', $bedIds)) . ")
              AND n.released_at IS NULL
              AND n.night_date >= CURDATE()
            ORDER BY b.id
        "));
    }

    public static function retireBeds(array $bedIds): void
    {
        DB::update('UPDATE hostel_beds SET is_active = 0 WHERE id IN (' . implode(',', array_map('intval', $bedIds)) . ')');
    }

    /* Take away the current or future closure; windows that already ended stay as history. */
    public static function clearLiveClosure(int $roomId): void
    {
        DB::delete("
            DELETE FROM maintenance_windows
            WHERE room_id = ? AND (until_date IS NULL OR until_date >= CURDATE())
        ", [$roomId]);
    }

    public static function addClosure(int $roomId, string $from, ?string $until, string $reason, bool $hard, int $actor): void
    {
        DB::insert("
            INSERT INTO maintenance_windows (room_id, from_date, until_date, reason, blocks_booking, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$roomId, $from, $until, $reason, $hard ? 1 : 0, $actor]);
    }

    /* The live bookings inside a closure: id, submitted_at. */
    public static function bookingsInClosure(int $roomId, string $roomType, string $from, string $until): array
    {
        $rows = $roomType === 'hostel'
            ? DB::select("
                SELECT DISTINCT b.id, b.submitted_at
                FROM bed_reservation_nights n
                JOIN hostel_beds hb ON hb.id = n.bed_id
                JOIN bookings b ON b.id = n.booking_id
                WHERE hb.room_id = ?
                  AND n.released_at IS NULL
                  AND b.reservation_status IN ('pending', 'approved')
                  AND n.night_date BETWEEN ? AND ?
                ORDER BY b.id
            ", [$roomId, $from, $until])
            : DB::select("
                SELECT DISTINCT b.id, b.submitted_at
                FROM venue_booking_slots s
                JOIN bookings b ON b.id = s.booking_id
                WHERE s.room_id = ?
                  AND s.released_at IS NULL
                  AND b.reservation_status IN ('pending', 'approved')
                  AND s.slot_date BETWEEN ? AND ?
                ORDER BY b.id
            ", [$roomId, $from, $until]);
        return array_map(fn ($r) => (array) $r, $rows);
    }

    /* The room for the switch, not deleted, locked until the caller's transaction ends; or null. */
    public static function lockForToggle(string $roomCode): ?array
    {
        $row = DB::selectOne("
            SELECT id, name, is_active, venue_id
            FROM rooms
            WHERE room_code = ? AND deleted_at IS NULL
            FOR UPDATE
        ", [$roomCode]);
        return $row ? (array) $row : null;
    }

    public static function setActive(int $roomId, int $active): void
    {
        DB::update('UPDATE rooms SET is_active = ? WHERE id = ?', [$active, $roomId]);
    }

    /* Which room a photo call is about: its id, office, and whether it is deleted; or null. */
    public static function findForPhotos(string $roomCode): ?array
    {
        $row = DB::selectOne("
            SELECT id, venue_id, deleted_at IS NOT NULL AS deleted
            FROM rooms
            WHERE room_code = ?
        ", [$roomCode]);
        return $row ? (array) $row : null;
    }

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
