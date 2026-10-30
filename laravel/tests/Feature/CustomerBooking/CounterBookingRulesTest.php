<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin.booking-create (the counter) checks a booking by the same rules as
   customer.booking-submit (BookingRules): real dates, hours HH:MM inside
   7:00 AM - 10:00 PM, at most 366 days or nights. */
class CounterBookingRulesTest extends TestCase
{
    private function create(array $data)
    {
        return $this->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.booking-create'), $data);
    }

    private function walkIn(array $over): array
    {
        return $over + [
            'id_checked' => '1', 'usep_id' => '0', 'agree_no_refund' => '1', 'method' => 'cash',
            'booker_mode' => 'walkin', 'walkin_name' => 'Rules Walk-in', 'walkin_phone' => '09171234567', 'walkin_no_email' => '1',
        ];
    }

    private function venue(string $start, string $end, array $times): array
    {
        return $this->walkIn(['type' => 'venue', 'room' => 'r1', 'event_name' => 'Rules test', 'attendees' => '40',
            'date_start' => $start, 'date_end' => $end, 'times' => json_encode($times)]);
    }

    private function hostel(string $in, string $out): array
    {
        return $this->walkIn(['type' => 'hostel', 'room' => 'h1', 'cash_received' => '1', 'check_in' => $in, 'check_out' => $out,
            'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'female']])]);
    }

    public function test_venue_hours_outside_the_bookable_window_are_refused(): void
    {
        $before = DB::table('bookings')->count();
        $day = now()->addDays(random_int(400, 1400))->toDateString();
        foreach ([['05:00', '09:00'], ['20:00', '23:30'], ['00:00', '23:59'], ['08:00', '25:99']] as [$s, $e]) {
            $this->actingAsStaff()->create($this->venue($day, $day, [$day => ['start' => $s, 'end' => $e]]))
                ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Set valid hours for ' . $day . '.']);
        }
        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_a_date_that_does_not_exist_is_refused(): void
    {
        $this->actingAsStaff()->create($this->venue('2030-02-30', '2030-02-30', ['2030-02-30' => ['start' => '08:00', 'end' => '12:00']]))
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Pick valid reservation dates.']);
        $y = date('Y', strtotime('+1 year'));
        $this->actingAsAdmin()->create($this->hostel($y . '-02-30', $y . '-03-02'))
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Check-out must be after check-in.']);
    }

    public function test_a_range_longer_than_366_days_is_refused(): void
    {
        $before = DB::table('bookings')->count();
        $start = now()->addDays(random_int(400, 1400))->toDateString();
        $this->actingAsStaff()->create($this->venue($start, date('Y-m-d', strtotime($start . ' +366 days')), []))
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'A booking can cover at most 366 days.']);
        $in = date('Y-m-d', strtotime('+1 day'));
        $this->actingAsAdmin()->create($this->hostel($in, date('Y-m-d', strtotime($in . ' +367 days'))))
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'A stay can be at most 366 nights.']);
        $this->assertSame($before, DB::table('bookings')->count());
    }
}
