<?php

namespace Tests\Feature\AdminSettings;

use App\Queries\ActivityQueries;
use App\Queries\SchemaQueries;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* The Activity page shows every recorded action, newest first, a page at a time, and the
   activity log (migration 21) records what no other table keeps. The tests that read or
   write activity_log are skipped until migration 21 is on this database. */
class ActivityLogTest extends TestCase
{
    use MakesAccounts { tearDown as removeAccounts; }

    private ?int $logBefore = null;   // null = no activity_log on this database

    protected function setUp(): void
    {
        parent::setUp();
        if (SchemaQueries::has('activity_log')) {
            $this->logBefore = (int) DB::table('activity_log')->max('id');
        }
    }

    private function needsMigration21(): void
    {
        if ($this->logBefore === null) {
            $this->markTestSkipped('run migration 21 (venusep_migration_21.sql) on the test database');
        }
    }

    /* The rows this test wrote. */
    private function logged(): array
    {
        return DB::table('activity_log')->where('id', '>', $this->logBefore)->orderBy('id')
            ->get(['user_id', 'role', 'action', 'subject', 'detail'])->map(fn ($r) => (array) $r)->all();
    }

    protected function tearDown(): void
    {
        if ($this->logBefore !== null) {
            DB::table('activity_log')->where('id', '>', $this->logBefore)->delete();
        }
        $this->removeAccounts();
    }

    public function test_the_page_is_paged_newest_first_and_a_page_past_the_end_shows_the_last(): void
    {
        $this->actingAsAdmin();
        $first = $this->get(route('admin.activity'))->assertOk()->assertDontSee('The latest 300');
        $total = (int) $first->viewData('total');
        $pages = (int) $first->viewData('pages');
        $this->assertSame(max(1, (int) ceil($total / 50)), $pages);
        $this->assertLessThanOrEqual(50, count($first->viewData('rows')));
        $at = array_column($first->viewData('rows'), 'at');
        $sorted = $at;
        rsort($sorted);
        $this->assertSame($sorted, $at, 'newest first');
        if ($pages > 1) {
            $first->assertSee('Page 1 of ' . $pages)->assertSee('rel="next"', false);
            $this->get(route('admin.activity', ['page' => 999999]))->assertOk()->assertViewHas('page', $pages);
        }
    }

    public function test_a_customers_own_booking_actions_show_too(): void
    {
        $c = $this->makeAccount('customer', ['name' => 'Timeline Tester']);
        $b = DB::table('bookings')->orderByDesc('id')->value('id');
        DB::table('booking_timeline')->insert(['booking_id' => $b, 'action_code' => 'status_changed', 'performed_by_user_id' => $c->id,
            'note' => 'Request cancelled by the customer.', 'occurred_at' => now()->addYears(5)]);   // the newest row, so page 1 has it
        try {
            $this->actingAsAdmin()->get(route('admin.activity'))->assertOk()
                ->assertSee('Booking #' . $b)->assertSee('Request cancelled by the customer.')->assertSee('(customer)');
        } finally {
            DB::table('booking_timeline')->where('performed_by_user_id', $c->id)->delete();
        }
    }

    public function test_logging_never_fails_the_action_before_migration_21(): void
    {
        ActivityQueries::add('test', 'Nothing', 'with or without the table');
        $this->actingAsAdmin()->get(route('admin.activity'))->assertOk();
    }

    public function test_sign_ins_and_failed_ones_are_logged_without_the_password(): void
    {
        $this->needsMigration21();
        $c = $this->makeAccount('customer');
        $this->post(route('customer.customer-login'), ['email' => $c->email, 'password' => 'Wrong-Secret-77'])->assertSee('Invalid email or password.');
        $this->post(route('customer.customer-login'), ['email' => $c->email, 'password' => self::PASSWORD])->assertRedirect(route('customer.venues'));
        $this->get(route('customer.logout'));

        $rows = $this->logged();
        $this->assertSame(['sign_in_failed', 'sign_in', 'sign_out'], array_column($rows, 'action'));
        $this->assertSame([$c->id, $c->id, $c->id], array_map('intval', array_column($rows, 'user_id')));
        $this->assertStringNotContainsString('Wrong-Secret-77', json_encode($rows));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($rows));

        $this->flushSession();
        $this->actingAsAdmin()->get(route('admin.activity'))->assertOk()->assertSee('Sign-in: ' . $c->email)->assertSee('Wrong email or password (customer sign-in)');
    }

    public function test_an_unknown_email_is_logged_with_nobody_behind_it(): void
    {
        $this->needsMigration21();
        $this->actingAsCustomer()->post(route('admin.admin-login'), ['email' => 'nobody-at-all@test.local', 'password' => 'x', 'department' => 1]);
        $this->assertSame([['user_id' => null, 'role' => null, 'action' => 'sign_in_failed', 'subject' => 'Sign-in: nobody-at-all@test.local',
            'detail' => 'Wrong email or password (staff sign-in)']], $this->logged());   // never the customer whose session it was
    }

    public function test_suspending_a_staff_account_is_logged_with_who_did_it(): void
    {
        $this->needsMigration21();
        $s = $this->makeAccount('staff');
        DB::table('staff')->where('user_id', $s->id)->update(['venue_id' => 3]);   // the hostel: one unit, no halls
        $admin = (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        $this->actingAsAdmin()->withSession(['_token' => 'tok'])
            ->post(route('admin.staff-save'), ['action' => 'set_active', 'user_id' => $s->id, 'active' => '0', 'csrf' => 'tok'])->assertOk();
        $this->assertSame([['user_id' => $admin, 'role' => 'admin', 'action' => 'account_suspended',
            'subject' => 'Account: ' . $s->email, 'detail' => 'Suspended the account']],
            array_map(fn ($r) => ['user_id' => (int) $r['user_id']] + $r, $this->logged()));
    }
}
