<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Aron, 2026-11-01. A walk-in has no account, so staff file the refund: the receipt must
   be this booking's, or (lost) staff attach proof and an administrator approves it. The
   money goes back by GCash or cash, the staff's call. Plus the POS/OR rule. */
class WalkInRefundTest extends TestCase
{
    use SeedsBookings;

    private function act(array $data)
    {
        return $this->postWithToken(route('admin.booking-action'), $data);
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4 refund proof');
    }

    /* A paid walk-in booking with its System Receipt: [id, reference, receipt number]. */
    private function seedPaidWalkIn(): array
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'paid_cash', 'cash', false, true);
        $walkIn = DB::table('customers')->insertGetId(['full_name' => 'Walk In Guest', 'contact_email' => 'guest@example.com']);
        DB::table('bookings')->where('id', $id)->update(['customer_id' => $walkIn]);
        DB::table('payments')->insert(['booking_id' => $id, 'payment_method' => 'cash',
            'amount' => $this->booking($id)->total_amount, 'payment_record_status' => 'confirmed', 'paid_at' => now(), 'confirmed_at' => now()]);
        legacy('bookings', 'system-receipt');
        $receipt = receipt_issue(venusep_db(), $id, (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id'));

        return [$id, $ref, 'VSR-' . now()->year . '-' . str_pad((string) $receipt, 6, '0', STR_PAD_LEFT)];
    }

    private function refund(int $id): ?object
    {
        return DB::table('refunds')->where('booking_id', $id)->orderByDesc('id')->first();
    }

    private function tearDownDocs(int $id): void
    {
        $dir = getenv('VENUSEP_DOC_ROOT') . '/bookings/' . $id;
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function test_staff_file_with_the_receipt_and_pay_in_cash(): void
    {
        [$id, $ref, $number] = $this->seedPaidWalkIn();
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'refund_file', 'note' => 'Event moved', 'receipt_number' => 'VSR-1999-000001'])
            ->assertStatus(400)->assertJson(['message' => 'That receipt number does not match this booking. Check it, or tick "The receipt was lost".']);
        $this->assertNull($this->refund($id));

        $this->act(['booking' => $ref, 'action' => 'refund_file', 'note' => 'Event moved', 'receipt_number' => strtolower($number), 'reason_category' => 'schedule_conflict'])->assertOk();
        $this->assertSame('requested', $this->refund($id)->refund_status);
        $this->assertSame('refund_requested', $this->booking($id)->payment_status);
        $this->act(['booking' => $ref, 'action' => 'refund_return', 'note' => 'x'])->assertStatus(409);

        try {
            $this->act(['booking' => $ref, 'action' => 'refund_complete', 'payout_method' => 'cash', 'proof' => [$this->proof()]])->assertOk();
            $refund = $this->refund($id);
            $this->assertSame('completed', $refund->refund_status);
            $this->assertNull($refund->refund_to_number);
            $this->assertNull($refund->payout_reference);
            $this->assertSame('refunded', $this->booking($id)->payment_status);
            $this->assertSame(1, DB::table('booking_documents')->where('booking_id', $id)->where('document_type', 'refund_support')->count());
        } finally {
            $this->tearDownDocs($id);
        }
    }

    public function test_a_lost_receipt_waits_for_the_staff_proof_and_an_admins_approval(): void
    {
        [$id, $ref] = $this->seedPaidWalkIn();
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'refund_file', 'note' => 'Lost it', 'receipt_lost' => '1'])
            ->assertStatus(400)->assertJson(['message' => 'Attach the proof you found, so an administrator can approve it.']);

        try {
            $this->act(['booking' => $ref, 'action' => 'refund_file', 'note' => 'Lost it', 'receipt_lost' => '1', 'support' => [$this->proof()]])->assertOk();
            $this->assertSame('under_review', $this->refund($id)->refund_status);
            $this->assertGreaterThan(0, DB::table('notifications')->where('booking_id', $id)->where('kind', 'refund_needs_admin')->count());

            $this->act(['booking' => $ref, 'action' => 'refund_complete', 'payout_method' => 'cash'])->assertStatus(403);
            $this->act(['booking' => $ref, 'action' => 'refund_approve'])->assertStatus(403);
            $this->assertSame('under_review', $this->refund($id)->refund_status);
        } finally {
            $this->tearDownDocs($id);
        }
    }

    public function test_an_admin_approves_the_proof_and_gcash_records_the_number(): void
    {
        [$id, $ref] = $this->seedPaidWalkIn();
        DB::table('refunds')->insert(['booking_id' => $id, 'reason_category' => 'other', 'reason' => 'Lost it',
            'amount_requested' => $this->booking($id)->total_amount, 'refund_status' => 'under_review']);
        DB::table('bookings')->where('id', $id)->update(['payment_status' => 'refund_requested']);

        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'refund_complete'])->assertStatus(403);
        $this->act(['booking' => $ref, 'action' => 'refund_approve'])->assertOk();
        $this->assertSame('approved', $this->refund($id)->refund_status);
        $this->assertSame(1, DB::table('booking_timeline')->where('booking_id', $id)->where('action_code', 'refund_approved')->count());

        $this->act(['booking' => $ref, 'action' => 'refund_complete', 'payout_method' => 'gcash', 'payout_to' => '0917 555 0123', 'payout_reference' => '1234567890123'])->assertOk();
        $this->assertSame('09175550123', $this->refund($id)->refund_to_number);
    }

    /* The same rule online: a customer who filed without the receipt waits for an administrator. */
    public function test_an_online_refund_without_the_receipt_needs_an_admin(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'refund_requested', 'gcash', false, true);
        DB::table('refunds')->insert(['booking_id' => $id, 'reason_category' => 'other', 'reason' => 'x', 'official_receipt_pending' => 1,
            'amount_requested' => $this->booking($id)->total_amount, 'refund_status' => 'requested']);
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'refund_complete', 'note' => 'I say it is fine'])->assertStatus(403);
        $this->act(['booking' => $ref, 'action' => 'refund_approve'])->assertStatus(403);
        try {
            $this->act(['booking' => $ref, 'action' => 'refund_add_proof', 'support' => [$this->proof()]])->assertOk();
            $this->assertSame(1, DB::table('booking_documents')->where('booking_id', $id)->count());
        } finally {
            $this->tearDownDocs($id);
        }
    }

    public function test_an_admin_approves_an_online_refund_without_the_receipt(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'refund_requested', 'gcash', false, true);
        DB::table('refunds')->insert(['booking_id' => $id, 'reason_category' => 'other', 'reason' => 'x', 'official_receipt_pending' => 1,
            'amount_requested' => $this->booking($id)->total_amount, 'refund_status' => 'requested']);
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'refund_complete'])->assertStatus(403);
        $this->act(['booking' => $ref, 'action' => 'refund_approve'])->assertOk();
        $this->act(['booking' => $ref, 'action' => 'refund_complete'])->assertOk();
        $this->assertSame('completed', $this->refund($id)->refund_status);
    }

    public function test_an_account_holder_files_their_own_and_another_office_is_refused(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'approved', 'confirmed', 'gcash', false, true);
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'refund_file', 'note' => 'x'])
            ->assertStatus(409)->assertJson(['message' => 'The customer files this refund from their own account.']);

        [, $other] = $this->seedVenueBooking('r5', 'approved', 'confirmed', 'gcash', false, true);
        $this->act(['booking' => $other, 'action' => 'refund_file', 'note' => 'x'])->assertStatus(403);
    }

    public function test_the_or_comes_after_payment_and_paperwork_never_lands_on_a_closed_booking(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'await_gcash');
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'record_or', 'or' => 'OR-1'])
            ->assertStatus(409)->assertJson(['message' => 'An Official Receipt is recorded only after the payment.']);

        DB::table('bookings')->where('id', $id)->update(['reservation_status' => 'cancelled', 'payment_status' => 'locked']);
        $this->act(['booking' => $ref, 'action' => 'record_pos', 'pos' => 'POS-1'])
            ->assertStatus(409)->assertJson(['message' => 'A POS cannot be recorded on a closed booking.']);
        $this->act(['booking' => $ref, 'action' => 'record_or', 'or' => 'OR-1'])
            ->assertStatus(409)->assertJson(['message' => 'An Official Receipt cannot be recorded on a closed booking.']);
    }
}
