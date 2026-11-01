<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* system_settings.hostel_advance_booking_max_days (Aron, 2026-10-30): a hostel stay
   can be booked at most that many days before check-in — greyed out in the hostel
   date picker, refused online (booking-submit) and at the counter (booking-create).
   The number comes from the setting: these tests set it to 3, not the seeded 7. */
class HostelAdvanceLimitTest extends TestCase
{
    private ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = DB::table('system_settings')->where('setting_key', 'hostel_advance_booking_max_days')->value('setting_value');
        DB::update("UPDATE system_settings SET setting_value = '3' WHERE setting_key = 'hostel_advance_booking_max_days'");
    }

    protected function tearDown(): void
    {
        DB::update("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'hostel_advance_booking_max_days'", [$this->saved]);
        parent::tearDown();
    }

    private function idFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return new UploadedFile($path, 'my-id.png', 'image/png', null, true);
    }

    private function message(): string
    {
        return 'Hostel stays can be booked at most 3 days ahead. Pick a check-in on or before ' . date('M j, Y', strtotime('+3 days')) . '.';
    }

    public function test_booking_submit_refuses_a_check_in_beyond_the_limit(): void
    {
        $before = DB::table('bookings')->count();
        $in = date('Y-m-d', strtotime('+4 days'));
        $this->actingAsCustomer('aronerfe0830@gmail.com')->withSession(['_token' => 'test-token'])
            ->post(route('customer.booking-submit'), [
                '_token' => 'test-token', 'type' => 'hostel', 'room' => 'h1', 'affiliated' => '0', 'agree_no_refund' => '1',
                'check_in' => $in, 'check_out' => date('Y-m-d', strtotime($in . ' +1 day')),
                'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'male']]),
                'id_document' => $this->idFile(),
            ])->assertStatus(400)->assertExactJson(['ok' => false, 'message' => $this->message()]);
        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_the_counter_refuses_a_check_in_beyond_the_limit(): void
    {
        $before = DB::table('bookings')->count();
        $in = date('Y-m-d', strtotime('+4 days'));
        $this->actingAsAdmin()->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.booking-create'), [
                'type' => 'hostel', 'room' => 'h1', 'id_checked' => '1', 'usep_id' => '0', 'agree_no_refund' => '1', 'cash_received' => '1',
                'booker_mode' => 'walkin', 'walkin_name' => 'Advance Walk-in', 'walkin_phone' => '09171234567', 'walkin_no_email' => '1',
                'check_in' => $in, 'check_out' => date('Y-m-d', strtotime($in . ' +1 day')),
                'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'female']]),
            ])->assertStatus(400)->assertExactJson(['ok' => false, 'message' => $this->message()]);
        $this->assertSame($before, DB::table('bookings')->count());
    }

    /* The date picker gets the number from the setting and greys out later check-ins. */
    public function test_the_hostel_page_greys_out_check_ins_beyond_the_limit(): void
    {
        $this->actingAsCustomer('aronerfe0830@gmail.com')->get(route('customer.hostel-reservation', ['room' => 'h1']))->assertOk()
            ->assertSee('const ADVANCE_DAYS = 3;', false)
            ->assertSee("const off = iso<min || (field==='checkIn' && LAST_CHECKIN!==null && iso>LAST_CHECKIN);", false);
    }
}
