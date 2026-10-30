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

    /* Found 2026-10-30: reject_payment had no state check, so it reopened payment on a PAID booking. */
    public function test_a_paid_booking_cannot_have_its_payment_rejected(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'confirmed');
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'reject_payment', 'reason' => 'Receipt image is unreadable'])
            ->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'There is no receipt waiting for review on this booking.']);
        $this->assertSame('confirmed', $this->booking($id)->payment_status);
    }

    /* Found 2026-10-30: "reject" accepted an approved, PAID booking (and a released one)
       and locked its payment. Only a pending request is rejected. */
    public function test_only_a_pending_request_can_be_rejected(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'confirmed');
        [$relId, $relRef] = $this->seedVenueBooking('r1', 'released', 'expired');
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'reject'])
            ->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'Only a pending request can be rejected.']);
        $this->act(['booking' => $relRef, 'action' => 'reject'])
            ->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'That booking is already closed.']);
        $this->assertSame(['approved', 'confirmed'], [$this->booking($id)->reservation_status, $this->booking($id)->payment_status]);
        $this->assertSame('released', $this->booking($relId)->reservation_status);
    }

    /* §7 Undo rejection: the receipt goes back under review, with the timeline keeping both steps. */
    public function test_undo_rejection_puts_the_receipt_back_under_review(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $receiptId = $this->seedReceipt($id);
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'undo_rejection'])->assertStatus(409);   // nothing rejected yet
        $this->act(['booking' => $ref, 'action' => 'reject_payment', 'reason' => 'Receipt image is unreadable'])->assertOk();

        $tl = $this->act(['booking' => $ref, 'action' => 'undo_rejection'])
            ->assertOk()->assertJson(['booking' => ['pay' => 'under_review']])->json('booking.tl');
        $this->assertSame('Rejection withdrawn; the receipt is under review again.', end($tl)['m']);
        $g = DB::table('gcash_receipts')->where('id', $receiptId)->first();
        $this->assertNull($g->reviewed_at);
        $this->assertNotContains('rejected_by_staff', json_decode($g->flags_json, true));
        $this->assertSame('under_review', DB::table('payments')->where('id', $g->payment_id)->value('payment_record_status'));

        /* and it can be decided again: confirmed, on the same payment row */
        $this->act(['booking' => $ref, 'action' => 'confirm_payment'])->assertOk()->assertJson(['booking' => ['pay' => 'confirmed']]);
        $this->assertSame(1, DB::table('payments')->where('booking_id', $id)->count());
    }

    public function test_undo_is_refused_once_the_customer_sent_another_receipt(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->seedReceipt($id);
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'reject_payment', 'reason' => 'Receipt image is unreadable'])->assertOk();
        $this->seedReceipt($id);   // a new upload: under review again
        $this->act(['booking' => $ref, 'action' => 'undo_rejection'])->assertStatus(409)->assertJson(['ok' => false]);
    }

    /* §7 Override & confirm: a rejected receipt, then staff find the money in GCash. */
    public function test_override_confirms_a_rejected_receipt_with_a_receipt_issued(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $receiptId = $this->seedReceipt($id);
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'reject_payment', 'reason' => 'Amount doesn\'t match'])->assertOk();
        $this->act(['booking' => $ref, 'action' => 'override_confirm', 'ref' => '1234567890123', 'note' => ''])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Both the verified reference number and a note are required.']);

        $this->act(['booking' => $ref, 'action' => 'override_confirm', 'ref' => '1234567890123', 'note' => 'Found it; the OCR misread the amount.'])
            ->assertOk()->assertJson(['booking' => ['pay' => 'confirmed']]);
        $g = DB::table('gcash_receipts')->where('id', $receiptId)->first();
        $this->assertContains('overridden_by_staff', json_decode($g->flags_json, true));
        $this->assertSame('Override — verified ref 1234567890123 in GCash. Found it; the OCR misread the amount.', $g->override_note);
        $this->assertSame(['confirmed'], DB::table('payments')->where('booking_id', $id)->pluck('payment_record_status')->all());
        $this->assertSame(1, DB::table('system_receipts')->where('booking_id', $id)->count());
    }

    public function test_override_of_a_duplicate_is_admin_only_and_needs_a_rejected_receipt(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'override_confirm', 'ref' => '1', 'note' => 'x'])
            ->assertStatus(409);   // no receipt at all
        $this->seedReceipt($id, 'rejected', ['duplicate_ref']);   // the checker refused it; payment stays open
        $this->act(['booking' => $ref, 'action' => 'override_confirm', 'ref' => '1', 'note' => 'x'])
            ->assertStatus(403)->assertJson(['message' => 'This receipt was rejected as a duplicate. Only an administrator can override that.']);
        $this->assertSame('await_gcash', $this->booking($id)->payment_status);

        $this->actingAsAdmin()->act(['booking' => $ref, 'action' => 'override_confirm', 'ref' => '1', 'note' => 'Checked: a genuine second transfer.'])
            ->assertOk()->assertJson(['booking' => ['pay' => 'confirmed']]);
        $this->assertSame(1, DB::table('payments')->where('booking_id', $id)->where('payment_record_status', 'confirmed')->count());
    }

    /* §7 Extend deadline: overdue -> payment open again for 48 hours from now. */
    public function test_extend_deadline_reopens_an_overdue_payment_for_48_hours(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'overdue', 'gcash', true, false);
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'extend_deadline'])
            ->assertOk()->assertJson(['booking' => ['pay' => 'await_gcash']]);
        $deadline = strtotime($this->booking($id)->current_deadline_at);
        $this->assertEqualsWithDelta(strtotime('+48 hours'), $deadline, 120);
        $this->assertSame(1, DB::table('booking_timeline')->where('booking_id', $id)->where('new_payment_status', 'await_gcash')
            ->where('note', 'like', 'Pay-by deadline extended 48 hours, to %')->count());
        $this->act(['booking' => $ref, 'action' => 'extend_deadline'])->assertStatus(409);   // not overdue any more
    }

    /* §7 Release slot: overdue -> released / expired; the dates free up; staff stay in their venue. */
    public function test_release_slot_ends_an_overdue_booking_and_frees_its_dates(): void
    {
        [$otherId, $otherRef] = $this->seedVenueBooking('r5', 'approved', 'overdue', 'gcash', true, false);   // venue 2
        $this->actingAsStaff()->act(['booking' => $otherRef, 'action' => 'release_slot'])->assertStatus(403);
        $this->assertSame('approved', $this->booking($otherId)->reservation_status);

        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->act(['booking' => $ref, 'action' => 'release_slot'])->assertStatus(409);   // not overdue
        DB::table('bookings')->where('id', $id)->update(['refunds_allowed' => 0, 'payment_status' => 'overdue']);
        $this->act(['booking' => $ref, 'action' => 'release_slot'])
            ->assertOk()->assertJson(['booking' => ['res' => 'released', 'pay' => 'expired']]);
        $this->assertSame(0, DB::table('venue_booking_slots')->where('booking_id', $id)->whereNull('released_at')->count());
        $this->assertSame(1, DB::table('notifications')->where('booking_id', $id)->where('kind', 'expired')->count());   // the customer is told
    }

    public function test_the_page_offers_the_reason_list(): void
    {
        [, $ref] = $this->seedVenueBooking('r1');
        $this->actingAsStaff()->get(route('admin.booking-request', ['id' => $ref]))->assertOk()
            ->assertSee('const REJECT_REASONS = ["Reference number not found in the Transaction History","Amount doesn\'t match","Receipt image is unreadable","Other"];', false)
            ->assertDontSee('the reference was not found in the Transaction History.', false)
            /* §7: the four buttons call the server, not just the screen */
            ->assertSee("staffAction('release_slot'", false)->assertSee("staffAction('extend_deadline'", false)
            ->assertSee("staffAction('undo_rejection'", false)->assertSee("staffAction('override_confirm'", false)
            ->assertDontSee("cur.res = 'released'", false)
            /* a hostel stay's rejected upload reaches the same box */
            ->assertSee("if (cur.pay === 'await_gcash' && !rejectedUpload())", false);
    }
}
