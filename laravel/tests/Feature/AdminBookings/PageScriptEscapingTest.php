<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* A customer chooses their own name and event name. Printed into a page's <script>
   as JSON, a "</script>" in them must not end the script and run in a staff session. */
class PageScriptEscapingTest extends TestCase
{
    use SeedsBookings;

    private const EVIL = '</script><script>alert(1)</script>';

    public function test_customer_text_cannot_break_out_of_the_staff_pages_scripts(): void
    {
        [, $ref] = $this->bookingRef(153);
        $customer = DB::table('bookings')->where('id', 153)->value('customer_id');
        $name = DB::table('customers')->where('id', $customer)->value('full_name');
        $event = DB::table('venue_booking_details')->where('booking_id', 153)->value('event_name');
        DB::table('customers')->where('id', $customer)->update(['full_name' => self::EVIL]);
        DB::table('venue_booking_details')->where('booking_id', 153)->update(['event_name' => self::EVIL]);
        try {
            $this->actingAsAdmin();
            foreach ([route('admin.booking-requests'), route('admin.booking-request', ['id' => $ref]),
                      route('admin.transaction-history'), route('admin.calendar')] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
            }
            $this->assertStringContainsString('</script>', $this->get(route('admin.booking-requests'))->getContent());
        } finally {
            DB::table('customers')->where('id', $customer)->update(['full_name' => $name]);
            DB::table('venue_booking_details')->where('booking_id', 153)->update(['event_name' => $event]);
        }
    }
}
