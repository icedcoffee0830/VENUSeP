<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/payment-settings.php, refund-switch.php, gcash-account-save.php. */
class PaymentSettingsTest extends TestCase
{
    use AdminTestHelpers;

    protected function tearDown(): void
    {
        $this->restoreAdminPassword();
        parent::tearDown();
    }

    public function test_guest_and_customer_are_sent_to_sign_in(): void
    {
        $this->get(route('admin.payment-settings'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsCustomer()->get(route('admin.payment-settings'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_admin_sees_the_switch_and_the_accounts(): void
    {
        $this->actingAsAdmin()->get(route('admin.payment-settings'))->assertOk()
            ->assertSee('Payment Settings')->assertSee('Refund Requests')
            ->assertSee('Turn refunds ON')->assertSee('id="rsModal"', false)
            ->assertSee('Mika J Juarez')->assertSee('0995 194 1234')->assertSee('Staff account')
            ->assertSee(json_encode(route('admin.refund-switch')), false)
            ->assertSee(json_encode(route('admin.gcash-account-save')), false)
            ->assertSee(asset('assets/js/confirm-modal.js'), false);
    }

    public function test_staff_sees_the_card_read_only(): void
    {
        $this->actingAsStaff()->get(route('admin.payment-settings'))->assertOk()
            ->assertSee('Only the administrator can change this')->assertDontSee('id="rsModal"', false);
    }

    /* The GCash save is admin-only on the server, so staff get no Edit button that
       could only ever end in "Only an administrator can change where a venue is paid". */
    public function test_staff_get_no_gcash_edit_button(): void
    {
        $page = $this->actingAsStaff()->get(route('admin.payment-settings'))->assertOk()->assertSee('Mika J Juarez');
        $this->assertDoesNotMatchRegularExpression('#<div class="ps-foot">\s*<button[^>]*psEdit#', $page->getContent());
    }

    /* A venue added in Venue Management has no GCash account; it must still get a
       card, or its account could never be set (gcash-account-save handles a first one). */
    public function test_a_venue_without_an_account_gets_a_card_and_can_be_set_up(): void
    {
        $name = 'Admina Test Hall ' . bin2hex(random_bytes(3));
        $venueId = DB::table('venues')->insertGetId(['venue_code' => strtoupper(str_replace(' ', '-', $name)),
            'name' => $name, 'venue_type' => 'event', 'description' => 'test', 'is_active' => 1]);
        $this->useKnownAdminPassword();
        try {
            $this->actingAsAdmin()->withTestCsrf()->get(route('admin.payment-settings'))->assertOk()
                ->assertSee('data-venue="' . $name . '"', false)
                ->assertSee('No GCash account yet: customers cannot pay this venue by GCash until one is set.');

            $this->post(route('admin.gcash-account-save'), ['_token' => 'test-csrf-token', 'venue' => $name,
                'account_name' => 'Test Payee', 'mobile_number' => '0917 000 1111', 'password' => $this->pw])
                ->assertOk()->assertJson(['ok' => true, 'number' => '09170001111', 'previous' => null]);
            $this->assertSame(1, DB::table('gcash_accounts')->where('venue_id', $venueId)->where('is_active', 1)->whereNull('valid_until')->count());
        } finally {
            DB::table('gcash_accounts')->where('venue_id', $venueId)->delete();
            DB::table('venues')->where('id', $venueId)->delete();
        }
    }

    /* The hostel's card (a staff GCash account) is picked by venue_type, not by the
       name "USeP Hostel": a renamed hostel keeps it, and only the hostel has it. */
    public function test_the_hostel_card_follows_venue_type_not_its_name(): void
    {
        DB::beginTransaction();
        try {
            DB::table('venues')->where('venue_type', 'hostel')->update(['name' => 'Campus Dormitory']);
            $html = $this->actingAsAdmin()->get(route('admin.payment-settings'))->assertOk()
                ->assertSeeInOrder(['<h2>Campus Dormitory</h2>', 'Staff account'], false)->getContent();
            $this->assertSame(1, substr_count($html, '<span class="ps-kind">Staff account</span>'));
        } finally {
            DB::rollBack();
        }
    }

    public function test_admin_gets_the_gcash_edit_buttons(): void
    {
        $this->actingAsAdmin()->get(route('admin.payment-settings'))->assertOk()
            ->assertSeeInOrder(['<div class="ps-foot">', '<button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>'], false);
    }

    public function test_saved_flash(): void
    {
        $this->actingAsAdmin()->get(route('admin.payment-settings', ['refunds' => 'saved']))->assertOk()
            ->assertSee('Saved. Refund requests are now OFF.');
    }

    /* ---- refund switch ---- */

    public function test_refund_switch_guards(): void
    {
        $this->get(route('admin.refund-switch'))->assertStatus(405)->assertHeader('Allow', 'POST')
            ->assertJson(['ok' => false, 'error' => 'method']);
        $this->post(route('admin.refund-switch'))->assertStatus(401)->assertJson(['error' => 'not_logged_in']);
        $this->actingAsStaff()->withTestCsrf()->post(route('admin.refund-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => 'x'])
            ->assertStatus(403)->assertJson(['error' => 'not_admin', 'message' => 'Only the administrator can change the refund setting.']);
    }

    public function test_refund_switch_csrf_and_validation(): void
    {
        $this->actingAsAdmin()->withTestCsrf()->post(route('admin.refund-switch'), ['_token' => 'wrong', 'enable' => '1', 'password' => 'x'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
        $this->post(route('admin.refund-switch'), ['_token' => 'test-csrf-token', 'enable' => '2', 'password' => 'x'])
            ->assertStatus(400)->assertJson(['error' => 'bad_value']);
        $this->post(route('admin.refund-switch'), ['_token' => 'test-csrf-token', 'enable' => '1'])
            ->assertStatus(400)->assertJson(['error' => 'no_password']);
    }

    public function test_refund_switch_wrong_password_ladder_then_lock(): void
    {
        $this->useKnownAdminPassword();
        $this->actingAsAdmin()->withTestCsrf();
        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('admin.refund-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => 'nope'])
                ->assertStatus(401)->assertJson(['error' => 'wrong_password', 'attemptsLeft' => 5 - $i]);
        }
        $this->post(route('admin.refund-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => 'nope'])
            ->assertStatus(423)->assertJson(['error' => 'locked', 'seconds' => 10, 'justLocked' => true]);
        $this->post(route('admin.refund-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => $this->pw])
            ->assertStatus(423)->assertJson(['error' => 'locked']);
        $this->assertSame(1, (int) DB::table('users')->where('email', 'admin@gmail.com')->value('reauth_lock_level'));
    }

    public function test_refund_switch_turns_on_and_writes_history(): void
    {
        $this->useKnownAdminPassword();
        $before = DB::table('system_settings_history')->where('setting_key', 'refunds_enabled')->count();
        $this->actingAsAdmin()->withTestCsrf()
            ->post(route('admin.refund-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => $this->pw])
            ->assertOk()->assertExactJson(['ok' => true, 'enabled' => true, 'changed' => true]);
        $this->assertSame('1', DB::table('system_settings')->where('setting_key', 'refunds_enabled')->value('setting_value'));
        $this->assertSame($before + 1, DB::table('system_settings_history')->where('setting_key', 'refunds_enabled')->count());

        $this->post(route('admin.refund-switch'), ['_token' => 'test-csrf-token', 'enable' => '0', 'password' => $this->pw])
            ->assertOk()->assertJson(['enabled' => false, 'changed' => true]);
        $this->assertSame('0', DB::table('system_settings')->where('setting_key', 'refunds_enabled')->value('setting_value'));
    }

    /* ---- GCash account ---- */

    public function test_gcash_save_refuses_staff_and_bad_numbers(): void
    {
        $this->actingAsStaff()->withTestCsrf()->post(route('admin.gcash-account-save'), ['_token' => 'test-csrf-token'])
            ->assertStatus(403)->assertJson(['error' => 'not_admin']);
        $this->flushSession();
        $this->actingAsAdmin()->withTestCsrf()->post(route('admin.gcash-account-save'), [
            '_token' => 'test-csrf-token', 'venue' => 'Bahay Alumni', 'account_name' => 'X', 'mobile_number' => '0812', 'password' => 'x',
        ])->assertStatus(400)->assertJson(['field' => 'mobile_number']);
    }

    public function test_gcash_save_supersedes_the_old_row(): void
    {
        $this->useKnownAdminPassword();
        $old = DB::table('gcash_accounts')->where('venue_id', 1)->where('is_active', 1)->first();
        $this->actingAsAdmin()->withTestCsrf()->post(route('admin.gcash-account-save'), [
            '_token' => 'test-csrf-token', 'venue' => 'Bahay Alumni', 'account_name' => 'Test Payee',
            'mobile_number' => '0917 000 1111', 'password' => $this->pw,
        ])->assertOk()->assertJson(['ok' => true, 'number' => '09170001111', 'previous' => $old->mobile_number]);

        $now = DB::table('gcash_accounts')->where('venue_id', 1)->where('is_active', 1)->whereNull('valid_until')->first();
        $this->assertSame('Test Payee', $now->account_name);
        $this->assertNotNull(DB::table('gcash_accounts')->where('id', $old->id)->value('valid_until'));

        // put the seeded account back (same endpoint: supersede again)
        $this->post(route('admin.gcash-account-save'), [
            '_token' => 'test-csrf-token', 'venue' => 'Bahay Alumni', 'account_name' => $old->account_name,
            'mobile_number' => $old->mobile_number, 'note' => $old->note, 'password' => $this->pw,
        ])->assertOk();
    }
}
