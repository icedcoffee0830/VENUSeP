<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* notifications.php -> route root.notifications (the bell's JSON endpoint). */
class NotificationsTest extends TestCase
{
    public function test_guest_gets_401_json(): void
    {
        $this->getJson(route('root.notifications'))->assertStatus(401)
            ->assertExactJson(['ok' => false, 'message' => 'Not signed in.']);
    }

    public function test_customer_gets_own_list_without_reminders(): void
    {
        $this->actingAsCustomer()->get(route('root.notifications'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['ok' => true, 'reminders' => []])
            ->assertJsonStructure(['ok', 'unread', 'items', 'reminders']);
    }

    public function test_staff_and_admin_get_list_and_reminders(): void
    {
        $this->actingAsStaff()->get(route('root.notifications'))->assertOk()
            ->assertJsonStructure(['ok', 'unread', 'items', 'reminders']);
    }

    public function test_admin_gets_list(): void
    {
        $this->actingAsAdmin()->get(route('root.notifications'))->assertOk()->assertJson(['ok' => true]);
    }

    public function test_mark_one_and_all_read(): void
    {
        $uid = (int) DB::table('users')->where('email', 'customer@gmail.com')->value('id');
        $id = DB::table('notifications')->insertGetId(['user_id' => $uid, 'kind' => 'test', 'detail' => '{}']);

        $this->actingAsCustomer()->post(route('root.notifications'), ['action' => 'read', 'id' => $id])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));

        DB::table('notifications')->where('id', $id)->update(['read_at' => null]);
        $this->post(route('root.notifications'), ['action' => 'read_all'])->assertOk()->assertJson(['ok' => true, 'unread' => 0]);
        $this->assertSame(0, DB::table('notifications')->where('user_id', $uid)->whereNull('read_at')->count());
        DB::table('notifications')->where('id', $id)->delete();
    }

    public function test_unknown_action_is_400(): void
    {
        $this->actingAsCustomer()->post(route('root.notifications'), ['action' => 'nope'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Unknown action.']);
    }
}
