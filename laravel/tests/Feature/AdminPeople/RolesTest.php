<?php

namespace Tests\Feature\AdminPeople;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Roles and offices (DB-DECISIONS 28, migration 17). Offices: 1 Bahay Alumni,
   2 USeP Venues, 3 USeP Hostel; groups: Bahay Alumni | USeP CEDU (2 and 3).
   admin@gmail.com = super admin; francishubero@gmail.com = Bahay Alumni admin;
   cedu.admin@gmail.com = USeP CEDU admin. Staff are made per test. */
class RolesTest extends TestCase
{
    use MakesAccounts;

    private const BAHAY = 1, VENUES = 2, HOSTEL = 3;

    /* Who signs in: [kind, venue, both CEDU offices] for staff, an email for the admins. */
    private function signInRole(string $role): static
    {
        return match ($role) {
            'super'      => $this->actingAsAdmin(),
            'bahay_na'   => $this->actingAsAdmin('francishubero@gmail.com'),
            'cedu_na'    => $this->actingAsAdmin('cedu.admin@gmail.com'),
            'venues'     => $this->signInAs($this->staffAt(self::VENUES), 'staff'),
            'hostel'     => $this->signInAs($this->staffAt(self::HOSTEL), 'staff'),
            'two_office' => $this->signInAs($this->staffAt(self::VENUES, true), 'staff'),
            'bahay'      => $this->signInAs($this->staffAt(self::BAHAY), 'staff'),
        };
    }

    private function staffAt(int $venue, bool $both = false): object
    {
        $acct = $this->makeAccount('staff');
        DB::table('staff')->where('user_id', $acct->id)->update(['venue_id' => $venue, 'both_cedu_offices' => $both]);
        DB::table('users')->where('id', $acct->id)->update(['password_changed_at' => now()]);
        return $acct;
    }

