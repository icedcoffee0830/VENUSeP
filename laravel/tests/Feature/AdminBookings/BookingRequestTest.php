<?php

namespace Tests\Feature\AdminBookings;

use Tests\TestCase;

/* admin/booking-request — the request detail, drawn by its script from DATA. */
class BookingRequestTest extends TestCase
{
    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.booking-request', ['id' => 'VB-2026-190']))->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_is_sent_to_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.booking-request', ['id' => 'VB-2026-190']))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_gets_the_page_with_data_and_new_urls(): void
    {
        $res = $this->actingAsStaff()->get(route('admin.booking-request', ['id' => 'VB-2026-190']));
        $res->assertOk()
            ->assertSee('<title>VENUSeP | Booking Request Detail</title>', false)
            ->assertSee('"VB-2026-190":{"id":"VB-2026-190"', false)
            ->assertSee('const DISCOUNT_PERCENT = 20;', false)          // pricing.php's script
            ->assertSee('function gcStore', false)                     // the GCash engine
            ->assertSee('fetch(' . json_encode(route('admin.booking-action')), false)
            ->assertSee('_token: CSRF', false)
            ->assertSee(asset('assets/js/confirm-modal.js'), false)
            ->assertDontSee('.php\'', false);
    }

    public function test_admin_gets_the_page(): void
    {
        $this->actingAsAdmin()->get(route('admin.booking-request', ['id' => 'VB-2026-033']))->assertOk()
            ->assertSee('"VB-2026-033":{', false);
    }
}
