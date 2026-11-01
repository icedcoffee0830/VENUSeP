<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/* All the SQL that changes a booking.

   bookings: id, customer_id, room_id, booking_type, reservation_status, payment_status,
             payment_method, is_usep_affiliated, affiliation_verified, room_price,
             discount_percent, discount_amount, total_amount, quoted_discount_percent,
             current_deadline_at, approved_at, updated_by_user_id
   reservation_status: pending, approved, rejected, cancelled, released, completed, disrupted
   Every status change writes a History line: the trigger trg_bookings_after_update. */
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
       changing the booking and why, because a trigger cannot see who is signed in. */
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
