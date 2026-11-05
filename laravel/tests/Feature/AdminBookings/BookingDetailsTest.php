<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
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

    public function test_staff_see_their_own_venues_booking_with_staff_labels(): void
    {
        [, $ref] = $this->bookingRef(153);   // venue 1 = staff@gmail.com's
        $this->actingAsStaff()->get(route('root.booking-details', ['booking' => $ref]))
            ->assertOk()->assertJson(['ok' => true, 'details' => ['reference' => $ref, 'type' => 'Venue booking']])
            ->assertJsonStructure(['details' => ['money' => ['total', 'paymentStatus'], 'when', 'timeline', 'customer']]);
    }

    public function test_staff_get_404_for_another_venues_booking_and_admins_see_it(): void
    {
        [, $ref] = $this->bookingRef(154);   // venue 2
        $this->actingAsStaff()->get(route('root.booking-details', ['booking' => $ref]))
            ->assertNotFound()->assertJson(['ok' => false]);
        $this->flushSession();
        $this->actingAsAdmin()->get(route('root.booking-details', ['booking' => $ref]))
            ->assertOk()->assertJson(['ok' => true, 'details' => ['reference' => $ref]]);
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

    /* The modal's payment step for each [reservation, payment] pair, on the customer's own
       bookings. All seeded first: the customer's list is read once per test. */
    private function paySteps(array ...$states): array
    {
        $refs = array_map(fn ($s) => $this->seedVenueBooking('r1', $s[0], $s[1])[1], $states);
        return array_map(fn ($ref) => $this->actingAsCustomer()->get(route('root.booking-details', ['booking' => $ref]))->assertOk()->json('details'), $refs);
    }

    public function test_an_open_payment_offers_pay_now_with_the_discounted_total(): void
    {
        [$gcash, $gcashRef] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        [, $cashRef] = $this->seedVenueBooking('r1', 'approved', 'await_cash');
        DB::update('UPDATE bookings SET discount_amount = 250.50, total_amount = total_amount - 250.50 WHERE id = ?', [$gcash]);
        $total = (float) DB::table('bookings')->where('id', $gcash)->value('total_amount');
        $this->actingAsCustomer()->get(route('root.booking-details', ['booking' => $gcashRef]))->assertOk()
            ->assertJsonPath('details.pay', ['label' => 'Pay now · ₱' . number_format($total, 2), 'url' => route('customer.pay', ['booking' => $gcashRef]), 'primary' => true]);
        $this->get(route('root.booking-details', ['booking' => $cashRef]))->assertOk()
            ->assertJsonPath('details.pay.url', route('customer.pay', ['booking' => $cashRef]));
    }

    public function test_a_booking_that_cannot_be_paid_says_why_with_no_button(): void
    {
        [$waiting, $overdue, $pos] = $this->paySteps(['pending', 'locked'], ['approved', 'overdue'], ['approved', 'await_pos']);
        $this->assertSame(['note' => 'Waiting for approval. You can pay once the office approves this booking.'], $waiting['pay']);
        $this->assertSame(['note' => 'Payment overdue. Please contact the office.'], $overdue['pay']);
        $this->assertSame(['note' => 'Waiting for your POS. You can pay once it is ready.'], $pos['pay']);
    }

    public function test_pay_after_the_event_gives_the_date_and_no_button(): void
    {
        [$d] = $this->paySteps(['approved', 'await_event']);
        $this->assertNotSame('', $d['money']['payBy']);
        $this->assertSame(['note' => 'You can pay after your event, by ' . $d['money']['payBy']], $d['pay']);
    }

    public function test_a_receipt_under_review_offers_view_receipt_instead_of_pay_now(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'under_review');
        $receipt = DB::table('gcash_receipts')->insertGetId(['booking_id' => $id, 'attempt_no' => 1, 'file_path' => 'receipts/ps-' . $id . '.png',
            'reference_number' => 'PS' . $id, 'sha256_hash' => hash('sha256', 'ps' . $id), 'verdict' => 'manual_review']);
        $this->actingAsCustomer()->get(route('root.booking-details', ['booking' => $ref]))->assertOk()
            ->assertJsonPath('details.pay', ['note' => 'Receipt sent · waiting for staff', 'label' => 'View receipt',
                'url' => route('root.valid-id-view', ['receipt' => $receipt])]);
    }

    public function test_finished_bookings_and_staff_get_no_payment_step(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        [$paid, $rejected] = $this->paySteps(['approved', 'confirmed'], ['rejected', 'locked']);
        $this->assertNull($paid['pay']);
        $this->assertNull($rejected['pay']);
        $this->flushSession();
        $this->actingAsStaff()->get(route('root.booking-details', ['booking' => $ref]))->assertOk()->assertJsonPath('details.pay', null);
    }
}
