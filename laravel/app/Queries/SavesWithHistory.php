<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/* What every query file that changes a booking shares: one save at a time, the History
   trigger told who acted, a save that matched nothing noticed, and the History line an
   action writes itself. Used by BookingQueries, PaymentQueries and RefundQueries. */
trait SavesWithHistory
{
    /* One save, all or nothing. The two @ variables tell the History trigger who is
       changing the booking and why, because a trigger cannot see who is signed in.
       A procedure may set its own note instead: sp_approve_booking does.
       Returns whatever the save returns. */
    private static function saveAs(int $actor, string $note, callable $save): mixed
    {
        DB::statement('SET @venusep_actor_user_id = ?, @venusep_action_note = ?', [$actor, $note]);
        try {
            return DB::transaction($save);
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
        $message = class_basename(self::class) . "::$what changed 0 rows";
        Log::error($message);
        if (config('app.debug')) {
            throw new RuntimeException($message);
        }
    }

    /* An action that changes no status (paperwork, arrival, the attempt allowance): the
       History trigger never sees it, so its History line is written here. */
    private static function writeHistory(int $bookingId, string $code, int $actor, string $note, ?array $meta = null): void
    {
        DB::insert("
            INSERT INTO booking_timeline (booking_id, action_code, performed_by_user_id, note, metadata_json)
            VALUES (?, ?, ?, ?, ?)
        ", [$bookingId, $code, $actor, $note, $meta !== null ? json_encode($meta) : null]);
    }
}
