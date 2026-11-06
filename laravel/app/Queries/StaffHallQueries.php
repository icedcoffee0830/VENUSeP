<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for which halls a staff member covers (Aron 2026-11-05). Columns:
   venusep_migration_18.sql.

   staff_halls: one row per staff member and hall (an event room of their own office;
     trg_staff_halls_ins refuses any other). The hostel is one unit: hostel staff cover the
     whole hostel and have no rows. A new or moved staff member gets every hall of the
     office from the triggers (trg_staff_halls_new_ins, trg_staff_halls_follow_upd).
   rooms: a hall is an event room; deleted_at set = deleted, left out of every rule here.
   Read only: staff, users, venues.

   Every hall keeps at least one active staff member: lastStaffOf() names the halls a
   change would leave with nobody, and the caller refuses it. */
class StaffHallQueries
{
    /* The halls of these offices that are not deleted: [id, name, venue_id], by office. */
    public static function isSuperAdmin(int $userId): bool
    {
        return (bool) (DB::selectOne("SELECT is_super_admin FROM users WHERE id = ? AND account_type = 'admin'", [$userId])->is_super_admin ?? false);
    }

    /* The offices this session handles: an admin's group, a staff member's office, and any
       office of a cover going on now. */
    public static function officeIdsOf(int $userId): array
    {
        return array_map(fn ($r) => (int) $r->id, DB::select("
            SELECT v.id
            FROM users u
            LEFT JOIN staff s ON s.user_id = u.id
            JOIN venues v ON (u.account_type = 'admin' AND v.office_group = u.office_group)
                          OR (u.account_type = 'staff'
                              AND (v.id = s.venue_id
                                   OR EXISTS (SELECT 1 FROM staff_covers c WHERE c.user_id = u.id AND c.venue_id = v.id
                                                 AND " . STAFF_COVER_ON_SQL . ")))
            WHERE u.id = ?
            ORDER BY v.id
        ", [$userId]));
    }

    /* The rooms a staff member handles right now: room id => office id. */
    public static function roomsOf(int $userId): array
    {
        return array_map('intval', array_column(DB::select('SELECT r.id, r.venue_id FROM rooms r WHERE fn_staff_on_room(?, r.id)', [$userId]), 'venue_id', 'id'));
    }

    public static function hallsOf(array $venueIds): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT r.id, r.name, r.venue_id
            FROM rooms r
            WHERE r.room_type = 'event' AND r.deleted_at IS NULL
              AND r.venue_id IN (" . self::idList($venueIds) . ")
            ORDER BY r.venue_id, r.name
        "));
    }

    /* Each staff member's halls (not deleted): user id => [room id, ...]. */
    public static function hallsByStaff(): array
    {
        $out = [];
        foreach (DB::select("
            SELECT h.user_id, h.room_id
            FROM staff_halls h
            JOIN rooms r ON r.id = h.room_id AND r.deleted_at IS NULL
            ORDER BY r.name
        ") as $row) {
            $out[(int) $row->user_id][] = (int) $row->room_id;
        }
        return $out;
    }

    /* The active staff accounts of an office: user id => name. */
    public static function staffOf(int $venueId): array
    {
        $out = [];
        foreach (DB::select("
            SELECT s.user_id, s.full_name
            FROM staff s
            JOIN users u ON u.id = s.user_id
            WHERE s.venue_id = ? AND u.account_type = 'staff' AND u.is_active = 1
            ORDER BY s.full_name
        ", [$venueId]) as $row) {
            $out[(int) $row->user_id] = (string) $row->full_name;
        }
        return $out;
    }

    /* The halls this active staff member is the only active staff of, id => name, keeping
       $keep. $leaving: they leave the office (suspended, moved), so the hostel counts too
       (id 0 => the hostel's name). */
    public static function lastStaffOf(int $userId, array $keep = [], bool $leaving = true): array
    {
        $rows = DB::select("
            SELECT r.id, r.name
            FROM staff_halls h
            JOIN rooms r ON r.id = h.room_id AND r.deleted_at IS NULL
            JOIN users me ON me.id = h.user_id AND me.is_active = 1
            WHERE h.user_id = ? AND r.id NOT IN (" . self::idList($keep) . ")
              AND NOT EXISTS (SELECT 1 FROM staff_halls o JOIN users u ON u.id = o.user_id
                               WHERE o.room_id = r.id AND o.user_id <> h.user_id
                                 AND u.is_active = 1 AND u.account_type = 'staff')
            UNION ALL
            SELECT 0, v.name
            FROM staff s
            JOIN venues v ON v.id = s.venue_id AND v.venue_type = 'hostel'
            JOIN users me ON me.id = s.user_id AND me.is_active = 1 AND me.account_type = 'staff'
            WHERE s.user_id = ? AND ?
              AND NOT EXISTS (SELECT 1 FROM staff o JOIN users u ON u.id = o.user_id
                               WHERE o.venue_id = v.id AND o.user_id <> s.user_id
                                 AND u.is_active = 1 AND u.account_type = 'staff')
        ", [$userId, $userId, (int) $leaving]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = (string) $row->name;
        }
        return $out;
    }

    /* "Assign another staff member to <hall> first." for the halls lastStaffOf() named. */
    public static function lastStaffMessage(array $halls, string $verb = 'Assign'): string
    {
        $names = array_values($halls);
        $last = array_pop($names);
        return $verb . ' another staff member to ' . ($names ? implode(', ', $names) . ' and ' : '') . $last . ' first.';
    }

    /* The staff member's halls become exactly $hallIds (halls of their office; deleted
       halls are left as they are). */
    public static function setHalls(int $userId, array $hallIds, int $actor): void
    {
        DB::transaction(function () use ($userId, $hallIds, $actor) {
            DB::delete("
                DELETE h FROM staff_halls h
                JOIN rooms r ON r.id = h.room_id AND r.deleted_at IS NULL
                WHERE h.user_id = ? AND h.room_id NOT IN (" . self::idList($hallIds) . ")
            ", [$userId]);
            foreach ($hallIds as $hallId) {
                self::assign((int) $hallId, $userId, $actor);
            }
        });
    }

    /* Give the hall to the staff member (no change when they have it). */
    public static function assign(int $roomId, int $userId, int $actor): void
    {
        DB::insert("
            INSERT IGNORE INTO staff_halls (user_id, room_id, assigned_by_user_id)
            VALUES (?, ?, ?)
        ", [$userId, $roomId, $actor]);
    }

    /* Ids for an IN (...): whole numbers only; none matches nothing. */
    private static function idList(array $ids): string
    {
        return implode(',', array_map('intval', $ids) ?: [0]);
    }
}
