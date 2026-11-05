<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* The top-right user chip shows the photo chosen on the profile page, else the initials
   (staff/admin header, the customer account header, the customer browse bar). */
class HeaderPhotoTest extends TestCase
{
    public function test_staff_chip_shows_the_photo_else_the_initials(): void
    {
        $uid = (int) DB::table('users')->where('email', 'staff@gmail.com')->value('id');
        $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk()
            ->assertSee('<span class="hd-avatar" aria-hidden="true">MR</span>', false);
        DB::table('staff')->where('user_id', $uid)->update(['photo_path' => 'assets/img/avatars/s7-test.png']);
        try {
            $this->get(route('admin.dashboard'))->assertOk()
                ->assertSee('<img class="hd-avatar" src="' . asset('assets/img/avatars/s7-test.png') . '"', false)
                ->assertDontSee('<span class="hd-avatar" aria-hidden="true">MR</span>', false);
        } finally {
            DB::table('staff')->where('user_id', $uid)->update(['photo_path' => null]);
        }
    }

    public function test_customer_chips_show_the_photo(): void
    {
        $old = DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('u.email', 'customer@gmail.com')->value('c.photo_path');
        DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('u.email', 'customer@gmail.com')
            ->update(['c.photo_path' => 'assets/img/avatars/c-test.png']);
        try {
            $src = asset('assets/img/avatars/c-test.png');
            $this->actingAsCustomer()->get(route('customer.booking-history'))->assertOk()
                ->assertSee('<img class="hd-avatar" src="' . $src . '"', false);
            $this->get(route('customer.venues'))->assertOk()
                ->assertSee('<img class="cn-avatar" src="' . $src . '"', false);
        } finally {
            DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('u.email', 'customer@gmail.com')
                ->update(['c.photo_path' => $old]);
        }
    }
}
