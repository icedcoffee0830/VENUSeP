<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Support\Facades\DB;

/* Shared by the AdminSettings tests: a known admin password for the
   re-auth endpoints (restored afterwards), and a fixed CSRF token. */
trait AdminTestHelpers
{
    protected string $pw = 'Test-pass-123';
    private ?string $savedHash = null;

    protected function useKnownAdminPassword(): void
    {
        $this->savedHash = DB::table('users')->where('email', 'admin@gmail.com')->value('password_hash');
        DB::table('users')->where('email', 'admin@gmail.com')->update([
            'password_hash' => password_hash($this->pw, PASSWORD_DEFAULT),
            'reauth_failed_attempts' => 0, 'reauth_lock_level' => 0, 'reauth_locked_until' => null,
        ]);
    }

    protected function restoreAdminPassword(): void
    {
        if ($this->savedHash !== null) {
            DB::table('users')->where('email', 'admin@gmail.com')->update([
                'password_hash' => $this->savedHash,
                'reauth_failed_attempts' => 0, 'reauth_lock_level' => 0, 'reauth_locked_until' => null,
            ]);
        }
    }

    protected function withTestCsrf(): static
    {
        return $this->withSession(['_token' => 'test-csrf-token']);
    }
}
