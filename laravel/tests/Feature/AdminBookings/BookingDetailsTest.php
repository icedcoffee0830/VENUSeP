<?php

namespace Tests\Feature\AdminBookings;

use Tests\TestCase;

/* booking-details — the "View" modal's JSON, for staff AND customers. */
class BookingDetailsTest extends TestCase
{
    use SeedsBookings;

    public function test_guest_gets_a_404_json(): void
    {
        [, $ref] = $this->bookingRef(153);
        $this->get(route('root.booking-details', ['booking' => $ref]))
            ->assertNotFound()->assertExactJson(['ok' => false, 'message' => 'That booking was not found.']);
    }

    public function test_staff_see_any_booking_with_staff_labels(): void
    {
        [, $ref] = $this->bookingRef(154);   // venue 2 — staff see ANY booking here (read only)
        $this->actingAsStaff()->get(route('root.booking-details', ['booking' => $ref]))
            ->assertOk()->assertJson(['ok' => true, 'details' => ['reference' => $ref, 'type' => 'Venue booking']])
            ->assertJsonStructure(['details' => ['money' => ['total', 'paymentStatus'], 'when', 'timeline', 'customer']]);
    }

    public function test_a_customer_sees_their_own_booking(): void
    {
        [, $ref] = $this->bookingRef(153);   // customer@gmail.com's
        $this->actingAsCustomer()->get(route('root.booking-details', ['booking' => $ref]))
            ->assertOk()->assertJson(['ok' => true, 'details' => ['reference' => $ref]]);
    }

    public function test_a_customer_gets_404_for_someone_elses(): void
    {
        [, $ref] = $this->bookingRef(154);   // customer 2's
        $this->actingAsCustomer()->get(route('root.booking-details', ['booking' => $ref]))
            ->assertNotFound()->assertJson(['ok' => false]);
    }

    public function test_unknown_reference_is_404_for_staff(): void
    {
        $this->actingAsAdmin()->get(route('root.booking-details', ['booking' => 'VB-2026-999999']))->assertNotFound();
    }
}