    /* One existing booking per office, as its reference. */
    private function bookingAt(int $venue): array
    {
        $b = DB::selectOne('SELECT b.id, b.submitted_at FROM bookings b JOIN rooms r ON r.id = b.room_id
                             WHERE r.venue_id = ? ORDER BY b.id DESC LIMIT 1', [$venue]);
        $this->assertNotNull($b, "office $venue has a booking in the test data");
        return ['id' => (int) $b->id, 'ref' => 'VB-' . date('Y', strtotime($b->submitted_at)) . '-' . str_pad((string) $b->id, 3, '0', STR_PAD_LEFT)];
    }

    private function postForm(string $route, array $data)
    {
        return $this->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')->post(route($route), $data);
    }

    public static function roles(): array
    {
        return [
            'super admin'       => ['super', [1, 2, 3]],
            'Bahay Alumni admin' => ['bahay_na', [1]],
            'USeP CEDU admin'   => ['cedu_na', [2, 3]],
            'Venues staff'      => ['venues', [2]],
            'Hostel staff'      => ['hostel', [3]],
            'two-office staff'  => ['two_office', [2, 3]],
            'Bahay Alumni staff' => ['bahay', [1]],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_sees_and_acts_on_its_own_offices_only(string $role, array $mine): void
    {
        $this->signInRole($role);
        foreach ([self::BAHAY, self::VENUES, self::HOSTEL] as $venue) {
            $b = $this->bookingAt($venue);
            $page = $this->get(route('admin.booking-request', ['id' => $b['ref']]))->assertOk()->getContent();
            $queue = $this->get(route('admin.booking-requests'))->assertOk()->getContent();
            /* the action is refused for another office before anything else is looked at */
            $act = $this->postForm('admin.booking-action', ['booking' => $b['ref'], 'action' => 'no_such_action']);
            if (in_array($venue, $mine, true)) {
                $this->assertStringContainsString('"' . $b['ref'] . '"', $page, "$role opens its own booking $b[ref]");
                $this->assertStringContainsString('"' . $b['ref'] . '"', $queue);
                $this->assertNotSame(403, $act->getStatusCode(), "$role may act on $b[ref]");
            } else {
                $this->assertStringNotContainsString('"' . $b['ref'] . '"', $page, "$role must not open $b[ref] by its URL");
                $this->assertStringNotContainsString('"' . $b['ref'] . '"', $queue);
                $act->assertStatus(403);
                $this->get(route('root.booking-details', ['booking' => $b['ref']]))->assertNotFound();
            }
        }
    }

    #[DataProvider('roles')]
    public function test_each_roles_lists_cover_its_offices(string $role, array $mine): void
    {
        $this->signInRole($role);
        $this->get(route('admin.dashboard'))->assertOk();   // the request that signs the session in
        legacy('bookings');
        $seen = DB::table('bookings as b')->join('rooms as r', 'r.id', '=', 'b.room_id')
            ->whereIn('b.id', array_column(bookings_all(), 'id') ?: [0])->distinct()->orderBy('r.venue_id')
            ->pluck('r.venue_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($mine, $seen);
    }

    public function test_office_label_on_booking_rows_only_for_mixed_lists(): void
    {
        $this->actingAsAdmin('cedu.admin@gmail.com')->get(route('admin.booking-requests'))->assertOk()
            ->assertSee('"office":"USeP Hostel"', false);
    }

    public function test_a_one_office_list_has_no_office_label(): void
    {
        $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.booking-requests'))->assertOk()
            ->assertSee('"office":""', false)->assertDontSee('"office":"Bahay Alumni"', false);
    }

    public function test_activity_and_backups_are_the_super_admins_only(): void
    {
        $this->actingAsAdmin()->get(route('admin.activity'))->assertOk();
        $this->actingAsAdmin()->get(route('admin.backup-restore'))->assertOk();
    }

    public function test_a_normal_admin_has_no_activity_backups_or_refund_switch(): void
    {
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $this->get(route('admin.activity'))->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.backup-restore'))->assertRedirect(route('admin.dashboard'));
        $this->postForm('admin.backup-action', ['action' => 'create'])->assertStatus(403);
        $this->postForm('admin.refund-switch', ['enable' => '1', 'password' => 'x'])->assertStatus(403)
            ->assertJson(['message' => 'Only the super admin can change the refund setting.']);
        $this->get(route('admin.dashboard'))->assertDontSee('Backup &amp; Restore', false)->assertDontSee('>Activity<', false);
    }

    public function test_office_settings_own_offices_for_a_normal_admin_all_for_the_super_admin(): void
    {
        $this->actingAsAdmin('francishubero@gmail.com');
        $this->get(route('admin.office-settings'))->assertOk()->assertSee('Bahay Alumni')->assertDontSee('USeP Hostel');
        $this->postForm('admin.office-save', ['venue_id' => self::HOSTEL, 'action' => 'closure_remove', 'closure_id' => 0])
            ->assertStatus(403);
        $this->postForm('admin.office-save', ['venue_id' => self::BAHAY, 'action' => 'closure_remove', 'closure_id' => 0])
            ->assertOk();
    }

    public function test_the_super_admin_sees_every_offices_settings(): void
    {
        $this->actingAsAdmin()->get(route('admin.office-settings'))->assertOk()
            ->assertSee('Bahay Alumni')->assertSee('USeP Venues')->assertSee('USeP Hostel');
    }

    public function test_gcash_accounts_are_the_offices_normal_admins_not_the_super_admins(): void
    {
        $this->actingAsAdmin()->get(route('admin.payment-settings'))->assertOk()->assertSee("Only the office's admin can change this", false);
        $this->postForm('admin.gcash-account-save', ['venue' => 'Bahay Alumni', 'account_name' => 'X', 'mobile_number' => '09171234567', 'password' => 'x'])
            ->assertStatus(403);
    }

    public function test_a_normal_admin_changes_only_its_own_offices_gcash(): void
    {
        $cedu = DB::table('users')->where('email', 'cedu.admin@gmail.com')->first();
        DB::table('users')->where('id', $cedu->id)->update(['password_hash' => password_hash(self::PASSWORD, PASSWORD_ARGON2ID)]);
        try {
            $this->actingAsAdmin('cedu.admin@gmail.com')->get(route('admin.payment-settings'))->assertOk()
                ->assertDontSee("Only the office's admin can change this", false)->assertDontSee('data-venue="Bahay Alumni"', false);
            $this->postForm('admin.gcash-account-save', ['venue' => 'Bahay Alumni', 'account_name' => 'X', 'mobile_number' => '09171234567', 'password' => self::PASSWORD])
                ->assertStatus(403);
        } finally {
            DB::table('users')->where('id', $cedu->id)->update(['password_hash' => $cedu->password_hash, 'reauth_failed_attempts' => 0]);
        }
    }

    public function test_only_the_super_admin_creates_admins_and_each_needs_a_group(): void
    {
        $form = ['full_name' => 'New Admin', 'email' => 'lvtest-na-' . bin2hex(random_bytes(4)) . '@test.local',
                 'password' => 'Long-enough-1', 'confirm_password' => 'Long-enough-1', 'terms' => '1', 'kind' => 'admin'];
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $this->postForm('root.register-submit', $form + ['office_group' => 'cedu'])->assertStatus(403);
        $this->actingAsAdmin()->get(route('admin.admin-register'))->assertSee('name="office_group"', false);
        $this->postForm('root.register-submit', $form)->assertStatus(400)->assertJson(['field' => 'office_group']);
    }

    public function test_a_normal_admin_adds_staff_to_its_own_offices_only(): void
    {
        $form = ['full_name' => 'New Staff', 'password' => 'Long-enough-1', 'confirm_password' => 'Long-enough-1', 'terms' => '1', 'kind' => 'staff'];
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $this->get(route('admin.admin-register'))->assertOk()->assertDontSee('value="admin"', false)->assertDontSee('>Bahay Alumni<', false);
        $this->postForm('root.register-submit', $form + ['email' => 'lvtest-' . bin2hex(random_bytes(4)) . '@test.local', 'venue_id' => self::BAHAY])
            ->assertStatus(403);
        $email = 'lvtest-' . bin2hex(random_bytes(4)) . '@test.local';
        $this->postForm('root.register-submit', $form + ['email' => $email, 'venue_id' => self::HOSTEL])->assertOk();
        $this->madeUserIds[] = (int) DB::table('users')->where('email', $email)->value('id');
    }

    public function test_a_normal_admin_manages_its_own_offices_staff_never_an_admin(): void
    {
        $venues = $this->staffAt(self::VENUES);
        $bahay = $this->staffAt(self::BAHAY);
        $francis = (int) DB::table('users')->where('email', 'francishubero@gmail.com')->value('id');
        $super = (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $roster = $this->get(route('admin.staff'))->assertOk();
        $roster->assertSee($venues->email)->assertDontSee($bahay->email)->assertDontSee('francishubero@gmail.com');

        $this->postForm('admin.staff-save', ['action' => 'set_active', 'user_id' => $bahay->id, 'active' => 0])->assertStatus(403);
        $this->postForm('admin.staff-save', ['action' => 'set_active', 'user_id' => $francis, 'active' => 0])->assertStatus(403);
        $this->postForm('admin.staff-save', ['action' => 'reset_password', 'user_id' => $super, 'new_password' => 'Long-enough-1'])->assertStatus(403);
        /* move: CEDU admin between Venues and Hostel only */
        $this->postForm('admin.staff-save', ['action' => 'set_venue', 'user_id' => $venues->id, 'venue_id' => self::BAHAY])->assertStatus(403);
        $this->postForm('admin.staff-save', ['action' => 'set_venue', 'user_id' => $venues->id, 'venue_id' => self::HOSTEL])->assertOk();
        $this->postForm('admin.staff-save', ['action' => 'set_venue', 'user_id' => $venues->id, 'venue_id' => self::HOSTEL, 'both_cedu' => 1])->assertOk();
        $row = DB::table('staff')->where('user_id', $venues->id)->first();
        $this->assertSame([self::HOSTEL, 1], [(int) $row->venue_id, (int) $row->both_cedu_offices]);
        $this->postForm('admin.staff-save', ['action' => 'reset_password', 'user_id' => $venues->id, 'new_password' => 'Long-enough-1'])->assertOk();
    }

    public function test_the_super_admin_manages_admins_but_nobody_resets_the_super_admin(): void
    {
        $na = $this->makeAccount('admin');
        DB::table('users')->where('id', $na->id)->update(['office_group' => 'cedu']);
        $super = (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        $this->actingAsAdmin();
        $this->postForm('admin.staff-save', ['action' => 'reset_password', 'user_id' => $na->id, 'new_password' => 'Long-enough-1'])->assertOk();
        $this->postForm('admin.staff-save', ['action' => 'reset_password', 'user_id' => $super, 'new_password' => 'Long-enough-1'])->assertStatus(403);
        $this->postForm('admin.staff-save', ['action' => 'set_active', 'user_id' => $na->id, 'active' => 0])->assertOk();
    }

    public function test_handing_over_the_super_admin(): void
    {
        $na = $this->makeAccount('admin', ['totp' => 'JBSWY3DPEHPK3PXP']);
        DB::table('users')->where('id', $na->id)->update(['office_group' => 'bahay_alumni']);
        $old = DB::table('users')->where('email', 'admin@gmail.com')->first();
        DB::table('users')->where('id', $old->id)->update(['password_hash' => password_hash(self::PASSWORD, PASSWORD_ARGON2ID)]);
        $outboxBefore = (int) DB::table('email_outbox')->max('id');
        try {
            $this->actingAsAdmin();
            $this->postForm('admin.staff-save', ['action' => 'handover', 'user_id' => $na->id, 'reason' => 'Leaving', 'password' => 'wrong'])
                ->assertStatus(401);
            $this->postForm('admin.staff-save', ['action' => 'handover', 'user_id' => $na->id, 'reason' => '', 'password' => self::PASSWORD])
                ->assertStatus(400);
            $this->postForm('admin.staff-save', ['action' => 'handover', 'user_id' => $na->id, 'reason' => 'Leaving the office', 'password' => self::PASSWORD])
                ->assertOk()->assertJson(['ok' => true, 'signed_out' => true]);

            $now = DB::table('users')->whereIn('id', [$old->id, $na->id])->get(['id', 'is_super_admin', 'is_active', 'office_group', 'handed_over_at'])->keyBy('id');
            $this->assertSame([1, 1, null], [(int) $now[$na->id]->is_super_admin, (int) $now[$na->id]->is_active, $now[$na->id]->office_group]);
            $this->assertSame([0, 0], [(int) $now[$old->id]->is_super_admin, (int) $now[$old->id]->is_active]);
            $this->assertNotNull($now[$old->id]->handed_over_at);
            $mails = DB::table('email_outbox')->where('id', '>', $outboxBefore)->where('kind', 'super_admin_handover')->pluck('to_email')->sort()->values()->all();
            $this->assertSame(collect([$old->email, $na->email])->sort()->values()->all(), $mails);
            /* a system-level event: the new super admin's bell, nobody else's */
            $this->assertSame([$na->id], DB::table('notifications')->where('kind', 'super_admin_handover')->pluck('user_id')->map(fn ($id) => (int) $id)->all());

            /* the old account is out: its next click finds no session, and nobody can bring it back */
            $this->get(route('admin.dashboard'))->assertRedirect();
            $this->flushSession();
            $this->signInAs($na, 'admin');
            $this->postForm('admin.staff-save', ['action' => 'set_active', 'user_id' => $old->id, 'active' => 1])->assertStatus(403);
        } finally {
            DB::table('email_outbox')->where('id', '>', $outboxBefore)->where('kind', 'super_admin_handover')->delete();
            DB::table('users')->where('id', $na->id)->update(['is_super_admin' => 0]);
            DB::table('users')->where('id', $old->id)->update(['is_super_admin' => 1, 'is_active' => 1, 'handed_over_at' => null,
                'password_hash' => $old->password_hash, 'reauth_failed_attempts' => 0]);
        }
    }

    /* A booking's news reaches that office's staff and the group's normal admins, never the super admin. */
    public function test_booking_notifications_go_to_the_office_and_its_group_admin(): void
    {
        $hostel = $this->staffAt(self::HOSTEL);
        $two = $this->staffAt(self::VENUES, true);
        $venues = $this->staffAt(self::VENUES);
        $bahay = $this->staffAt(self::BAHAY);
        $b = $this->bookingAt(self::HOSTEL);
        $before = (int) DB::table('notifications')->max('id');
        DB::statement('CALL sp_notify_venue_staff(?, ?, NULL, NULL)', [$b['id'], 'new_request']);
        $to = DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.user_id')->where('n.id', '>', $before)->pluck('u.email')->all();
        DB::table('notifications')->where('id', '>', $before)->delete();

        foreach ([$hostel->email, $two->email, 'cedu.admin@gmail.com'] as $yes) {
            $this->assertContains($yes, $to);
        }
        foreach ([$venues->email, $bahay->email, 'admin@gmail.com', 'francishubero@gmail.com'] as $no) {
            $this->assertNotContains($no, $to);
        }
    }

    public function test_two_office_staff_may_sign_in_on_either_cedu_card(): void
    {
        $two = $this->staffAt(self::VENUES, true);
        $this->post(route('admin.admin-login'), ['email' => $two->email, 'password' => self::PASSWORD, 'department' => self::HOSTEL])
            ->assertRedirect();
        $this->assertSame($two->id, (int) session('user_id'));
    }
}
