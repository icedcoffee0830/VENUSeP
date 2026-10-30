<?php

namespace Tests\Feature\AdminBookings;

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

    public function test_demo_mode_writes_nothing(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1');
        DB::update("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'demo_mode'");
        try {
            $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'approve'])
                ->assertOk()->assertJson(['ok' => true, 'demo' => true, 'message' => 'Saved (demo mode — nothing was written).',
                    'booking' => ['res' => 'approved']]);
        } finally {
            DB::update("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'demo_mode'");
        }
        $this->assertSame('pending', $this->booking($id)->reservation_status);
    }
}
