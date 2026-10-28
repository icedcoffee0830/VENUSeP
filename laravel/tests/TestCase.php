<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/* Tests run against this worktree's own copy of the real database
   (.env.testing -> venusep_laravel_test_<area>), never migrations. Sign a test
   in with the same session keys the sign-in pages write (LARAVEL-PORT.md). */
abstract class TestCase extends BaseTestCase
{
    /* admin@gmail.com. Admins must have two-step verification on, or the staff
       guard signs them out (DB-DECISIONS #20) — so the test DB gets a key if it
       has none. */
    protected function actingAsAdmin(string $email = 'admin@gmail.com'): static
    {
        DB::update("UPDATE users SET totp_secret = 'JBSWY3DPEHPK3PXP', totp_enabled_at = NOW()
                     WHERE email = ? AND totp_secret IS NULL", [$email]);
        $id = DB::table('users')->where('email', $email)->value('id');

        return $this->withSession(['user_id' => (int) $id, 'account_type' => 'admin', 'tfa_passed' => true]);
    }

    /* staff@gmail.com — handles venue 1 only (staff_venue_scope()). */
    protected function actingAsStaff(string $email = 'staff@gmail.com'): static
    {
        $id = DB::table('users')->where('email', $email)->value('id');

        return $this->withSession(['user_id' => (int) $id, 'account_type' => 'staff']);
    }

    /* customer@gmail.com by default; any customer email works. */
    protected function actingAsCustomer(string $email = 'customer@gmail.com'): static
    {
        $row = DB::table('users as u')->join('customers as c', 'c.user_id', '=', 'u.id')
            ->where('u.email', $email)->select('u.id', 'c.id as customer_id', 'c.full_name', 'u.email')->first();

        return $this->withSession([
            'user_id' => (int) $row->id,
            'customer_id' => (int) $row->customer_id,
            'account_type' => 'customer',
            'customer_name' => $row->full_name,
            'customer_email' => $row->email,
            'auth_at' => time(),
        ]);
    }
}
