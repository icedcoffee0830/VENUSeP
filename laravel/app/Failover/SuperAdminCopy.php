<?php

namespace App\Failover;

use App\Http\Controllers\Auth\SignInThrottle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/* The emergency sign-in while the database is down (failover plan §3). Each laptop keeps a
   locked copy of the super admin's sign-in: storage/app/failover/super-admin.lock, encrypted
   with APP_KEY (the same on every laptop). The 1-minute task refreshes it from the main.
   The same laptop's database copy already holds the same hash and authenticator secret
   (decision #6), so the file adds nothing a thief would not already have.
   Signing in here is NOT a normal sign-in: it opens only the Server panel, for 10 minutes.
   No authenticator = no emergency sign-in. Recovery codes don't work here (decided). */
final class SuperAdminCopy
{
    public const MINUTES = 10;

    public static function path(): string
    {
        return env('VENUSEP_SUPER_ADMIN_COPY') ?: storage_path('app/failover/super-admin.lock');
    }

    /* From the main: rewritten only when something changed; removed when there is no super
       admin with an authenticator. Returns what happened, for the task's log line. */
    public static function refresh(): string
    {
        $u = DB::selectOne("SELECT id, email, password_hash, totp_secret FROM users
                             WHERE is_super_admin = 1 AND is_active = 1 AND account_type = 'admin'");
        if (!$u || !$u->totp_secret || !$u->password_hash) {
            @unlink(self::path());
            return 'no super admin with an authenticator: no emergency sign-in';
        }
        $data = ['id' => (int) $u->id, 'email' => $u->email, 'hash' => $u->password_hash, 'secret' => $u->totp_secret];
        if (self::read() === $data) {
            return 'unchanged';
        }
        @mkdir(dirname(self::path()), 0775, true);
        $tmp = self::path() . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, Crypt::encryptString(json_encode($data)));
        @rename($tmp, self::path()) || @unlink($tmp);
        return 'refreshed';
    }

    public static function exists(): bool
    {
        return self::read() !== null;
    }

    private static function read(): ?array
    {
        try {
            $d = json_decode(Crypt::decryptString((string) @file_get_contents(self::path())), true);
            return is_array($d) ? $d : null;
        } catch (Throwable) {
            return null;   // missing, or another APP_KEY, or tampered with
        }
    }

    /* null = right email, password and a fresh code; otherwise the message to show. Wrong tries
       count in the same cache-based limit as the sign-in pages (it works without the database). */
    public static function check(string $email, string $password, string $code): ?string
    {
        legacy('totp');
        if ($wait = SignInThrottle::enter($email)) {
            return SignInThrottle::message($wait);
        }
        $u = self::read();
        if ($u === null) {
            return 'This laptop has no emergency sign-in. Is the 1-minute task running (SETUP.md)?';
        }
        if (mb_strtolower(trim($email)) !== mb_strtolower($u['email']) || !password_verify($password, $u['hash'])) {
            $locked = SignInThrottle::failed($email);
            return $locked ? SignInThrottle::message($locked) : 'Wrong email or password.';
        }
        $last = Cache::get('failover.totp_step');
        $step = totp_match_step($u['secret'], $code, time(), $last);
        if ($step === null) {
            if (totp_is_used($u['secret'], $code, time(), $last)) {
                return 'That code was already used. Wait for the next code in your app, then try again.';
            }
            $locked = SignInThrottle::failed($email);
            return $locked ? SignInThrottle::message($locked) : 'That code is not right.';
        }
        Cache::put('failover.totp_step', $step, 3600);   // a code works once
        SignInThrottle::passed($email);
        return null;
    }

    /* The emergency session's account, or null when there is none or it ran out. */
    public static function session(): ?array
    {
        $s = session('failover_emergency');
        return is_array($s) && ($s['until'] ?? 0) > time() ? $s : null;
    }

    public static function open(): void
    {
        $u = self::read();
        session()->regenerate();
        session(['failover_emergency' => ['id' => $u['id'], 'email' => $u['email'], 'until' => time() + self::MINUTES * 60]]);
    }
}
