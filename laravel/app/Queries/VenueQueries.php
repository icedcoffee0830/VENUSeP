<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for an office's own row and the staff filed under it, and the dashboard's tiles.
   Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   venues: one row per office (Bahay Alumni, USeP Venues, the Hostel). The offices are fixed.
     uq_venues_name: no two share a name.
   staff: one row per staff account; venue_id is the one office they handle.
     trg_staff_venue_required_upd refuses "no office", trg_staff_halls_follow_upd brings the
     new office's halls with them.
   Read only: users, rooms, bookings, venue_booking_details, hostel_booking_details. */
class VenueQueries
{
    /* An office by its name: its id and type; or null. */
    public static function findByName(string $name): ?array
    {
        $row = DB::selectOne('SELECT id, venue_type FROM venues WHERE name = ?', [$name]);
        return $row ? (array) $row : null;
    }

    public static function exists(int $venueId): bool
    {
        return DB::selectOne('SELECT id FROM venues WHERE id = ?', [$venueId]) !== null;
    }

    public static function saveNameAndDescription(int $venueId, string $name, string $description): void
    {
        DB::update('UPDATE venues SET name = ?, description = ? WHERE id = ?', [$name, $description, $venueId]);
    }

    /* These staff accounts, by name. An id that is not a staff account is left out. */
    public static function findStaff(array $userIds): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT s.user_id, s.full_name, s.venue_id, u.email
            FROM staff s
            JOIN users u ON u.id = s.user_id
            WHERE u.account_type = 'staff'
              AND s.user_id IN (" . implode(',', array_map('intval', $userIds)) . ")
            ORDER BY s.full_name
        "));
    }

    /* Move these staff to an office; its halls come with them. */
    public static function moveStaff(array $userIds, int $venueId): void
    {
        DB::update('UPDATE staff SET venue_id = ? WHERE user_id IN (' . implode(',', array_map('intval', $userIds)) . ')', [$venueId]);
    }

    /* Every staff account, by name, with the office it handles. */
    public static function allStaff(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT u.id, u.is_active, s.full_name, s.position_role, s.venue_id, v.name AS venue_name
            FROM users u
            JOIN staff s ON s.user_id = u.id
            LEFT JOIN venues v ON v.id = s.venue_id
            WHERE u.account_type = 'staff'
            ORDER BY s.full_name
        "));
    }

    /* The dashboard: bookings happening today, narrowed like every staff list. */
    public static function countBookingsToday(): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) AS n
            FROM bookings b
            LEFT JOIN venue_booking_details vd ON vd.booking_id = b.id
            LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
            WHERE (CURDATE() BETWEEN vd.start_date AND vd.end_date
                   OR (hd.check_in_date <= CURDATE() AND hd.check_out_date > CURDATE()))
              AND b.reservation_status IN ('approved', 'completed')"
            . staff_venue_sql('b.room_id IN (SELECT id FROM rooms WHERE venue_id IN (%s))', 'AND')
            . staff_hall_sql('b.room_id'))->n;
    }

    /* The dashboard: this person's offices, each with how many rooms are open. */
    public static function officesWithRooms(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT v.name, v.description, COUNT(r.id) AS rooms
            FROM venues v
            LEFT JOIN rooms r ON r.venue_id = v.id AND r.is_active = 1" . staff_hall_sql('r.id') . "
            WHERE v.is_active = 1" . staff_venue_sql('v.id IN (%s)', 'AND') . staff_hall_office_sql('v.id') . "
            GROUP BY v.id
            ORDER BY v.id
        "));
    }

    /* The name an account goes by on the dashboard: staff full name, else username or email. */
    public static function displayName(int $userId): ?string
    {
        return DB::selectOne("
            SELECT COALESCE(s.full_name, u.username, u.email) AS name
            FROM users u
            LEFT JOIN staff s ON s.user_id = u.id
            WHERE u.id = ?
        ", [$userId])->name ?? null;
    }
}
