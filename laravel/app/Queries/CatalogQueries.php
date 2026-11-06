<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL that reads what is on offer: the offices, the rooms, and what is taken. It feeds
   legacy/includes/offices.php, venue-rooms.php and hostel-rooms.php, the one source every
   booking and management page reads. Read only.
   Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   venues: one row per office. venue_closures: the dates an office is closed.
   rooms, event_room_details, hostel_room_details, hostel_beds: the rooms (notes in RoomQueries).
   maintenance_windows: a room's closures.
   venue_booking_slots: the days a venue booking holds its room.
   bed_reservations, bed_reservation_nights, hostel_occupants: who sleeps where, each night. */
class CatalogQueries
{
    private static function rows(array $objects): array
    {
        return array_map(fn ($r) => (array) $r, $objects);
    }

    public static function offices(): array
    {
        return self::rows(DB::select('SELECT * FROM venues ORDER BY id'));
    }

    /* Office closures not over yet. */
    public static function officeClosures(): array
    {
        return self::rows(DB::select("
            SELECT id, venue_id, from_date, until_date, reason
            FROM venue_closures
            WHERE until_date >= CURDATE()
            ORDER BY from_date
        "));
    }

    /* The office of a room, or null. */
    public static function officeIdOfRoom(string $roomCode): ?int
    {
        $row = DB::selectOne('SELECT venue_id FROM rooms WHERE room_code = ?', [$roomCode]);
        return $row ? (int) $row->venue_id : null;
    }

    /* The office of a booking, or null. */
    public static function officeIdOfBooking(int $bookingId): ?int
    {
        $row = DB::selectOne("
            SELECT r.venue_id
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ?
        ", [$bookingId]);
        return $row ? (int) $row->venue_id : null;
    }

    /* The halls, not deleted. Customers get open ones only; a management page also those
       switched off, so a hall can be switched on again. */
    public static function eventRooms(bool $includeInactive): array
    {
        return self::rows(DB::select("
            SELECT r.id, r.room_code, r.name, r.description, r.amenities, r.is_active,
                   r.best_for, r.catering, r.accessibility,
                   r.venue_id, v.name AS venue_name,
                   d.attendee_capacity, d.fee_per_day
            FROM rooms r
            JOIN venues v ON v.id = r.venue_id
            LEFT JOIN event_room_details d ON d.room_id = r.id
            WHERE r.room_type = 'event' AND r.deleted_at IS NULL" . ($includeInactive ? '' : ' AND r.is_active = 1') . "
            ORDER BY r.id
        "));
    }

    /* The hostel rooms, not deleted, with their beds counted. */
    public static function hostelRooms(bool $includeInactive): array
    {
        return self::rows(DB::select("
            SELECT r.id, r.room_code, r.name, r.description, r.amenities, r.is_active,
                   r.venue_id, v.name AS venue_name,
                   d.cr_type, d.rate_per_head_per_night, d.check_in_time, d.check_out_time,
                   (SELECT COUNT(*) FROM hostel_beds b WHERE b.room_id = r.id AND b.is_active = 1) AS bed_count
            FROM rooms r
            JOIN venues v ON v.id = r.venue_id
            LEFT JOIN hostel_room_details d ON d.room_id = r.id
            WHERE r.room_type = 'hostel' AND r.deleted_at IS NULL" . ($includeInactive ? '' : ' AND r.is_active = 1') . "
            ORDER BY r.id
        "));
    }

    /* The name of the office that holds the hostel rooms. */
    public static function hostelName(): string
    {
        return (string) (DB::selectOne("SELECT name FROM venues WHERE venue_type = 'hostel' ORDER BY id LIMIT 1")->name ?? '');
    }

    /* Room closures not over yet, for one room type, every room in ONE query: per room, a
       closure going on comes first, then the next one due. */
    public static function roomClosures(string $roomType): array
    {
        return self::rows(DB::select("
            SELECT m.room_id, m.from_date, m.until_date, m.reason, m.blocks_booking
            FROM maintenance_windows m
            JOIN rooms r ON r.id = m.room_id
            WHERE r.room_type = ?
              AND (m.until_date IS NULL OR m.until_date >= CURDATE())
            ORDER BY m.room_id,
                     (m.from_date <= CURDATE()) DESC,
                     m.from_date ASC
        ", [$roomType]));
    }

    /* What is already taken, every hall in ONE query. These conditions MIRROR
       sp_add_venue_slot()'s check: what a customer sees as taken is what it would refuse. */
    public static function heldSlots(): array
    {
        return self::rows(DB::select("
            SELECT s.room_id, s.slot_date, s.start_time, s.end_time
            FROM venue_booking_slots s
            JOIN bookings b ON b.id = s.booking_id
            WHERE s.released_at IS NULL
              AND b.reservation_status IN ('pending', 'approved')
            ORDER BY s.slot_date, s.start_time
        "));
    }

    /* The beds taken, every room and night in ONE query. Released nights are left out:
       that is what frees a bed. No names: this reaches the customers' booking page. */
    public static function occupiedNights(): array
    {
        return self::rows(DB::select("
            SELECT hb.room_id, n.night_date, o.gender
            FROM bed_reservation_nights n
            JOIN hostel_beds hb ON hb.id = n.bed_id
            JOIN bed_reservations br ON br.id = n.bed_reservation_id
            JOIN hostel_occupants o ON o.id = br.occupant_id
            JOIN bookings bk ON bk.id = n.booking_id
            WHERE n.released_at IS NULL
              AND bk.reservation_status IN ('pending', 'approved', 'completed')
            ORDER BY hb.room_id, n.night_date, o.id
        "));
    }
}
