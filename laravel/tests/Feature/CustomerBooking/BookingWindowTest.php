<?php

namespace Tests\Feature\CustomerBooking;

use App\Http\Controllers\Customer\BookingRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* THE BOOKING WINDOW (Aron, 2026-11-05): 183 days ahead, every office, fixed. No booked date
   past it: greyed out on both booking pages, refused online (booking-submit) and at the
   counter (booking-create). phpunit.xml widens it so other tests can use far, free dates;
   these tests put the real number back. */
class BookingWindowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['venusep.max_days_ahead' => 183]);
    }

    private function day(int $ahead): string
    {
        return date('Y-m-d', strtotime('+' . $ahead . ' days'));
    }

    private function refusal(): array
    {
        return ['ok' => false, 'message' => 'Bookings can be made at most 183 days ahead. Pick dates on or before ' . date('M j, Y', strtotime('+183 days')) . '.'];
    }

    private function idFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return new UploadedFile($path, 'my-id.png', 'image/png', null, true);
    }

    private function submit(array $data)
    {
        return $this->actingAsCustomer('aronerfe0830@gmail.com')->withSession(['_token' => 'test-token'])
            ->post(route('customer.booking-submit'), $data + [
                '_token' => 'test-token', 'affiliated' => '0', 'method' => 'gcash', 'agree_no_refund' => '1', 'id_document' => $this->idFile(),
            ]);
    }

    private function counter(array $data)
    {
        return $this->actingAsAdmin()->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.booking-create'), $data + [
                'id_checked' => '1', 'usep_id' => '0', 'agree_no_refund' => '1', 'method' => 'cash', 'cash_received' => '1',
                'booker_mode' => 'walkin', 'walkin_name' => 'Window Walk-in', 'walkin_phone' => '09171234567', 'walkin_no_email' => '1',
            ]);
    }

    /* Inside the window on the first day, past it on the last: only the last day decides. */
    private function venue(): array
    {
        $first = $this->day(183);
        $last = $this->day(184);
        return ['type' => 'venue', 'room' => 'r5', 'event_name' => 'Window test', 'attendees' => '50', 'date_start' => $first, 'date_end' => $last,
            'times' => json_encode([$first => ['start' => '08:00', 'end' => '12:00'], $last => ['start' => '08:00', 'end' => '12:00']])];
    }

    private function hostel(): array
    {
        return ['type' => 'hostel', 'room' => 'h1', 'check_in' => $this->day(183), 'check_out' => $this->day(184),
            'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'female']])];
    }

    public function test_the_window_is_183_days_unless_a_test_widens_it(): void
    {
        $this->assertStringContainsString("env('VENUSEP_MAX_DAYS_AHEAD', 183)", file_get_contents(config_path('venusep.php')));
    }

    public function test_day_183_is_the_last_bookable_day(): void
    {
        $this->assertSame($this->day(183), BookingRules::lastBookableDay());
        $this->assertNull(BookingRules::tooFarAhead($this->day(183)));
        $this->assertSame($this->refusal()['message'], BookingRules::tooFarAhead($this->day(184)));
    }

    public function test_online_a_booking_past_the_window_is_refused_at_both_kinds_of_office(): void
    {
        $before = DB::table('bookings')->count();
        $this->submit($this->venue())->assertStatus(409)->assertExactJson($this->refusal());
        $this->submit($this->hostel())->assertStatus(400)->assertExactJson($this->refusal());
        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_the_counter_refuses_a_booking_past_the_window_too(): void
    {
        $before = DB::table('bookings')->count();
        $this->counter($this->venue())->assertStatus(409)->assertExactJson($this->refusal());
        $this->counter($this->hostel())->assertStatus(400)->assertExactJson($this->refusal());
        $this->assertSame($before, DB::table('bookings')->count());
    }

    /* The pickers get the last day from the server and grey out everything after it. */
    public function test_both_booking_pages_are_given_the_last_bookable_day(): void
    {
        $line = 'const LAST_DAY = "' . $this->day(183) . '";';
        $this->actingAsCustomer('aronerfe0830@gmail.com');
        $this->get(route('customer.hostel-reservation', ['room' => 'h1']))->assertOk()->assertSee($line, false);
        $this->get(route('customer.room-reservation', ['room' => 'r5']))->assertOk()->assertSee($line, false);
    }
}
