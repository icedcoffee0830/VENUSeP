<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\TestCase;

/* The 30-second check (public/assets/js/live.js): the version strings move only for what the
   viewer can see, and ?part=1 answers a page's parts alone. */
class LiveCheckTest extends TestCase
{
    use SeedsBookings;

    private int $lastBooking = 0;
    private int $lastNote = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastBooking = (int) DB::table('bookings')->max('id');
        $this->lastNote = (int) DB::table('notifications')->max('id');
    }

    protected function tearDown(): void
    {
        DB::table('notifications')->where('id', '>', $this->lastNote)->delete();
        DB::table('bookings')->where('id', '>', $this->lastBooking)->delete();
        parent::tearDown();
    }

    private function versions(string $page): array
    {
        return $this->getJson(route('root.live-check', ['page' => $page]))->assertOk()->json();
    }

    public function test_a_guest_gets_401(): void
    {
        $this->getJson(route('root.live-check'))->assertStatus(401)->assertJsonPath('ok', false);
    }

    public function test_the_staff_version_moves_for_their_own_office_only(): void
    {
        $this->actingAsAdmin('francishubero@gmail.com');   // Bahay Alumni's group: room r1 yes, the hostel no
        $before = $this->versions('queue')['page'];

        $this->seedHostelBooking();
        $this->assertSame($before, $this->versions('queue')['page'], 'a booking in another office moved it');

        $this->seedVenueBooking();
        $this->assertNotSame($before, $this->versions('queue')['page'], 'a new booking in their office did not move it');
    }

    public function test_a_timeline_row_moves_the_version(): void
    {
        [$id] = $this->seedVenueBooking();
        $this->actingAsAdmin();
        $before = $this->versions('queue')['page'];

        DB::table('booking_timeline')->insert(['booking_id' => $id, 'action_code' => 'test_live']);
        $this->assertNotSame($before, $this->versions('queue')['page']);
    }

    public function test_a_customer_sees_their_own_bookings_only(): void
    {
        $this->actingAsCustomer();
        $before = $this->versions('mine')['page'];
        $this->assertNotNull($before);
        $this->assertNull($this->versions('queue')['page'], 'a customer read the staff version');

        $this->seedVenueBooking();
        $this->assertNotSame($before, $this->versions('mine')['page']);
    }

    public function test_the_bell_version_moves_with_a_notification(): void
    {
        $this->actingAsCustomer();
        $before = $this->versions('mine')['bell'];
        $user = (int) DB::table('users')->where('email', 'customer@gmail.com')->value('id');

        DB::table('notifications')->insert(['user_id' => $user, 'kind' => 'test_live']);
        $this->assertNotSame($before, $this->versions('mine')['bell']);
    }

    public function test_part_answers_the_parts_alone(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.booking-requests', ['part' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('data-live="br-rows"', $html);
        $this->assertStringContainsString('data-live="hd-user"', $html);
        $this->assertStringNotContainsString('<html', $html);
    }
}
