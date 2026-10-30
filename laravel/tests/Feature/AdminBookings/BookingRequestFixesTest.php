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

    /* The booking as the page's DATA holds it after a fresh load. */
    private function pageRow(string $ref): array
    {
        $html = $this->page($ref)->getContent();
        $this->assertSame(1, preg_match('/const DATA = (\{.*\});\n/', $html, $m), 'no DATA on the page');

        return json_decode($m[1], true)[$ref];
    }

    /* Bug 4: a new timeline entry said "Jul 14, 2026 — You (staff)" until a reload.
       The action's reply now carries the timeline exactly as a reload shows it. */
    public function test_action_reply_timeline_matches_a_reload(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', false, false);
        $tl = $this->actingAsStaff()->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => 'approve'])
            ->assertOk()->json('booking.tl');

        $last = end($tl);
        $this->assertStringEndsWith(' — Marites Robles', $last['w']);   // the acting staff member, by name
        $this->assertSame('Approved; pay after the event.', $last['m']);
        $this->assertSame($this->pageRow($ref)['tl'], $tl);
    }

    /* Bug 6: recording the POS or the OR wrote nothing to booking_timeline, so
       neither survived a reload. Check-in had the same gap. */
    public function test_pos_or_and_check_in_are_in_the_timeline_after_a_reload(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'paid_cash', 'cash', false, true);
        DB::table('hostel_booking_details')->where('booking_id', $id)->update(['check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString()]);
        $this->actingAsAdmin();
        foreach ([['record_pos', 'pos' => 'POS-123'], ['record_or', 'or' => 'OR-456'], ['check_in']] as $a) {
            $this->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => array_shift($a)] + $a)->assertOk();
        }

        $tl = array_slice($this->pageRow($ref)['tl'], -3);
        $this->assertSame(['POS recorded', 'Official Receipt recorded', 'Guest checked in'], array_column($tl, 'x'));
        $this->assertSame(['POS POS-123 recorded (CEDU).', 'Official Receipt OR-456 recorded (University Cashier).', 'Guest checked in.'],
            array_column($tl, 'm'));
        $this->assertStringEndsWith(' — admin@gmail.com', $tl[0]['w']);   // the admin has no staff profile: the email names them
    }

    /* Bug 8: a hostel stay paid at the front desk read "Paid at cashier" (the
       University Cashier is the venues' cash window, DB-DECISIONS #26). */
    public function test_hostel_cash_is_labelled_paid_at_the_front_desk(): void
    {
        [, $hostelRef] = $this->seedHostelBooking('approved', 'paid_cash', 'cash', false, true);
        [, $venueRef] = $this->seedVenueBooking('r1', 'approved', 'paid_cash', 'cash');
        $this->actingAsAdmin();
        $label = fn ($ref) => $this->get(route('root.booking-details', ['booking' => $ref]))->assertOk()->json('details.money.paymentStatus');
        $this->assertSame('Paid at the front desk', $label($hostelRef));
        $this->assertSame('Paid at cashier', $label($venueRef));

        $this->get(route('admin.booking-requests'))->assertOk()
            ->assertSee('"id":"' . $hostelRef . '"', false)->assertSee('"pay":"paid_cash","payLabel":"Paid at the front desk"', false)
            ->assertSee("r.pay === 'paid_cash' ? esc(r.payLabel)", false);
        $this->page($hostelRef)->assertSee("cur.pay === 'paid_cash') return \"Paid at the front desk\";", false);
    }

    /* Bug 9: a paid stay could be checked in days before its check-in date. */
    public function test_check_in_only_from_the_check_in_date(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'paid_cash', 'cash', false, true);   // check-in 400+ days out
        $this->assertFalse($this->pageRow($ref)['checkInOpen']);
        $this->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => 'check_in'])
            ->assertStatus(409)->assertJson(['ok' => false])
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Too early: check-in opens on '));
        $this->assertSame(0, (int) DB::table('hostel_booking_details')->where('booking_id', $id)->value('checked_in'));

        DB::table('hostel_booking_details')->where('booking_id', $id)->update(['check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString()]);
        $this->assertTrue($this->pageRow($ref)['checkInOpen']);
        $this->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => 'check_in'])->assertOk();
        $this->assertSame(1, (int) DB::table('hostel_booking_details')->where('booking_id', $id)->value('checked_in'));
    }

    /* Found 2026-10-30: check-in accepted a cancelled stay, and a second check-in
       of the same guest (a second timeline row). */
    public function test_check_in_needs_an_approved_stay_and_happens_once(): void
    {
        [$id, $ref] = $this->seedHostelBooking('approved', 'paid_cash', 'cash', false, true);
        DB::table('hostel_booking_details')->where('booking_id', $id)->update(['check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString()]);
        $this->actingAsAdmin();
        $checkIn = fn () => $this->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => 'check_in']);
        $checkIn()->assertOk();
        $checkIn()->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'The guest is already checked in.']);
        $this->assertSame(1, DB::table('booking_timeline')->where('booking_id', $id)->where('action_code', 'checked_in')->count());

        DB::table('hostel_booking_details')->where('booking_id', $id)->update(['checked_in' => 0]);
        DB::table('bookings')->where('id', $id)->update(['reservation_status' => 'cancelled']);
        $checkIn()->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'Only an approved stay can be checked in.']);
    }

    /* Found 2026-10-30: the "Pay-by rule" line read the LIVE refund switch, so a
       post-pay booking waiting on GCash after its event said "1 day before the event". */
    public function test_pay_by_rule_follows_the_bookings_own_policy(): void
    {
        [, $post] = $this->seedVenueBooking('r1', 'approved', 'overdue', 'gcash', true, false);
        [, $pre] = $this->seedVenueBooking('r1', 'approved', 'await_gcash', 'gcash', true, true);
        $this->assertFalse($this->pageRow($post)['prepay']);
        $this->assertTrue($this->pageRow($pre)['prepay']);
        $this->page($post)->assertSee('${!cur.prepay', false)->assertSee("'GCash')}\${cur.prepay", false)
            ->assertDontSee("(!PAY_POLICY.prepay && ['pending','locked']", false);
    }

    public function test_the_page_has_no_fake_timeline_stamp(): void
    {
        [, $ref] = $this->seedVenueBooking('r1');
        $this->page($ref)->assertDontSee('You (staff)')->assertDontSee('function now()', false)
            ->assertSee('cur.tl = out.booking.tl', false);
    }
}
