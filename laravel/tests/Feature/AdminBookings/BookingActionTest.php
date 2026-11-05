<?php

namespace Tests\Feature\AdminBookings;

use App\Queries\BookingQueries;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/booking-action — the staff action endpoint (two-axis status). */
class BookingActionTest extends TestCase
{
    use SeedsBookings;

    private function act(array $data)
    {
        return $this->postWithToken(route('admin.booking-action'), $data);
    }

    public function test_get_is_refused_with_405_json(): void
    {
        $this->get(route('admin.booking-action'))->assertStatus(405)->assertHeader('Allow', 'POST')
            ->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
    }

    public function test_guest_gets_401_json_not_a_redirect(): void
    {
        $this->post(route('admin.booking-action'), ['booking' => 'VB-2026-190', 'action' => 'approve'])
            ->assertStatus(401)->assertExactJson(['ok' => false, 'message' => 'Your session has ended. Log in again.']);
    }

    public function test_customer_gets_401_json(): void
    {
        $this->actingAsCustomer()->post(route('admin.booking-action'), ['booking' => 'VB-2026-190', 'action' => 'approve'])
            ->assertStatus(401);
    }

    public function test_staff_cannot_act_on_another_venues_booking(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r5');   // venue 2
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'approve'])
            ->assertStatus(403)->assertJson(['ok' => false,
                'message' => 'This booking belongs to another venue. Only that venue\'s staff or an administrator can act on it.']);
        $this->assertSame('pending', $this->booking($id)->reservation_status);
    }

    public function test_admin_approves_with_the_usep_rate_snapshotted(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r5', 'pending', 'locked', 'gcash', true, true);
        $this->actingAsAdmin()->act(['booking' => $ref, 'action' => 'approve', 'discount' => '1'])
            ->assertOk()->assertJson(['ok' => true, 'message' => 'Saved.',
                'booking' => ['res' => 'approved', 'pay' => 'await_gcash']]);
        $b = $this->booking($id);
        $this->assertSame('approved', $b->reservation_status);
        $this->assertSame('await_gcash', $b->payment_status);
        $this->assertEquals(20, $b->discount_percent);
        $this->assertEquals(round($b->room_price * 0.8, 2), (float) $b->total_amount);
        $this->assertEquals(1, $b->affiliation_verified);
        // the timeline trigger learned who acted and why
        $adminId = DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        $this->assertGreaterThan(0, DB::table('booking_timeline')->where('booking_id', $id)
            ->where('performed_by_user_id', $adminId)->where('note', 'Approved; pay before the event.')->count());
    }

    /* The discounted final price rounds UP to the whole peso and the discount is the rest
       (Aron 2026-11-05, migration 18's sp_approve_booking): 1,542.75 less 20% = 1,234.20 -> 1,235.00.
       Before approval the customer sees the full price and a note; after it, the discounted one. */
    public function test_the_discounted_price_rounds_up_to_the_peso_at_approval_and_is_shown(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, true);
        DB::table('bookings')->where('id', $id)->update(['room_price' => 1542.75, 'total_amount' => 1542.75]);
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'approve', 'discount' => '1'])->assertOk();
        $b = $this->booking($id);
        $this->assertSame(['1542.75', '307.75', '1235.00'], [$b->room_price, $b->discount_amount, $b->total_amount]);
        $this->assertEquals(20, $b->discount_percent);
        $this->flushSession();

        $this->actingAsCustomer()->get(route('customer.booking-history'))->assertOk()
            ->assertSee('<td class="booking-amount" data-label="Amount">₱1,235</td>', false);
        $this->getJson(route('root.booking-details', ['booking' => $ref]))->assertOk()
            ->assertJsonPath('details.money.total', '₱1,235.00')->assertJsonPath('details.money.discount', '−₱307.75')
            ->assertJsonPath('details.money.discountNote', '');
    }

    /* Migration 19 (Aron 2026-11-05, option B): rounding up never cancels the discount
       (then it rounds down), and a booking is never free (then the full price, no discount).
       The page previews (usep_discounted, priceWithDiscount) use the same rule. */
    public function test_rounding_never_cancels_the_discount_and_never_makes_a_booking_free(): void
    {
        ob_start();
        legacy('pricing');   /* ends with a <script> block for the booking pages */
        ob_end_clean();
        $staff = (int) DB::table('users')->where('email', 'staff@gmail.com')->value('id');
        foreach ([[1, '1.00', '0.00'], [3, '2.00', '1.00'], [1999, '1600.00', '399.00'], [4000, '3200.00', '800.00']] as [$price, $total, $off]) {
            [$id] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, true);
            DB::table('bookings')->where('id', $id)->update(['room_price' => $price, 'total_amount' => $price]);
            BookingQueries::approve($id, true, 20, round($price * 20 / 100, 2), $staff, 'test');
            $b = $this->booking($id);
            $this->assertSame([$total, $off, 'approved'], [$b->total_amount, $b->discount_amount, $b->reservation_status], "₱$price");
            $this->assertEquals((float) $total, usep_discounted($price, 20), "preview of ₱$price");
        }
        $this->assertSame('₱1 per day', usep_price_html(1, true, ' per day', 20));   /* no discount left: no crossed-out price */
    }

    /* (legacy customer-bookings.php reads the list once per process, so this is its own test) */
    public function test_before_approval_the_customer_sees_the_full_price_and_the_discount_note(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, true);
        DB::table('bookings')->where('id', $id)->update(['room_price' => 1542.75, 'total_amount' => 1542.75]);
        $this->actingAsCustomer()->get(route('customer.booking-history'))->assertOk()
            ->assertSee('₱1,542.75<span class="booking-amount-note">USeP discount applied once your ID is verified</span>', false);
        $this->getJson(route('root.booking-details', ['booking' => $ref]))->assertOk()
            ->assertJsonPath('details.money.total', '₱1,542.75')
            ->assertJsonPath('details.money.discountNote', 'USeP discount applied once your ID is verified');
    }

    /* No discount: nothing is rounded, the price stays as it is. Every approval, the
       counter's included, goes through sp_approve_booking. */
    public function test_an_approval_without_the_discount_rounds_nothing_and_the_procedure_rounds_a_counter_total(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, true);
        DB::table('bookings')->where('id', $id)->update(['room_price' => 1542.75, 'total_amount' => 1542.75]);
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'approve'])->assertOk();
        $b = $this->booking($id);
        $this->assertSame(['1542.75', '0.00', '1542.75'], [$b->room_price, $b->discount_amount, $b->total_amount]);

        [$id2] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, true);
        DB::table('bookings')->where('id', $id2)->update(['room_price' => 1542.75, 'discount_percent' => 20, 'discount_amount' => 308.55, 'total_amount' => 1234.20]);
        DB::statement('CALL sp_approve_booking(?, ?)', [$id2, (int) DB::table('users')->where('email', 'staff@gmail.com')->value('id')]);
        $b2 = $this->booking($id2);
        $this->assertSame(['307.75', '1235.00', 'approved'], [$b2->discount_amount, $b2->total_amount, $b2->reservation_status]);
    }

    public function test_staff_approves_their_own_venues_booking_post_pay(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', false, false);
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'approve'])
            ->assertOk()->assertJson(['ok' => true, 'booking' => ['res' => 'approved', 'pay' => 'await_event']]);
    }

    public function test_discount_without_an_affiliation_claim_is_refused(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', false);
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'approve', 'discount' => '1'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'This booking did not claim USeP affiliation.']);
        $this->assertSame('pending', $this->booking($id)->reservation_status);
    }

    public function test_approving_twice_is_a_409(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'approve'])
            ->assertStatus(409)->assertJson(['message' => 'Only a pending booking can be approved.']);
    }

    public function test_reject_payment_needs_a_reason(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'approved', 'under_review');
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'reject_payment', 'note' => '  '])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'A reason is required — the customer reads it.']);
    }

    public function test_confirm_cash_payment_writes_the_payment_and_the_system_receipt(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_cash', 'cash');
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'confirm_payment'])
            ->assertOk()->assertJson(['ok' => true, 'booking' => ['pay' => 'paid_cash']]);
        $this->assertSame('paid_cash', $this->booking($id)->payment_status);
        $this->assertSame(1, DB::table('payments')->where('booking_id', $id)->count());
        $this->assertSame(1, DB::table('system_receipts')->where('booking_id', $id)->count());
    }

    public function test_confirm_with_no_gcash_receipt_in_is_refused_and_redraws(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'confirm_payment'])
            ->assertStatus(409)->assertJson(['ok' => false, 'booking' => ['pay' => 'await_gcash']]);
    }

    public function test_reject_closes_the_request(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1');
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'reject', 'note' => 'Room in use'])
            ->assertOk()->assertJson(['booking' => ['res' => 'rejected', 'pay' => 'locked']]);
        $this->assertSame('Room in use', $this->booking($id)->staff_notes);
    }

    public function test_hostel_pos_is_recorded_pay_to_use_stays_put(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'await_pos');
        $this->actingAsAdmin()->act(['booking' => $ref, 'action' => 'record_pos', 'pos' => ''])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Enter the POS number.']);
        $this->act(['booking' => $ref, 'action' => 'record_pos', 'pos' => 'POS-77'])
            ->assertOk()->assertJson(['booking' => ['pay' => 'await_pos']]);   // every new hostel stay is pay-to-use: the POS is only recorded
        $this->assertSame('POS-77', DB::table('hostel_booking_details')->where('booking_id', $id)->value('pos_number'));
    }

    public function test_unknown_action_and_bad_reference(): void
    {
        [, $ref] = $this->seedVenueBooking('r1');
        $this->actingAsAdmin()->act(['booking' => $ref, 'action' => 'frobnicate'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Unknown action.']);
        $this->act(['booking' => 'nope', 'action' => 'approve'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Invalid booking reference.']);
    }
}
