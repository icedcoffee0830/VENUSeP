<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/booking-request — what the page draws from DATA (bugs found 2026-10-30). */
class BookingRequestFixesTest extends TestCase
{
    use SeedsBookings;

    private function page(string $ref)
    {
        return $this->actingAsAdmin()->get(route('admin.booking-request', ['id' => $ref]))->assertOk();
    }

    /* Bug 7: the time came from the date-only field, so every request read 12:00 AM. */
    public function test_submitted_shows_the_real_time(): void
    {
        [$id] = $this->seedVenueBooking('r1');
        DB::update("UPDATE bookings SET submitted_at = '2026-10-27 14:35:00' WHERE id = ?", [$id]);
        [, $ref] = $this->bookingRef($id);

        $this->page($ref)
            ->assertSee('"submitted":"Oct 27, 2026 · 2:35 PM"', false)
            ->assertSee('"w":"Oct 27 · 2:35 PM — Customer","x":"Booking submitted"', false);
    }
}
