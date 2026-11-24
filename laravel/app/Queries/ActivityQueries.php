<?php

namespace App\Queries;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/* activity_log (migration 21): what someone did that no other table keeps, for the super
   admin's Activity page (ActivityController). Booking actions are not here: booking_timeline
   already holds them, with who did them. */
class ActivityQueries
{
    /* One line of the log. $userId and $role default to the signed-in person; pass them for
       someone not signed in (yet): a sign-in, a failed one, a set-up at sign-in; 0 = nobody
       known (a wrong email, the nightly task), whoever is signed in here. $subject is
       what it was done to, $detail what happened, both in words. Never a password.
       Fail-soft: before migration 21 it writes nothing, and a failure is logged and
       swallowed, because the log must never cost the action it records. */
    public static function add(string $action, string $subject, ?string $detail = null, ?int $userId = null, ?string $role = null): void
    {
        try {
            if (!SchemaQueries::has('activity_log')) {
                return;
            }
            if ($userId === null) {
                [$userId, $role] = session('user_id') ? [(int) session('user_id'), $role ?? session('account_type')] : [0, $role];
            }
            DB::insert("
                INSERT INTO activity_log (user_id, role, action, subject, detail, ip)
                VALUES (?, ?, ?, ?, ?, ?)
            ", [$userId ?: null, $role, $action, mb_substr($subject, 0, 190), $detail === null ? null : mb_substr($detail, 0, 500), request()->ip()]);
        } catch (Throwable $e) {
            Log::error('Activity log not written (' . $action . '): '
                . ($e instanceof QueryException ? $e->getPrevious()->getMessage() : $e->getMessage()));
        }
    }
}
