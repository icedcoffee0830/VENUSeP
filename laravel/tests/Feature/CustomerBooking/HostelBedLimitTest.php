<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* One named guest per free bed (Aron 2026-11-05). The page stops at the beds free for the
   dates (flow booking-dates); the server refuses more, online and at the counter, and
   leaves no half-made stay behind. */
class HostelBedLimitTest extends TestCase
{
    private const GUEST = 'Over Limit Guest';

    /* Only needed when the rule is broken and a stay gets through: the counter's is paid
       at once, and its payment and receipt hold the booking row in place. */
    protected function tearDown(): void
    {
        foreach (DB::table('hostel_occupants')->where('full_name', self::GUEST)->pluck('booking_id')->unique() as $id) {
            DB::delete('DELETE FROM system_receipts WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM payments WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
        }
        DB::delete("DELETE FROM customers WHERE full_name = 'Bed Limit Walk-in' AND user_id IS NULL");
        parent::tearDown();
    }

    private function idFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return new UploadedFile($path, 'my-id.png', 'image/png', null, true);
    }

    /* One guest more than the room has beds, on far, free dates. */
    private function stay(): array
    {
        $beds = (int) DB::selectOne(
            "SELECT COUNT(*) AS n FROM hostel_beds hb JOIN rooms r ON r.id = hb.room_id WHERE r.room_code = 'h1' AND hb.is_active = 1"
        )->n;
        $in = date('Y-m-d', strtotime('+' . random_int(400, 4000) . ' days'));
        return ['type' => 'hostel', 'room' => 'h1', 'check_in' => $in, 'check_out' => date('Y-m-d', strtotime($in . ' +1 day')),
            'occupants' => json_encode(array_fill(0, $beds + 1, ['name' => self::GUEST, 'gender' => 'female']))];
    }

    public function test_online_more_guests_than_free_beds_are_refused_and_nothing_stays(): void
    {
        $before = DB::table('bookings')->count();
        $this->actingAsCustomer('aronerfe0830@gmail.com')->withSession(['_token' => 'test-token'])
            ->post(route('customer.booking-submit'), $this->stay() + [
                '_token' => 'test-token', 'affiliated' => '0', 'agree_no_refund' => '1', 'id_document' => $this->idFile(),
            ])->assertStatus(409)->assertExactJson(['ok' => false,
                'message' => 'There are not enough free beds for every night of that stay. Please pick different dates or fewer beds.']);
        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_the_counter_refuses_more_guests_than_free_beds_too(): void
    {
        $before = DB::table('bookings')->count();
        $this->actingAsAdmin()->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.booking-create'), $this->stay() + [
                'id_checked' => '1', 'usep_id' => '0', 'agree_no_refund' => '1', 'cash_received' => '1',
                'booker_mode' => 'walkin', 'walkin_name' => 'Bed Limit Walk-in', 'walkin_phone' => '09171234567', 'walkin_no_email' => '1',
            ])->assertStatus(409)->assertJson(['ok' => false]);
        $this->assertSame($before, DB::table('bookings')->count());
    }
}
