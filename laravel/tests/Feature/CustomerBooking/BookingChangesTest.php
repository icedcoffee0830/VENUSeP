<?php

namespace Tests\Feature\CustomerBooking;

use App\Http\Controllers\Customer\BookingRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\TestCase;

/* The teammate's answers (2026-11-01): cancel before approval, send a new ID after a
   rejection, move a date once, at most 5 unpaid per office, staff don't change prices. */
class BookingChangesTest extends TestCase
{
    use SeedsBookings;

    private function asOwner()
    {
        return $this->actingAsCustomer('customer@gmail.com')->withSession(['_token' => 'tok']);
    }

    public function test_a_customer_cancels_a_request_but_not_an_approved_booking(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked');
        $this->asOwner()->post(route('customer.booking-cancel'), ['_token' => 'tok', 'booking' => $ref])->assertOk();
        $this->assertSame('cancelled', $this->booking($id)->reservation_status);
        $this->assertNotNull(DB::table('venue_booking_slots')->where('booking_id', $id)->value('released_at'));

        [$id2, $ref2] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->asOwner()->post(route('customer.booking-cancel'), ['_token' => 'tok', 'booking' => $ref2])->assertStatus(409);
        $this->assertSame('approved', $this->booking($id2)->reservation_status);
    }

    public function test_someone_elses_booking_is_not_found(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked');
        $this->actingAsCustomer('aronerfe0830@gmail.com')->withSession(['_token' => 'tok'])
            ->post(route('customer.booking-cancel'), ['_token' => 'tok', 'booking' => $ref])->assertNotFound();
    }

    public function test_a_rejected_request_comes_back_with_a_new_id(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked');
        DB::table('bookings')->where('id', $id)->update(['reservation_status' => 'rejected']);
        $this->assertNotNull(DB::table('venue_booking_slots')->where('booking_id', $id)->value('released_at'));
        $this->asOwner()->post(route('customer.booking-fix'), ['_token' => 'tok', 'booking' => $ref])->assertStatus(400);

        $id_doc = UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 new id');
        try {
            $this->asOwner()->post(route('customer.booking-fix'), ['_token' => 'tok', 'booking' => $ref, 'id_document' => $id_doc])->assertOk();
            $this->assertSame('pending', $this->booking($id)->reservation_status);
            $this->assertSame(1, DB::table('venue_booking_slots')->where('booking_id', $id)->whereNull('released_at')->count());
        } finally {
            $dir = getenv('VENUSEP_DOC_ROOT') . '/bookings/' . $id;
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    public function test_staff_move_a_date_once_at_the_same_length(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        do {
            $day = now()->addDays(random_int(400, 4000))->toDateString();
        } while (DB::table('venue_booking_slots as s')->join('rooms as r', 'r.id', '=', 's.room_id')->where('r.room_code', 'r1')->where('s.slot_date', $day)->exists());
        $move = ['booking' => $ref, 'action' => 'move_date', 'from' => $day, 'to' => $day, 'start' => '09:00', 'end' => '12:00'];
        $this->actingAsStaff();
        $this->postWithToken(route('admin.booking-action'), ['to' => date('Y-m-d', strtotime($day . ' +1 day'))] + $move)->assertStatus(400);
        $this->postWithToken(route('admin.booking-action'), $move)->assertOk();
        $this->assertSame($day, DB::table('venue_booking_details')->where('booking_id', $id)->value('start_date'));
        $this->assertSame(1, (int) $this->booking($id)->date_moves);
        $this->postWithToken(route('admin.booking-action'), $move)->assertStatus(403);
    }

    public function test_at_most_five_unpaid_bookings_per_office_online(): void
    {
        legacy('db', 'offices');
        config(['venusep.max_unpaid' => 5]);
        $customer = DB::table('customers')->insertGetId(['full_name' => 'Unpaid Limit Test']);
        for ($i = 0; $i < 4; $i++) {
            [$id] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
            DB::table('bookings')->where('id', $id)->update(['customer_id' => $customer]);
        }
        $this->assertNull(BookingRules::tooManyUnpaid(venusep_db(), $customer, 1));
        [$id] = $this->seedVenueBooking('r1', 'pending', 'locked');
        DB::table('bookings')->where('id', $id)->update(['customer_id' => $customer]);
        $this->assertNotNull(BookingRules::tooManyUnpaid(venusep_db(), $customer, 1));
        $this->assertNull(BookingRules::tooManyUnpaid(venusep_db(), $customer, 2));
    }

    public function test_only_an_admin_changes_a_price(): void
    {
        $fee = (float) DB::table('event_room_details as d')->join('rooms as r', 'r.id', '=', 'd.room_id')->where('r.room_code', 'r1')->value('d.fee_per_day');
        $this->actingAsStaff();
        $this->postWithToken(route('admin.room-save'), ['room_id' => 'r1', 'name' => 'Alumni Grand Ballroom', 'venue' => 'Bahay Alumni', 'room_type' => 'event', 'fee' => (string) ($fee + 100)])
            ->assertStatus(403)->assertJson(['error' => 'admin_price']);
    }

    public function test_status_emails_go_to_the_account_holder(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->actingAsStaff();
        $this->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => 'force_cancel', 'reason' => 'asked', 'note' => 'They phoned us'])->assertOk();
        $mail = DB::table('email_outbox')->where('booking_id', $id)->where('kind', 'booking_cancelled')->first();
        $this->assertNotNull($mail);
        $this->assertSame('customer@gmail.com', $mail->to_email);
        $this->assertStringContainsString('They phoned us', $mail->body_text);
    }
}
