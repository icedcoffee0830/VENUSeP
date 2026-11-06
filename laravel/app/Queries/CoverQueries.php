<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for covers and moves between the two groups (Aron 2026-11-05). Columns:
   venusep_migration_18.sql.

   staff_covers: a staff member also handles another hall or office from starts_at to
     ends_at (7 days at most, chk_staff_covers_week). approved_at NULL = waiting for the
     destination group's admin; unanswered, it expires at starts_at. It ends by itself
     at ends_at (nothing runs then: every reader compares with NOW()), or early (ended_at).
   staff_moves: a permanent move to the other group, waiting for the destination's admin;
     unanswered, it expires 3 days after requested_at. replacements = JSON
     {"hall id": staff user id}: who takes each hall the move would leave with nobody.
   notifications: the admins on each side are told (kinds cover_* and move_*).
   staff: venue_id changes when a move is approved (StaffHallQueries for the halls).
   Read only: users, venues, rooms. */
class CoverQueries
{
    /* Each cover's state, from its times: waiting, expired, rejected, ended (early),
       scheduled, on, finished. */
    private const COVER_STATE = "CASE WHEN c.rejected_at IS NOT NULL THEN 'rejected'
            WHEN c.ended_at IS NOT NULL THEN 'ended'
            WHEN c.approved_at IS NULL AND c.starts_at <= NOW() THEN 'expired'
            WHEN c.approved_at IS NULL THEN 'waiting'
            WHEN c.ends_at <= NOW() THEN 'finished'
            WHEN c.starts_at > NOW() THEN 'scheduled'
            ELSE 'on' END";

    /* A cover that can still be answered, and a move that can (3 days). */
    private const COVER_WAITING = 'approved_at IS NULL AND rejected_at IS NULL AND ended_at IS NULL AND starts_at > NOW()';
    private const MOVE_WAITING = 'approved IS NULL AND requested_at > NOW() - INTERVAL 3 DAY';

    private const MOVE_STATE = "CASE WHEN m.approved = 1 THEN 'moved'
            WHEN m.approved = 0 THEN 'rejected'
            WHEN m." . self::MOVE_WAITING . " THEN 'waiting'
            ELSE 'expired' END";

    /* What waits for the signed-in admin to answer, for the sidebar: the group of the office that
       gets the cover or the person answers; the super admin, every one. */
    public static function waitingForMe(): array
    {
        $mine = admin_is_super() ? '' : ' AND v.office_group = (SELECT office_group FROM users WHERE id = ?)';
        $args = admin_is_super() ? [] : [(int) session('user_id')];
        return [
            'covers' => (int) DB::selectOne('SELECT COUNT(*) AS n FROM staff_covers c JOIN venues v ON v.id = c.venue_id WHERE c.'
                . str_replace(' AND ', ' AND c.', self::COVER_WAITING) . $mine, $args)->n,
            'moves'  => (int) DB::selectOne('SELECT COUNT(*) AS n FROM staff_moves m JOIN venues v ON v.id = m.to_venue_id WHERE m.'
                . str_replace(' AND ', ' AND m.', self::MOVE_WAITING) . $mine, $args)->n,
        ];
    }

    /* One cover with its state and both sides' groups, or null. */
    public static function findCover(int $id): ?array
    {
        $rows = self::covers('c.id = ?', [$id]);
        return $rows[0] ?? null;
    }

    /* The covers on Staff Management: waiting, scheduled or on now, and the ones that
       closed in the last 14 days. $group: a normal admin's group (either side of the
       cover is in it); null = the super admin, every cover. */
    public static function listCovers(?string $group): array
    {
        $recent = '(c.ends_at > NOW() - INTERVAL 14 DAY OR c.created_at > NOW() - INTERVAL 14 DAY)';
        return $group === null ? self::covers($recent) : self::covers("$recent AND ? IN (sv.office_group, v.office_group)", [$group]);
    }

