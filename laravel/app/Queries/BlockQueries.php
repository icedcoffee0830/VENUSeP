<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for blocking a customer at one office (DB-DECISIONS 28): no new bookings there,
   online or at the counter. Columns: venusep_migration_17.sql.

   customer_office_blocks: one row per block. lifted_at NULL = the block is on; at most one
     such row per customer and office (uq_customer_office_blocks_active).
   Read only: customers, users, venues, rooms, bookings.

   The database enforces it too: trg_bookings_block_ins refuses a new booking, and
   sp_expire_due_bookings cancels a pending request still blocked when it is due to start. */
class BlockQueries
{
    /* The block on right now for this customer at this office, or null. */
    public static function findActive(int $customerId, int $venueId): ?array
    {
        $row = DB::selectOne("
            SELECT k.id, k.reason, k.blocked_at
            FROM customer_office_blocks k
            WHERE k.customer_id = ? AND k.venue_id = ? AND k.lifted_at IS NULL
        ", [$customerId, $venueId]);
        return $row ? (array) $row : null;
    }

    /* The office ids this customer is blocked at right now. */
    public static function activeOfficeIds(int $customerId): array
    {
        return array_map(fn ($row) => (int) $row->venue_id, DB::select("
            SELECT k.venue_id
            FROM customer_office_blocks k
            WHERE k.customer_id = ? AND k.lifted_at IS NULL
            ORDER BY k.venue_id
        ", [$customerId]));
    }

    /* The customers blocked at this office right now: customer id => true. */
    public static function blockedCustomersAt(int $venueId): array
    {
        return array_fill_keys(array_map(fn ($row) => (int) $row->customer_id, DB::select("
            SELECT k.customer_id
            FROM customer_office_blocks k
            WHERE k.venue_id = ? AND k.lifted_at IS NULL
        ", [$venueId])), true);
    }

    /* Every block on right now at these offices (null = every office), for the Customers page:
       customer id => venue id => reason, who and when. */
    public static function activeByCustomer(?array $venueIds): array
    {
        $rows = DB::select("
            SELECT k.customer_id, k.venue_id, k.reason, k.blocked_at,
                   COALESCE(s.full_name, u.username, u.email, 'Someone') AS blocked_by
            FROM customer_office_blocks k
            LEFT JOIN users u ON u.id = k.blocked_by_user_id
            LEFT JOIN staff s ON s.user_id = k.blocked_by_user_id
            WHERE k.lifted_at IS NULL
        " . ($venueIds === null ? '' : ' AND k.venue_id IN (' . implode(',', array_map('intval', $venueIds) ?: [0]) . ')'));
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->customer_id][(int) $row->venue_id] = (array) $row;
        }
        return $out;
    }

    /* Block the customer at the office. The caller has checked no block is on: a second one
       breaks the unique key, so two admins at once cannot both block. */
    public static function block(int $customerId, int $venueId, string $reason, int $actor): void
    {
        DB::insert("
            INSERT INTO customer_office_blocks (customer_id, venue_id, reason, blocked_by_user_id)
            VALUES (?, ?, ?, ?)
        ", [$customerId, $venueId, $reason, $actor]);
    }

    /* Lift the block on at the office. False when none was on (someone lifted it a moment ago). */
    public static function lift(int $customerId, int $venueId, string $reason, int $actor): bool
    {
        return DB::update("
            UPDATE customer_office_blocks
            SET lifted_at = NOW(),
                lifted_by_user_id = ?,
                lift_reason = ?
            WHERE customer_id = ? AND venue_id = ? AND lifted_at IS NULL
        ", [$actor, $reason, $customerId, $venueId]) === 1;
    }
}
