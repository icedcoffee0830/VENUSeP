<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* A customer blocked at an office (Aron 2026-11-05, option A): that office is gone from their
   booking listings, past bookings stay in Booking History, a direct link still warns with the
   contact details, and a modal tells them once per session and on a direct link.
   The reason stays internal. Offices: 1 Bahay Alumni, 2 USeP Venues, 3 USeP Hostel. */
class OfficeBlockHiddenTest extends TestCase
{
    private const EMAIL = 'aronerfe0830@gmail.com';
    private const REASON = 'Secret reason: damaged the hall twice';
    private const MODAL = 'id="blockModal"';
    private int $customerId = 0;
    private array $bookingIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerId = (int) DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('u.email', self::EMAIL)->value('c.id');
    }

    protected function tearDown(): void
    {
        DB::table('customer_office_blocks')->where('customer_id', $this->customerId)->delete();
        DB::table('bookings')->whereIn('id', $this->bookingIds ?: [0])->delete();
        parent::tearDown();
    }

    private function block(int $venueId): void
    {
        $by = DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        DB::insert('INSERT INTO customer_office_blocks (customer_id, venue_id, reason, blocked_by_user_id) VALUES (?, ?, ?, ?)',
            [$this->customerId, $venueId, self::REASON, $by]);
    }

    private function landing()
    {
        return $this->get(route('customer.venues'))->assertOk();
    }

    public function test_the_landing_page_hides_a_blocked_venue_office_and_keeps_the_others(): void
    {
        $this->block(1);
        $this->actingAsCustomer(self::EMAIL);
        $this->landing()->assertDontSee('room-reservation?room=r1', false)->assertDontSee('id="venue-bahay-alumni"', false)
            ->assertDontSee('#venue-bahay-alumni', false)
            ->assertSee('room-reservation?room=r5', false)->assertSee('#hostel-listings', false);
    }

    public function test_the_landing_page_hides_a_blocked_hostel(): void
    {
        $this->block(3);
        $this->actingAsCustomer(self::EMAIL);
        $this->landing()->assertDontSee('hostel-reservation?room=h1', false)->assertDontSee('#hostel-listings', false)
            ->assertSee('room-reservation?room=r1', false);
    }

    /* Nothing left to list: the page still opens and the modal names all three. */
    public function test_blocked_at_every_office_the_landing_page_still_opens(): void
    {
        foreach ([1, 2, 3] as $office) {
            $this->block($office);
        }
        $this->actingAsCustomer(self::EMAIL);
        $this->landing()->assertSee("You can't book at Bahay Alumni right now.")->assertSee("You can't book at USeP Venues right now.")
            ->assertSee("You can't book at USeP Hostel right now.")->assertDontSee('room-reservation?room=', false)
            ->assertDontSee('hostel-reservation?room=', false);
    }

    public function test_more_rooms_on_another_offices_page_leaves_out_the_blocked_office(): void
    {
        $this->block(1);
        $this->actingAsCustomer(self::EMAIL);
        $this->get(route('customer.room-reservation', ['room' => 'r5']))->assertOk()
            ->assertDontSee('room-reservation?room=r1"', false)->assertDontSee('room-reservation?room=r2"', false);
    }

    public function test_a_direct_link_keeps_the_warning_the_contact_details_and_the_modal(): void
    {
        $this->block(2);
        $notice = "You can't book at USeP Venues right now. Please contact the office.";
        $this->actingAsCustomer(self::EMAIL);
        foreach ([1, 2] as $visit) {
            $page = $this->get(route('customer.room-reservation', ['room' => 'r5']))->assertOk();
            $page->assertSee($notice)->assertSee('usep.venues@example.com')->assertSee(self::MODAL, false)->assertDontSee('Secret reason');
        }
    }

    public function test_the_modal_shows_once_per_session_and_never_the_reason(): void
    {
        $this->block(2);
        $this->actingAsCustomer(self::EMAIL);
        $this->landing()->assertSee(self::MODAL, false)->assertSee("You can't book at USeP Venues right now. Please contact the office.")
            ->assertSee('usep.venues@example.com')->assertDontSee('Secret reason');
        $this->landing()->assertDontSee(self::MODAL, false);
    }

    public function test_a_customer_with_no_block_sees_every_office_and_no_modal(): void
    {
        $this->actingAsCustomer(self::EMAIL);
        $this->landing()->assertDontSee(self::MODAL, false)->assertSee('room-reservation?room=r1', false)
            ->assertSee('room-reservation?room=r5', false)->assertSee('hostel-reservation?room=h1', false);
    }

    public function test_past_bookings_at_the_blocked_office_stay_in_booking_history(): void
    {
        $room = DB::table('rooms')->where('room_code', 'r5')->value('id');
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => $this->customerId, 'room_id' => $room, 'booking_type' => 'venue',
            'reservation_status' => 'completed', 'payment_status' => 'confirmed', 'payment_method' => 'gcash',
            'is_usep_affiliated' => 0, 'affiliation_verified' => 0, 'room_price' => 1000, 'discount_percent' => 0,
            'discount_amount' => 0, 'total_amount' => 1000, 'refunds_allowed' => 0, 'submitted_at' => now()->subDays(30),
        ]);
        $this->bookingIds[] = $id;
        DB::table('venue_booking_details')->insert(['booking_id' => $id, 'event_name' => 'Past block test', 'start_date' => date('Y-m-d', strtotime('-20 days')),
            'end_date' => date('Y-m-d', strtotime('-20 days')), 'attendee_count' => 20]);
        $this->block(2);
        $this->actingAsCustomer(self::EMAIL)->get(route('customer.booking-history'))->assertOk()->assertSee('Past block test');
    }

    /* Staff at the counter book for anyone: the customer's block is theirs to see, not a filter. */
    public function test_the_counter_is_not_narrowed_by_a_customers_block(): void
    {
        $this->block(1);
        $this->actingAsAdmin()->get(route('customer.venues', ['counter' => 1]))->assertOk()
            ->assertSee('room-reservation?room=r1', false)->assertDontSee(self::MODAL, false);
    }
}