    private static function covers(string $where, array $args = []): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT c.id, c.user_id, c.venue_id, c.room_id, c.starts_at, c.ends_at, c.reason, c.reject_reason,
                   " . self::COVER_STATE . " AS state,
                   s.full_name AS staff_name, sv.name AS staff_office, sv.office_group AS staff_group,
                   v.name AS office, v.office_group AS dest_group, r.name AS hall,
                   COALESCE(cs.full_name, cu.email) AS created_by
            FROM staff_covers c
            JOIN staff s ON s.user_id = c.user_id
            LEFT JOIN venues sv ON sv.id = s.venue_id
            JOIN venues v ON v.id = c.venue_id
            LEFT JOIN rooms r ON r.id = c.room_id
            LEFT JOIN users cu ON cu.id = c.created_by_user_id
            LEFT JOIN staff cs ON cs.user_id = c.created_by_user_id
            WHERE $where
            ORDER BY c.starts_at DESC, c.id DESC
        ", $args));
    }

    /* A new cover. $approved: set up by one admin of the group (or the super admin), so it
       counts from starts_at at once; otherwise it waits for the other group's admin. */
    public static function createCover(int $userId, int $venueId, ?int $roomId, string $from, string $to,
                                       string $reason, int $actor, bool $approved): int
    {
        DB::insert("
            INSERT INTO staff_covers (user_id, venue_id, room_id, starts_at, ends_at, reason,
                                      created_by_user_id, approved_at, approved_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL), IF(?, ?, NULL))
        ", [$userId, $venueId, $roomId, $from, $to, $reason, $actor, (int) $approved, (int) $approved, $actor]);
        return (int) DB::getPdo()->lastInsertId();
    }

    /* Approve a waiting cover. False when it is no longer waiting (answered, ended, or
       its start time came). */
    public static function approveCover(int $id, int $actor): bool
    {
        return DB::update("
            UPDATE staff_covers
            SET approved_at = NOW(), approved_by_user_id = ?
            WHERE id = ? AND " . self::COVER_WAITING . "
        ", [$actor, $id]) === 1;
    }

    public static function rejectCover(int $id, string $reason, int $actor): bool
    {
        return DB::update("
            UPDATE staff_covers
            SET rejected_at = NOW(), rejected_by_user_id = ?, reject_reason = ?
            WHERE id = ? AND " . self::COVER_WAITING . "
        ", [$actor, $reason, $id]) === 1;
    }

    /* End a cover before its time (a waiting one is withdrawn). False when it is already over. */
    public static function endCover(int $id, int $actor): bool
    {
        return DB::update("
            UPDATE staff_covers
            SET ended_at = NOW(), ended_by_user_id = ?
            WHERE id = ? AND rejected_at IS NULL AND ended_at IS NULL AND ends_at > NOW()
              AND (approved_at IS NOT NULL OR starts_at > NOW())
        ", [$actor, $id]) === 1;
    }

    /* ---------------------------------------------------------------- moves */

    public static function findMove(int $id): ?array
    {
        $rows = self::moves('m.id = ?', [$id]);
        return $rows[0] ?? null;
    }

    /* The moves on Staff Management: waiting ones and those closed in the last 14 days. */
    public static function listMoves(?string $group): array
    {
        $recent = '(m.approved IS NULL OR m.decided_at > NOW() - INTERVAL 14 DAY)';
        return $group === null ? self::moves($recent) : self::moves("$recent AND ? IN (fv.office_group, tv.office_group)", [$group]);
    }

    private static function moves(string $where, array $args = []): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT m.id, m.user_id, m.from_venue_id, m.to_venue_id, m.reason, m.replacements, m.reject_reason,
                   m.requested_at, " . self::MOVE_STATE . " AS state,
                   s.full_name AS staff_name, fv.name AS from_office, fv.office_group AS from_group,
                   tv.name AS to_office, tv.office_group AS to_group,
                   COALESCE(rs.full_name, ru.email) AS requested_by
            FROM staff_moves m
            JOIN staff s ON s.user_id = m.user_id
            JOIN venues fv ON fv.id = m.from_venue_id
            JOIN venues tv ON tv.id = m.to_venue_id
            LEFT JOIN users ru ON ru.id = m.requested_by_user_id
            LEFT JOIN staff rs ON rs.user_id = m.requested_by_user_id
            WHERE $where
            ORDER BY m.requested_at DESC, m.id DESC
        ", $args));
    }

    /* Is a move of this staff member still waiting? */
    public static function hasWaitingMove(int $userId): bool
    {
        return DB::selectOne("
            SELECT id FROM staff_moves
            WHERE user_id = ? AND " . self::MOVE_WAITING . "
        ", [$userId]) !== null;
    }

    public static function createMove(int $userId, int $from, int $to, string $reason, array $replacements, int $actor): int
    {
        DB::insert("
            INSERT INTO staff_moves (user_id, from_venue_id, to_venue_id, reason, replacements, requested_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$userId, $from, $to, $reason, $replacements ? json_encode($replacements) : null, $actor]);
        return (int) DB::getPdo()->lastInsertId();
    }

    /* Record the answer to a waiting, unexpired move. False when it is no longer waiting. */
    public static function decideMove(int $id, bool $approved, ?string $reason, int $actor): bool
    {
        return DB::update("
            UPDATE staff_moves
            SET approved = ?, decided_at = NOW(), decided_by_user_id = ?, reject_reason = ?
            WHERE id = ? AND " . self::MOVE_WAITING . "
        ", [(int) $approved, $actor, $reason, $id]) === 1;
    }

    /* The move itself, inside the caller's transaction: the new office (its halls come
       with it, the old ones go: trg_staff_halls_follow_upd). */
    public static function moveStaff(int $userId, int $from, int $to): bool
    {
        return DB::update('UPDATE staff SET venue_id = ? WHERE user_id = ? AND venue_id = ?', [$to, $userId, $from]) === 1;
    }

    /* ---------------------------------------------------------------- who and where */

    /* The account and office of a staff member, or null. */
    public static function staffAccount(int $userId): ?array
    {
        $row = DB::selectOne("
            SELECT u.id, u.account_type, u.is_active, u.is_super_admin, u.handed_over_at,
                   s.venue_id, s.full_name, v.office_group
            FROM users u
            JOIN staff s ON s.user_id = u.id
            LEFT JOIN venues v ON v.id = s.venue_id
            WHERE u.id = ?
        ", [$userId]);
        return $row ? (array) $row : null;
    }

    public static function office(int $venueId): ?array
    {
        $row = DB::selectOne('SELECT id, name, office_group FROM venues WHERE id = ?', [$venueId]);
        return $row ? (array) $row : null;
    }

    /* Every office: a cover or move can point at any of them. */
    public static function offices(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select('SELECT id, name, office_group FROM venues WHERE is_active = 1 ORDER BY id'));
    }

    /* ---------------------------------------------------------------- telling the admins */

    /* The active normal admins of a group, or the super admin when it has none; never the actor. */
    public static function notifyGroup(string $group, string $kind, int $actor, array $detail): void
    {
        DB::insert("
            INSERT INTO notifications (user_id, kind, actor_user_id, detail)
            SELECT u.id, ?, ?, ? FROM users u
            WHERE u.account_type = 'admin' AND u.is_active = 1 AND u.id <> ?
              AND ((u.is_super_admin = 0 AND u.office_group = ?)
                   OR (u.is_super_admin = 1 AND NOT EXISTS (
                         SELECT 1 FROM users n WHERE n.account_type = 'admin' AND n.is_active = 1
                            AND n.is_super_admin = 0 AND n.office_group = ?)))
        ", [$kind, $actor, json_encode($detail, JSON_UNESCAPED_UNICODE), $actor, $group, $group]);
    }
}
