<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer.booking-submit (was customer/booking-submit.php) — the JSON endpoint
   both booking pages post to. Bookings a test creates are deleted again. */
class BookingSubmitTest extends TestCase
{
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
            $dir = getenv('VENUSEP_DOC_ROOT') . '/bookings/' . $id;
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /* a real 1x1 PNG (getimagesize() must accept it; GD is not installed) */
    private function idFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return new UploadedFile($path, 'my-id.png', 'image/png', null, true);
    }

    private function asCustomer(): static
    {
        return $this->actingAsCustomer()->withSession(['_token' => 'test-token']);
    }

    private function venuePost(array $over = []): array
    {
        $day = date('Y-m-d', strtotime('+' . random_int(400, 1400) . ' days'));   /* a far, free date */
        return $over + [
            '_token' => 'test-token', 'type' => 'venue', 'room' => 'r5', 'event_name' => 'Port test event',
            'date_start' => $day, 'date_end' => $day, 'attendees' => '50',
            'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']]),
            'affiliated' => '1', 'method' => 'gcash', 'agree_no_refund' => '1',
            'id_document' => $this->idFile(),
        ];
    }

    private function idFileFrom(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, $bytes);
        return new UploadedFile($path, $name, null, null, true);
    }

    /* The ID is checked BEFORE anything is written: a wrong file used to create
       the booking anyway — the slot held, staff left with a request and no ID. */
    public function test_an_id_that_is_not_an_image_or_pdf_is_refused_and_nothing_is_written(): void
    {
        $before = DB::table('bookings')->count();
        $this->asCustomer()->post(route('customer.booking-submit'), $this->venuePost([
            'id_document' => $this->idFileFrom('id.txt', 'just some text'),
        ]))->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Upload a JPG, PNG, WEBP or PDF.']);
        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_a_renamed_file_posing_as_an_image_is_refused(): void
    {
        $before = DB::table('bookings')->count();
        $this->asCustomer()->post(route('customer.booking-submit'), $this->venuePost([
            'id_document' => $this->idFileFrom('id.png', 'MZ not really a png'),
        ]))->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'That file is not a valid image.']);
        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_an_id_over_8mb_is_refused(): void
    {
        $before = DB::table('bookings')->count();
        $this->asCustomer()->post(route('customer.booking-submit'), $this->venuePost([
            'id_document' => $this->idFileFrom('id.pdf', str_repeat('x', 8 * 1024 * 1024 + 1)),
        ]))->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'That file is larger than 8MB.']);
        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_get_is_refused_with_the_old_json(): void
    {
        $this->get(route('customer.booking-submit'))->assertStatus(405)
            ->assertHeader('Allow', 'POST')->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
    }

    public function test_a_guest_gets_the_401_json(): void
    {
        $this->post(route('customer.booking-submit'), ['type' => 'venue'])->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
    }

    public function test_a_booking_without_an_id_is_refused(): void
    {
        $data = $this->venuePost();
        unset($data['id_document']);
        $this->asCustomer()->post(route('customer.booking-submit'), $data)
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'A valid ID is required to submit a booking.']);
    }

    public function test_bahay_alumni_keeps_the_12_hour_rule(): void
    {
        $today = date('Y-m-d', strtotime('-1 day'));   /* already past: well inside 12 hours */
        $this->asCustomer()->post(route('customer.booking-submit'), $this->venuePost([
            'room' => 'r1', 'date_start' => $today, 'date_end' => $today,
            'times' => json_encode([$today => ['start' => '22:00', 'end' => '23:00']]),
        ]))->assertStatus(409)->assertJson(['ok' => false, 'message' => 'Bookings must start at least 12 hours from now.']);
    }

    public function test_a_venue_booking_is_written_like_the_old_endpoint_did(): void
    {
        $data = $this->venuePost();
        $res = $this->asCustomer()->post(route('customer.booking-submit'), $data)->assertOk()
            ->assertJson(['ok' => true, 'idStored' => true, 'message' => 'Booking submitted.']);
        $id = $res->json('bookingId');
        $this->made[] = $id;
        $this->assertMatchesRegularExpression('/^VB-\d{4}-/', $res->json('reference'));

        $b = DB::selectOne('SELECT * FROM bookings WHERE id = ?', [$id]);
        $this->assertSame('pending', $b->reservation_status);
        $this->assertSame('locked', $b->payment_status);
        $this->assertSame('gcash', $b->payment_method);
        $this->assertEquals(1, $b->is_usep_affiliated);
        $this->assertEquals(0, $b->affiliation_verified);       /* the claim, not the discount */
        $this->assertEquals(0, $b->discount_percent);
        $this->assertEquals(0, $b->refunds_allowed);            /* the switch, snapshotted */
        $this->assertNotNull($b->current_deadline_at);
        $this->assertSame('Port test event', DB::table('venue_booking_details')->where('booking_id', $id)->value('event_name'));
        $slot = DB::selectOne('SELECT slot_date, start_time, end_time FROM venue_booking_slots WHERE booking_id = ?', [$id]);
        $this->assertSame($data['date_start'], $slot->slot_date);
        $this->assertSame('08:00:00', $slot->start_time);
        $doc = DB::selectOne('SELECT document_type, original_filename, file_path FROM booking_documents WHERE booking_id = ?', [$id]);
        $this->assertSame('customer_id', $doc->document_type);
        $this->assertSame('my-id.png', $doc->original_filename);
        $this->assertFileExists(getenv('VENUSEP_DOC_ROOT') . '/' . $doc->file_path);   /* the Laravel storage copy */

        /* the same slot again: sp_add_venue_slot refuses it */
        $this->asCustomer()->post(route('customer.booking-submit'), $this->venuePost([
            'date_start' => $data['date_start'], 'date_end' => $data['date_end'], 'times' => $data['times'],
        ]))->assertStatus(409)->assertJson(['ok' => false]);
    }

    public function test_a_hostel_stay_assigns_one_bed_per_guest_per_night(): void
    {
        $in  = date('Y-m-d', strtotime('+1 day'));
        $out = date('Y-m-d', strtotime('+3 days'));
        $res = $this->asCustomer()->post(route('customer.booking-submit'), [
            '_token' => 'test-token', 'type' => 'hostel', 'room' => 'h1', 'affiliated' => '0', 'agree_no_refund' => '1',
            'check_in' => $in, 'check_out' => $out,
            'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'male'], ['name' => 'Guest Two', 'gender' => 'female']]),
            'id_document' => $this->idFile(),
        ]);
        if ($res->status() === 409) {
            $this->markTestSkipped('Hostel Room 1 is full on those nights in this database.');
        }
        $res->assertOk()->assertJson(['ok' => true]);
        $id = $res->json('bookingId');
        $this->made[] = $id;
        $b = DB::selectOne('SELECT booking_type, payment_method, total_amount FROM bookings WHERE id = ?', [$id]);
        $this->assertSame('hostel', $b->booking_type);
        $this->assertNull($b->payment_method);                  /* pay to use: no method yet */
        $rate = DB::table('hostel_room_details')->where('room_id', 9)->value('rate_per_head_per_night');
        $this->assertEquals($rate * 2 * 2, $b->total_amount);   /* 2 beds x 2 nights */
        $this->assertSame(2, DB::table('hostel_occupants')->where('booking_id', $id)->count());
        $this->assertSame(4, DB::table('bed_reservation_nights')->where('booking_id', $id)->count());
    }

    public function test_demo_mode_records_the_booking_in_the_session_only(): void
    {
        $before = DB::table('bookings')->count();
        DB::update("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'demo_mode'");
        try {
            $this->asCustomer()->post(route('customer.booking-submit'), $this->venuePost())->assertOk()
                ->assertJson(['ok' => true, 'demo' => true, 'idStored' => false,
                              'message' => 'Booking submitted (demo mode — nothing was saved).'])
                ->assertJsonPath('reference', 'VB-' . date('Y') . '-D001')
                ->assertSessionHas('demo');
            /* the demo row names the ROOM, not its code (fixed 2026-10-29) */
            $demoRow = array_values(session('demo')['bookings'])[0];
            $this->assertSame(DB::table('rooms')->where('room_code', $this->venuePost()['room'])->value('name'), $demoRow['roomName']);
        } finally {
            DB::update("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'demo_mode'");
        }
        $this->assertSame($before, DB::table('bookings')->count());
    }
}
