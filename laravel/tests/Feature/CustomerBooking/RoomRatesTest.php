<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Room rates keep their centavos (venue-rooms / hostel-rooms includes). The
   admin room forms take a rate to the centavo (step 0.01) and the server charges
   it, but the includes cast it to a whole peso: the booking pages showed less
   than the booking stored, and the room form re-filled the truncated value, so
   the next save of that room silently dropped the centavos. */
class RoomRatesTest extends TestCase
{
    private function withRate(string $table, string $column, int $roomId, string $value, callable $fn): void
    {
        $old = DB::table($table)->where('room_id', $roomId)->value($column);
        DB::table($table)->where('room_id', $roomId)->update([$column => $value]);
        try {
            $fn();
        } finally {
            DB::table($table)->where('room_id', $roomId)->update([$column => $old]);
        }
    }

    public function test_the_venue_room_form_refills_the_rate_with_its_centavos(): void
    {
        $this->withRate('event_room_details', 'fee_per_day', 5, '1500.50', function () {
            $this->actingAsAdmin()->get(route('admin.room-form', ['id' => 'r5']))->assertOk()
                ->assertSee('value="1500.50"', false);
        });
    }

    public function test_the_hostel_room_form_refills_the_rate_with_its_centavos(): void
    {
        $this->withRate('hostel_room_details', 'rate_per_head_per_night', 9, '350.75', function () {
            $this->actingAsAdmin()->get(route('admin.hostel-room-form', ['id' => 'h1']))->assertOk()
                ->assertSee('value="350.75"', false);
        });
    }

    public function test_the_venue_booking_page_prices_with_the_rate_the_server_charges(): void
    {
        $this->withRate('event_room_details', 'fee_per_day', 5, '1500.50', function () {
            $this->actingAsCustomer()->get(route('customer.room-reservation', ['room' => 'r5']))->assertOk()
                ->assertSee('"fee":1500.5,', false);
        });
    }

    public function test_the_hostel_page_prices_with_the_rate_the_server_charges(): void
    {
        $this->withRate('hostel_room_details', 'rate_per_head_per_night', 9, '350.75', function () {
            $this->actingAsCustomer()->get(route('customer.hostel-reservation', ['room' => 'h1']))->assertOk()
                ->assertSee('"rate":350.75,', false);
        });
    }

    public function test_the_landing_page_shows_the_centavos(): void
    {
        $this->withRate('event_room_details', 'fee_per_day', 5, '1500.50', function () {
            $this->get(route('customer.venues'))->assertOk()->assertSee('₱1,500.50 per day', false);
        });
    }

    /* The scripts' peso() drew 1500.5 as "₱1,500.5" (and float sums as "₱1,050.449"):
       it is run here in Node, straight from each booking page. */
    public function test_the_booking_pages_draw_centavos_as_two_digits(): void
    {
        foreach ([['customer.room-reservation', 'r5'], ['customer.hostel-reservation', 'h1']] as [$route, $room]) {
            $html = $this->actingAsCustomer('aronerfe0830@gmail.com')->get(route($route, ['room' => $room]))->assertOk()->getContent();
            $this->assertSame(1, preg_match('/^function peso\(n\)\{.*\}$/m', $html, $m), "$route: no peso()");
            $file = tempnam(sys_get_temp_dir(), 'peso') . '.js';
            file_put_contents($file, $m[0] . "\nconsole.log([1500, 1500.5, 350.15*3, 0.1+0.2, 12345.678].map(peso).join('|'));");
            $out = trim((string) shell_exec('node ' . escapeshellarg($file) . ' 2>&1'));
            @unlink($file);
            $this->assertSame('₱1,500|₱1,500.50|₱1,050.45|₱0.30|₱12,345.68', $out, $route);
        }
    }
}
