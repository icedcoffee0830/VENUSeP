<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Aron, 2026-11-01: force cancel, write-off, reverse payment, outside refunds, check-out,
   the extension limit and the exact-amount rule. */
class SettlementActionsTest extends TestCase
{
    use SeedsBookings;
    use \Tests\Feature\AdminSettings\AdminTestHelpers;

    protected function tearDown(): void
    {
        $this->restoreAdminPassword();
        parent::tearDown();
    }

    private function act(array $data)
    {
        return $this->postWithToken(route('admin.booking-action'), $data);
    }

    private function cancel(string $ref)
    {
        return $this->act(['booking' => $ref, 'action' => 'force_cancel', 'reason' => 'fake', 'note' => 'Nobody answers this number']);
    }

    /* ---- force cancel ---- */

    public function test_force_cancel_frees_the_date_and_tells_the_customer_and_admins(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'force_cancel'])->assertStatus(400);
        $this->cancel($ref)->assertOk();
        $b = $this->booking($id);
        $this->assertSame('cancelled', $b->reservation_status);
        $this->assertNotNull(DB::table('venue_booking_slots')->where('booking_id', $id)->value('released_at'));
        $this->assertGreaterThanOrEqual(2, DB::table('notifications')->where('booking_id', $id)->where('kind', 'booking_cancelled')->count());
    }

    public function test_force_cancel_refuses_paid_reviewing_pending_started_and_other_offices(): void
    {
        $this->actingAsStaff();
        [, $paid] = $this->seedVenueBooking('r1', 'approved', 'confirmed');
        $this->cancel($paid)->assertStatus(409);
        [, $review] = $this->seedVenueBooking('r1', 'approved', 'under_review');
        $this->cancel($review)->assertStatus(409)->assertJson(['message' => 'A receipt is waiting for review. They may really have paid: check it and reject it first.']);
        [, $pending] = $this->seedVenueBooking('r1', 'pending', 'locked');
        $this->cancel($pending)->assertStatus(409);
        [$oId, $overdue] = $this->seedVenueBooking('r1', 'approved', 'overdue');
        $this->cancel($overdue)->assertStatus(409);
        [$sId, $started] = $this->seedVenueBooking('r1', 'approved', 'await_event');
        DB::table('venue_booking_slots')->where('booking_id', $sId)->update(['slot_date' => now()->subDay()->toDateString()]);
        $this->cancel($started)->assertStatus(409)->assertJson(['message' => 'The event has already started, so it cannot be force cancelled.']);
        [, $other] = $this->seedVenueBooking('r5', 'approved', 'await_gcash');
        $this->cancel($other)->assertStatus(403);
        $this->assertSame('overdue', $this->booking($oId)->payment_status);
    }

    /* ---- write-off ---- */

    public function test_staff_request_a_write_off_but_cannot_decide_it(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'overdue');
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'write_off_request'])->assertStatus(400);
        $this->act(['booking' => $ref, 'action' => 'write_off_request', 'note' => 'The organizer left the school'])->assertOk();
        $this->assertSame('The organizer left the school', $this->booking($id)->write_off_reason);
        $this->act(['booking' => $ref, 'action' => 'write_off_approve'])->assertStatus(403);
        $this->assertSame('overdue', $this->booking($id)->payment_status);
    }

    public function test_an_admin_approves_a_write_off_and_the_block_ends(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'overdue');
        DB::table('bookings')->where('id', $id)->update(['write_off_reason' => 'Gone']);
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'write_off_approve'])->assertOk();
        $b = $this->booking($id);
        $this->assertSame(['completed', 'written_off'], [$b->reservation_status, $b->payment_status]);
    }

    public function test_an_admin_declines_a_write_off(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'overdue');
        DB::table('bookings')->where('id', $id)->update(['write_off_reason' => 'Gone']);
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'write_off_decline'])->assertOk();
        $b = $this->booking($id);
        $this->assertNull($b->write_off_reason);
        $this->assertSame('overdue', $b->payment_status);
    }

    /* ---- reverse payment ---- */

    private function paidWithReceipt(): array
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'confirmed');
        DB::table('payments')->insert(['booking_id' => $id, 'payment_method' => 'gcash', 'amount' => 100,
            'payment_record_status' => 'confirmed', 'paid_at' => now(), 'confirmed_at' => now()]);
        legacy('bookings', 'system-receipt');
        receipt_issue($id, (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id'));
        return [$id, $ref];
    }

    public function test_staff_cannot_reverse_a_payment(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'approved', 'confirmed');
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'reverse_payment', 'reason' => 'fake', 'note' => 'A long enough note', 'password' => 'x'])->assertStatus(403);
    }

    public function test_reversing_needs_a_reason_and_the_password_then_voids_the_receipt(): void
    {
        [$id, $ref] = $this->paidWithReceipt();
        $this->useKnownAdminPassword();
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'reverse_payment', 'reason' => 'fake', 'note' => 'short', 'password' => 'x'])->assertStatus(400);
        $this->act(['booking' => $ref, 'action' => 'reverse_payment', 'reason' => 'fake', 'note' => 'No money in the app', 'password' => ''])->assertStatus(400);
        $this->act(['booking' => $ref, 'action' => 'reverse_payment', 'reason' => 'fake', 'note' => 'No money in the app', 'password' => 'wrong'])->assertStatus(401);
        $this->assertSame('confirmed', $this->booking($id)->payment_status);

        $this->act(['booking' => $ref, 'action' => 'reverse_payment', 'reason' => 'fake', 'note' => 'No money in the app', 'password' => $this->pw])->assertOk();
        $this->assertSame('await_gcash', $this->booking($id)->payment_status);
        $this->assertNotNull(DB::table('system_receipts')->where('booking_id', $id)->value('voided_at'));
        $this->assertSame('rejected', DB::table('payments')->where('booking_id', $id)->value('payment_record_status'));
    }

    /* ---- outside refund ---- */

    public function test_staff_record_an_outside_refund_with_proof(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'paid_cash', 'cash', false, false);
        $data = ['booking' => $ref, 'action' => 'refund_outside', 'reason' => 'closure', 'note' => 'The hostel flooded',
                 'cashier_reference' => 'CR-1', 'refunded_on' => date('Y-m-d')];
        $this->actingAsStaff();
        $this->act($data)->assertStatus(400)->assertJson(['message' => "Attach the proof from the Cashier's record."]);
        $this->act(['refunded_on' => date('Y-m-d', strtotime('+1 day'))] + $data)->assertStatus(400);
        $this->assertSame('paid_cash', $this->booking($id)->payment_status);

        $proof = UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4 cashier');
        try {
            $this->act(['proof' => [$proof]] + $data)->assertOk();
            $b = $this->booking($id);
            $this->assertSame(['cancelled', 'refunded'], [$b->reservation_status, $b->payment_status]);
            $r = DB::table('refunds')->where('booking_id', $id)->first();
            $this->assertSame(1, (int) $r->outside_system);
            $this->assertSame('CR-1', $r->cashier_reference);
            $this->assertSame('completed', $r->refund_status);
        } finally {
            $dir = getenv('VENUSEP_DOC_ROOT') . '/bookings/' . $id;
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    /* ---- check-out ---- */

    public function test_check_out_frees_the_remaining_nights(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'paid_cash', 'cash', false, true);
        $this->actingAsStaff('jakemerson@gmail.com');
        $this->act(['booking' => $ref, 'action' => 'check_out'])->assertStatus(409);
        DB::table('hostel_booking_details')->where('booking_id', $id)->update(['checked_in' => 1]);
        $this->act(['booking' => $ref, 'action' => 'check_out'])->assertOk();
        $this->assertSame(1, (int) DB::table('hostel_booking_details')->where('booking_id', $id)->value('checked_out'));
        $this->act(['booking' => $ref, 'action' => 'check_out'])->assertStatus(409);
    }

    /* A guest who checked in and never paid stays a visible debt, not a released booking. */
    public function test_an_unpaid_checked_in_stay_is_never_released(): void
    {
        [$id] = $this->seedHostelBooking('approved', 'await_gcash', 'gcash', false, true);
        DB::table('hostel_booking_details')->where('booking_id', $id)->update(['checked_in' => 1, 'checked_out' => 1]);
        DB::table('bookings')->where('id', $id)->update(['current_deadline_at' => now()->subDay()]);
        DB::statement('CALL sp_expire_due_bookings()');
        $this->assertSame('approved', $this->booking($id)->reservation_status);
    }

    /* ---- limits ---- */

    public function test_staff_extend_five_times_then_only_an_admin(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'overdue');
        DB::table('bookings')->where('id', $id)->update(['deadline_extensions' => 5]);
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'extend_deadline'])->assertStatus(403);
    }

    public function test_a_receipt_for_the_wrong_amount_cannot_be_confirmed(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'under_review');
        DB::table('gcash_receipts')->insert(['booking_id' => $id, 'attempt_no' => 1, 'file_path' => 'receipts/x.png',
            'reference_number' => (string) random_int(1000000000000, 9999999999999), 'sha256_hash' => hash('sha256', uniqid('', true)),
            'amount_centavos' => 100, 'verdict' => 'manual_review', 'flags_json' => '[]']);
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'confirm_payment'])->assertStatus(409);
        $this->assertSame('under_review', $this->booking($id)->payment_status);
    }
}
