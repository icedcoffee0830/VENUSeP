<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* The staff actions that had no test of their own when their SQL moved to the query
   files (cleanup, 2026-11-01): what each one saves, and the History line it writes. */
class StaffActionCoverageTest extends TestCase
{
    use SeedsBookings;

    private function act(array $data)
    {
        return $this->postWithToken(route('admin.booking-action'), $data);
    }

    private function lastHistory(int $id): object
    {
        return DB::table('booking_timeline')->where('booking_id', $id)->orderByDesc('id')->first();
    }

    public function test_cash_received_at_the_front_desk_pays_the_stay_with_a_receipt(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'await_gcash', 'gcash', false, true);
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'cash_received'])
            ->assertStatus(409)->assertJson(['ok' => false, 'booking' => ['pay' => 'await_gcash']]);

        DB::table('bookings')->where('id', $id)->update(['payment_status' => 'await_cash']);
        $this->act(['booking' => $ref, 'action' => 'cash_received'])
            ->assertOk()->assertJson(['booking' => ['pay' => 'paid_cash', 'method' => 'Cash']]);
        $this->assertSame(1, DB::table('payments')->where('booking_id', $id)->where('payment_record_status', 'confirmed')->count());
        $this->assertSame(1, DB::table('system_receipts')->where('booking_id', $id)->count());
        $this->assertSame('Cash received at the front desk.', $this->lastHistory($id)->note);
        $this->assertNull(DB::selectOne('SELECT @venusep_actor_user_id AS who')->who);
    }

    public function test_five_more_receipt_attempts_need_a_note_and_used_up_attempts(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'allow_receipt_attempts'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'A note is required — say why this booking gets more attempts.']);
        $this->act(['booking' => $ref, 'action' => 'allow_receipt_attempts', 'note' => 'Network trouble'])
            ->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'This booking still has receipt attempts left.']);

        for ($n = 1; $n <= 5; $n++) {
            DB::table('gcash_receipts')->insert(['booking_id' => $id, 'attempt_no' => $n, 'file_path' => 'receipts/cov-' . $id . '-' . $n . '.png',
                'reference_number' => (string) random_int(1000000000000, 9999999999999), 'sha256_hash' => hash('sha256', uniqid('', true)),
                'verdict' => 'rejected', 'flags_json' => '[]']);
        }
        $this->act(['booking' => $ref, 'action' => 'allow_receipt_attempts', 'note' => 'Network trouble'])
            ->assertOk()->assertJson(['booking' => ['attempts' => 5, 'attemptsAllowed' => 10]]);
        $row = $this->lastHistory($id);
        $this->assertSame('receipt_attempts_allowed', $row->action_code);
        $this->assertSame('5 more GCash receipt attempts (now 10 in total): Network trouble', $row->note);
        $this->assertSame(['allowed_before' => 5, 'allowed_after' => 10], json_decode($row->metadata_json, true));
    }

    /* An account holder's refund: [id, reference, refund id]. */
    private function seedRefundRequest(): array
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'refund_requested', 'gcash', false, true);
        $refund = DB::table('refunds')->insertGetId(['booking_id' => $id, 'reason_category' => 'other', 'reason' => 'x',
            'amount_requested' => $this->booking($id)->total_amount, 'refund_status' => 'requested']);

        return [$id, $ref, $refund];
    }

    public function test_a_denied_refund_keeps_the_booking_paid(): void
    {
        [$id, $ref, $refund] = $this->seedRefundRequest();
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'refund_deny'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'A reason is required — the customer reads it.']);
        $this->act(['booking' => $ref, 'action' => 'refund_deny', 'note' => 'Past the deadline'])
            ->assertOk()->assertJson(['booking' => ['res' => 'approved', 'pay' => 'refund_denied']]);
        $r = DB::table('refunds')->where('id', $refund)->first();
        $this->assertSame(['rejected', 'Past the deadline'], [$r->refund_status, $r->notes]);
        $this->assertSame('Refund denied: Past the deadline', $this->lastHistory($id)->note);

        $this->act(['booking' => $ref, 'action' => 'refund_deny', 'note' => 'Again'])
            ->assertStatus(409)->assertJson(['message' => 'This request is no longer open — it was rejected while this page was open. Nothing has been recorded.']);
    }

    public function test_a_refund_returned_for_correction_gets_48_hours(): void
    {
        [$id, $ref, $refund] = $this->seedRefundRequest();
        $this->actingAsStaff();
        $this->act(['booking' => $ref, 'action' => 'refund_return', 'note' => 'Attach the receipt'])
            ->assertOk()->assertJson(['booking' => ['pay' => 'refund_correction']]);
        $r = DB::table('refunds')->where('id', $refund)->first();
        $this->assertSame('returned_for_correction', $r->refund_status);
        $this->assertEqualsWithDelta(strtotime('+48 hours'), strtotime($r->correction_due_at), 120);
        $this->assertSame('Refund returned for correction: Attach the receipt', $this->lastHistory($id)->note);
    }

    /* A pre-pay hostel stay (the rule before pay-to-use): the POS opens payment. */
    public function test_the_pos_opens_payment_on_a_pre_pay_stay(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'await_pos', 'gcash', true, false);
        DB::table('bookings')->where('id', $id)->update(['pay_to_use' => 0]);
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'record_pos', 'pos' => 'POS-9'])
            ->assertOk()->assertJson(['booking' => ['pay' => 'await_gcash']]);
        $this->assertSame('POS-9', DB::table('hostel_booking_details')->where('booking_id', $id)->value('pos_number'));
        $row = $this->lastHistory($id);
        $this->assertSame(['status_changed', 'POS POS-9 recorded; payment unlocked.'], [$row->action_code, $row->note]);
        $this->assertNotNull($this->booking($id)->current_deadline_at);
    }

    public function test_staff_move_a_stay_to_open_nights_of_the_same_length(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'paid_cash', 'cash', false, true);
        legacy('offices');
        $office = office_by_id(DB::table('rooms')->where('room_code', 'h1')->value('venue_id'));
        $in = now()->addDays(random_int(400, 4000))->toDateString();
        while (office_closed_reason($office, $in)) {
            $in = date('Y-m-d', strtotime($in . ' +1 day'));
        }
        $out = date('Y-m-d', strtotime($in . ' +1 day'));
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'move_date', 'from' => $in, 'to' => date('Y-m-d', strtotime($in . ' +2 day'))])
            ->assertStatus(400)->assertJson(['message' => 'The new dates must cover the same number of nights, so the price stays the same.']);
        $this->act(['booking' => $ref, 'action' => 'move_date', 'from' => $in, 'to' => $out])->assertOk();
        $stay = DB::table('hostel_booking_details')->where('booking_id', $id)->first();
        $this->assertSame([$in, $out], [$stay->check_in_date, $stay->check_out_date]);
        $this->assertSame(1, (int) $this->booking($id)->date_moves);
        $this->assertSame('date_moved', $this->lastHistory($id)->action_code);
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    /* The booking window holds for a move too: a date past it is refused, nothing changes. */
    public function test_a_stay_cannot_be_moved_past_the_booking_window(): void
    {
        config(['venusep.max_days_ahead' => 183]);
        [$id, $ref] = $this->seedHostelBooking('approved', 'paid_cash', 'cash', false, true);
        $was = DB::table('hostel_booking_details')->where('booking_id', $id)->first();
        $in = date('Y-m-d', strtotime('+183 days'));
        $this->actingAsAdmin();
        $this->act(['booking' => $ref, 'action' => 'move_date', 'from' => $in, 'to' => date('Y-m-d', strtotime($in . ' +1 day'))])
            ->assertStatus(409)->assertJson(['message' => 'Bookings can be made at most 183 days ahead. Pick dates on or before ' . date('M j, Y', strtotime($in)) . '.']);
        $this->assertSame($was->check_in_date, DB::table('hostel_booking_details')->where('booking_id', $id)->value('check_in_date'));
    }

    /* A procedure's refusal (SIGNAL 45000) reads as it did before the SQL moved to DB::,
       never with the query appended. */
    public function test_a_database_refusal_is_shown_in_pdo_words_without_the_sql(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked');
        DB::unprepared("
            CREATE TRIGGER zz_test_refuse_approval BEFORE UPDATE ON bookings FOR EACH ROW
            BEGIN
                IF NEW.id = $id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test';
                END IF;
            END
        ");
        try {
            $this->actingAsStaff();
            $this->act(['booking' => $ref, 'action' => 'approve'])
                ->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'SQLSTATE[45000]: <<Unknown error>>: 1644 refused by the test']);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS zz_test_refuse_approval');
        }
        $this->assertSame('pending', $this->booking($id)->reservation_status);
    }
}
