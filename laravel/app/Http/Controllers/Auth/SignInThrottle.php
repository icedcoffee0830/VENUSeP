<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Support\Facades\Cache;

/* WRONG-PASSWORD LIMIT on both sign-in pages (customer + staff/admin).
   The same ladder as the users.reauth_* lockout (RefundSwitchController):
   5 wrong in a row = one lock, each lock longer than the last — 10s, 30s,
   1m, 5m, then 15m. A correct password starts it over.

   Kept in the cache, keyed by the TYPED email, not in users.reauth_*:
   - an email with no account locks exactly like a real one, so the lock
     never tells a stranger which emails exist;
   - a stranger's wrong sign-ins never lock an admin out of the in-session
     password checks (refund switch, GCash, backup) — the same reason
     two-factor.php keeps its own totp_* columns.
   A lock level is forgotten a day after the last wrong attempt. */
final class SignInThrottle
{
    const ATTEMPTS_PER_LOCK = 5;
    const LOCK_SECONDS = [10, 30, 60, 300, 900];   // lock #1, #2, #3, #4, #5 and later
    const FORGET_AFTER = 86400;

    private static function key(string $email): string
    {
        return 'signin-throttle:' . sha1(mb_strtolower(trim($email)));
    }

    /* Seconds left on a running lock; 0 = this email may try. */
    public static function secondsLeft(string $email): int
    {
        $s = Cache::get(self::key($email));

        return is_array($s) ? max(0, (int) $s['until'] - now()->getTimestamp()) : 0;
    }

    /* A wrong attempt. Returns the length of the lock it just started, or 0. */
    public static function failed(string $email): int
    {
        $s = Cache::get(self::key($email));
        $s = is_array($s) ? $s : ['attempts' => 0, 'level' => 0, 'until' => 0];
        $s['attempts']++;
        $seconds = 0;
        if ($s['attempts'] >= self::ATTEMPTS_PER_LOCK) {
            $s['level']++;
            $seconds = self::LOCK_SECONDS[min($s['level'], count(self::LOCK_SECONDS)) - 1];
            $s['attempts'] = 0;
            $s['until'] = now()->getTimestamp() + $seconds;
        }
        Cache::put(self::key($email), $s, self::FORGET_AFTER);

        return $seconds;
    }

    /* The password checked out. */
    public static function passed(string $email): void
    {
        Cache::forget(self::key($email));
    }

    public static function message(int $seconds): string
    {
        $wait = $seconds < 60
            ? $seconds . ' second' . ($seconds === 1 ? '' : 's')
            : (int) ceil($seconds / 60) . ' minute' . ((int) ceil($seconds / 60) === 1 ? '' : 's');

        return 'Too many wrong passwords. Try again in ' . $wait . '.';
    }
}
