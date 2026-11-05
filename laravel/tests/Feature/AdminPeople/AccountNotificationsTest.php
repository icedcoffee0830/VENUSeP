<?php

namespace Tests\Feature\AdminPeople;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Who hears of an account event (DB-DECISIONS 28): a staff account's events go to the
   normal admins of that staff member's group, never the super admin; an admin account's
   (system-level) events go to the super admin; the one who acted gets nothing.
   admin@gmail.com = super admin; francishubero@gmail.com = Bahay Alumni; cedu.admin@gmail.com = CEDU. */
class AccountNotificationsTest extends TestCase
{
    use MakesAccounts;

    private int $before = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = (int) DB::table('notifications')->max('id');
    }

    protected function tearDown(): void
    {
        DB::table('notifications')->where('id', '>', $this->before)->delete();
        parent::tearDown();
    }

    private function staffAt(int $venue): object
    {
        $acct = $this->makeAccount('staff');
        DB::table('staff')->where('user_id', $acct->id)->update(['venue_id' => $venue]);
        return $acct;
    }

    /* who got a notification of $kind since the test began */
    private function told(string $kind): array
    {
        $to = DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.user_id')
            ->where('n.id', '>', $this->before)->where('n.kind', $kind)->pluck('u.email')->all();
        sort($to);
        return $to;
    }

    private function save(string $actorEmail, array $data)
    {
        return $this->actingAsAdmin($actorEmail)->withSession(['_token' => 'tok'])
            ->post(route('admin.staff-save'), $data + ['csrf' => 'tok'])->assertOk();
    }

    public function test_the_super_admin_suspending_hostel_staff_tells_the_cedu_admin_only(): void
    {
        $s = $this->staffAt(3);
        $this->save('admin@gmail.com', ['action' => 'set_active', 'user_id' => $s->id, 'active' => '0']);
        $this->assertSame(['cedu.admin@gmail.com'], $this->told('account_suspended'));
    }

    public function test_a_password_reset_by_the_groups_own_admin_tells_nobody_new(): void
    {
        $s = $this->staffAt(1);
        $this->save('francishubero@gmail.com', ['action' => 'reset_password', 'user_id' => $s->id, 'new_password' => 'Temporary-123']);
        $this->assertSame([], $this->told('account_password_reset'));   // the actor is the group's only admin; never the super admin
    }

    public function test_a_move_across_groups_tells_both_groups_admins(): void
    {
        $s = $this->staffAt(1);
        $this->save('admin@gmail.com', ['action' => 'set_venue', 'user_id' => $s->id, 'venue_id' => '2']);
        $this->assertSame(['cedu.admin@gmail.com', 'francishubero@gmail.com'], $this->told('staff_moved'));
    }

    public function test_a_move_inside_cedu_by_the_cedu_admin_skips_the_other_group(): void
    {
        $s = $this->staffAt(3);
        $this->save('cedu.admin@gmail.com', ['action' => 'set_venue', 'user_id' => $s->id, 'venue_id' => '2']);
        $this->assertSame([], $this->told('staff_moved'));
    }

    public function test_system_level_events_go_to_the_super_admin(): void
    {
        legacy('db', 'notifications');
        $francis = (int) DB::table('users')->where('email', 'francishubero@gmail.com')->value('id');
        notify_account_event(venusep_db(), 'account_created', $francis, ['email' => 'x@test.local', 'role' => 'admin'], null);
        $this->assertSame(['admin@gmail.com'], $this->told('account_created'));

        $sa = (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        notify_account_event(venusep_db(), 'account_reactivated', $sa, ['email' => 'x@test.local', 'role' => 'admin'], null);
        $this->assertSame([], $this->told('account_reactivated'));   // the super admin did it: nobody
    }

    public function test_a_new_staff_account_is_announced_to_its_group_only(): void
    {
        legacy('db', 'notifications');
        $sa = (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        notify_account_event(venusep_db(), 'account_created', $sa, ['email' => 'x@test.local', 'role' => 'staff'], [3]);
        $this->assertSame(['cedu.admin@gmail.com'], $this->told('account_created'));
    }
}
