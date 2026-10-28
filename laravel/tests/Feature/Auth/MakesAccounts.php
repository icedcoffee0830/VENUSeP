<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;

/* Throw-away accounts for the auth tests: every test makes its own (unique
   email, known password), so no test depends on the seed's passwords or on
   rows another test changed. Removed again in tearDown. */
trait MakesAccounts
{
    protected array $madeUserIds = [];

    protected const PASSWORD = 'Correct-Horse-9';

    /* $type customer|staff|admin. Options: password (null = Google-only),
       hash (a ready-made hash), totp (base32 secret = 2FA on), active, google_sub. */
    protected function makeAccount(string $type, array $o = []): object
    {
        $email = 'lvtest-' . bin2hex(random_bytes(5)) . '@test.local';
        $password = array_key_exists('password', $o) ? $o['password'] : self::PASSWORD;
        $hash = $o['hash'] ?? ($password === null ? null : password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]));
        $id = DB::table('users')->insertGetId([
            'email' => $email,
            'username' => substr($email, 0, 18),
            'password_hash' => $hash,
            'google_sub' => $o['google_sub'] ?? null,
            'account_type' => $type,
            'is_active' => $o['active'] ?? 1,
            'totp_secret' => $o['totp'] ?? null,
            'totp_enabled_at' => isset($o['totp']) ? now() : null,
        ]);
        $this->madeUserIds[] = $id;
        $customerId = null;
        if ($type === 'customer') {
            $customerId = DB::table('customers')->insertGetId(['user_id' => $id, 'full_name' => $o['name'] ?? 'Test Customer']);
        } elseif ($type === 'staff') {
            DB::table('staff')->insert(['user_id' => $id, 'venue_id' => 1, 'full_name' => 'Test Staff']);
        }

        return (object) ['id' => $id, 'email' => $email, 'customer_id' => $customerId];
    }

    protected function signInAs(object $acct, string $type): static
    {
        $s = ['user_id' => $acct->id, 'account_type' => $type];
        if ($type === 'customer') {
            $s += ['customer_id' => $acct->customer_id, 'customer_name' => 'Test Customer', 'customer_email' => $acct->email, 'auth_at' => time()];
        }
        if ($type === 'admin') {
            $s['tfa_passed'] = true;
        }

        return $this->withSession($s);
    }

    /* The current code for a base32 secret (includes/totp.php). */
    protected function totpNow(string $secret, int $offsetSteps = 0): string
    {
        legacy('totp');

        return totp_code($secret, intdiv(time(), TOTP_PERIOD) + $offsetSteps);
    }

    protected function tearDown(): void
    {
        if ($this->madeUserIds) {
            $ids = $this->madeUserIds;
            foreach (['user_recovery_codes', 'remember_tokens', 'password_resets', 'customers', 'staff', 'notifications'] as $t) {
                try {
                    DB::table($t)->whereIn('user_id', $ids)->delete();
                } catch (\Throwable $e) {
                    // a table without user_id (or a FK that cascades) — fine
                }
            }
            try {
                DB::table('email_outbox')->whereIn('requested_by_user_id', $ids)->delete();
                DB::table('users')->whereIn('id', $ids)->delete();
            } catch (\Throwable $e) {
            }
        }
        parent::tearDown();
    }
}
