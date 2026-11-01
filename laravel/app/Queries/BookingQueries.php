<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/* The SQL that changes a booking. Being filled in during the cleanup: the rest still
   lives in the controllers. Columns: venusep_schema.sql (search "CREATE TABLE bookings").

   bookings: one row per booking request, venue or hostel.
     reservation_status: pending, approved, rejected, cancelled, released, completed, disrupted
   Trigger trg_bookings_after_update: on a status change, writes the booking's History line
   and frees its dates when the booking closes. */
class BookingQueries
{
    /* Approve a pending request: save the discount, then let sp_approve_booking
       set its status and payment deadline. */
    public static function approve(int $id, bool $idVerified, int $percent, float $discount, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $idVerified, $percent, $discount, $actor) {
            self::mustChange('approve', DB::update("
                UPDATE bookings
                SET affiliation_verified = ?,
                    discount_percent = ?,
                    discount_amount = ?,
                    total_amount = room_price - ?,
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$idVerified ? 1 : 0, $percent, $discount, $discount, $actor, $id]));
            DB::statement('CALL sp_approve_booking(?, ?)', [$id, $actor]);
        });
    }

    /* One save, all or nothing. The two @ variables tell the History trigger who is
       changing the booking and why, because a trigger cannot see who is signed in.
       A procedure may set its own note instead: sp_approve_booking does. */
    private static function saveAs(int $actor, string $note, callable $save): void
    {
        DB::statement('SET @venusep_actor_user_id = ?, @venusep_action_note = ?', [$actor, $note]);
        try {
            DB::transaction($save);
        } finally {
            /* even after a refusal, or the next save would be logged under this person */
            DB::statement('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
        }
    }

    /* A save that matched no row did nothing, silently: usually a broken WHERE.
       Always logged; shown on the page in debug mode. */
    private static function mustChange(string $what, int $rows): void
    {
        if ($rows > 0) {
            return;
        }
        $message = "BookingQueries::$what changed 0 rows";
        Log::error($message);
        if (config('app.debug')) {
            throw new RuntimeException($message);
        }
    }
}
