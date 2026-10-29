<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/booking-action — what staff do with an uploaded GCash receipt:
   reject it with a reason (bug 10), and the §7 actions that used to change
   only the screen (2026-10-30). */
class ReceiptReviewActionsTest extends TestCase
{
    use SeedsBookings;

    private function act(array $data)
    {
        return $this->postWithToken(route('admin.booking-action'), $data);
    }

    /* A GCash upload on the booking, as customer/payment-submit records it:
       the payment row, the receipt row, the booking under review. */
    private function seedReceipt(int $bookingId, string $verdict = 'manual_review', array $flags = []): int
    {
        $b = $this->booking($bookingId);
        $paymentId = $verdict === 'rejected' ? null : DB::table('payments')->insertGetId([
            'booking_id' => $bookingId, 'payment_method' => 'gcash', 'amount' => $b->total_amount,
            'payment_record_status' => 'under_review', 'paid_at' => now(),
        ]);
        $n = DB::table('gcash_receipts')->where('booking_id', $bookingId)->count() + 1;
        $id = DB::table('gcash_receipts')->insertGetId([
            'payment_id' => $paymentId, 'booking_id' => $bookingId, 'attempt_no' => $n,
            'file_path' => 'receipts/test-' . $bookingId . '-' . $n . '.png',
            'reference_number' => (string) random_int(1000000000000, 9999999999999),
            'sha256_hash' => hash('sha256', uniqid('', true)), 'amount_centavos' => (int) round($b->total_amount * 100),
            'verdict' => $verdict, 'flags_json' => json_encode($flags),
        ]);
        if ($verdict !== 'rejected') {
            DB::table('bookings')->where('id', $bookingId)->update(['payment_status' => 'under_review']);
        }

        return $id;
    }

    private function customerUserId(): int
    {
        return (int) DB::table('users')->where('email', 'customer@gmail.com')->value('id');
    }

    /* Bug 10: rejecting always sent "reference not found in the Transaction History". */
    public function test_reject_receipt_with_a_reason_and_note_reaches_the_customer(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $receiptId = $this->seedReceipt($id);

        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'reject_payment',
                'reason' => 'Amount doesn\'t match', 'note' => 'You sent ₱500 less.'])
            ->assertOk()->assertJson(['ok' => true, 'booking' => ['pay' => 'await_gcash']]);

        $said = 'Amount doesn\'t match — You sent ₱500 less.';
        $this->assertSame($said, $this->booking($id)->staff_notes);
        $this->assertSame($said, DB::table('gcash_receipts')->where('id', $receiptId)->value('override_note'));
        /* the customer's bell, where the fixed text used to appear */
        legacy('notifications');
        $texts = array_column(notif_items(venusep_db(), $this->customerUserId(), 'customer'), 'text');
        $this->assertContains('Your GCash receipt for ' . $ref . ' was not accepted: ' . $said . ' Please upload another.', $texts);
    }

    public function test_reject_receipt_reason_must_come_from_the_list_and_other_needs_a_note(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->seedReceipt($id);
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'reject_payment', 'reason' => 'Because', 'note' => 'x'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'A reason is required — the customer reads it.']);
        $this->act(['booking' => $ref, 'action' => 'reject_payment', 'reason' => 'Other', 'note' => ' '])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Write the reason in the note — the customer reads it.']);
        $this->assertSame('under_review', $this->booking($id)->payment_status);

        $this->act(['booking' => $ref, 'action' => 'reject_payment', 'reason' => 'Other', 'note' => 'Sent to the wrong venue.'])->assertOk();
        $this->assertSame('Sent to the wrong venue.', $this->booking($id)->staff_notes);
    }

    public function test_the_page_offers_the_reason_list(): void
    {
        [, $ref] = $this->seedVenueBooking('r1');
        $this->actingAsStaff()->get(route('admin.booking-request', ['id' => $ref]))->assertOk()
            ->assertSee('const REJECT_REASONS = ["Reference number not found in the Transaction History","Amount doesn\'t match","Receipt image is unreadable","Other"];', false)
            ->assertDontSee('the reference was not found in the Transaction History.', false);
    }
}
