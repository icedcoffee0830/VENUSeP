<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/* customer/payment-submit — the JSON endpoint pay.blade.php posts to. Writes
   are undone at the end of each test (booking 20: customer@gmail.com,
   overdue, ₱9,000, USeP Venues — GCash 09183345566). */
class PaymentSubmitTest extends TestCase
{
    use BookingRefs;

    private const BOOKING = 20;

    private function asCustomerWithToken(): static
    {
        return $this->actingAsCustomer('customer@gmail.com')->withSession(['_token' => 'test-token']);
    }

    /* A real (tiny) PNG — getimagesize() must accept it; random tail = a fresh sha256. */
    private function receiptPng(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent('receipt.png', $png . random_bytes(16));
    }

    public function test_get_is_refused_with_405_json(): void
    {
        $this->get(route('customer.payment-submit'))
            ->assertStatus(405)->assertHeader('Allow', 'POST')
            ->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
    }

    public function test_a_guest_gets_the_401_json(): void
    {
        $this->post(route('customer.payment-submit'), ['booking' => 'VB-2026-020', 'method' => 'cash'])
            ->assertStatus(401)->assertExactJson(['ok' => false, 'message' => 'Your session has ended. Log in again.']);
    }

    public function test_someone_elses_booking_is_not_found(): void
    {
        $this->asCustomerWithToken()
            ->post(route('customer.payment-submit'), ['_token' => 'test-token', 'booking' => $this->ref(13), 'method' => 'cash'])
            ->assertStatus(404)->assertJson(['ok' => false, 'message' => 'That booking was not found.']);
    }

    public function test_an_unreadable_reference_is_refused_without_storing_anything(): void
    {
        $before = DB::table('gcash_receipts')->count();
        $this->asCustomerWithToken()
            ->post(route('customer.payment-submit'), [
                '_token' => 'test-token', 'booking' => $this->ref(self::BOOKING), 'method' => 'gcash',
                'receipt' => $this->receiptPng(), 'ref' => '12345', 'amount_centavos' => '900000',
            ])
            ->assertStatus(400)
            ->assertExactJson(['ok' => false, 'message' => 'The reference number could not be read from that receipt. Try a clearer screenshot.']);
        $this->assertSame($before, DB::table('gcash_receipts')->count());
    }

    public function test_choosing_cash_records_the_method(): void
    {
        $this->asCustomerWithToken()
            ->post(route('customer.payment-submit'), ['_token' => 'test-token', 'booking' => $this->ref(self::BOOKING), 'method' => 'cash'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'status' => 'await_cash', 'message' => 'Pay at the venue office — staff will record it.']);
        // overdue stays overdue (the old UPDATE skips it); the method switches
        $row = DB::table('bookings')->where('id', self::BOOKING)->first();
        $this->assertSame('cash', $row->payment_method);
        $this->assertSame('overdue', $row->payment_status);

        DB::table('bookings')->where('id', self::BOOKING)->update(['payment_method' => 'gcash']);
    }

    public function test_a_matching_gcash_receipt_is_accepted_and_goes_to_staff(): void
    {
        $refNo = '9' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        try {
            $this->asCustomerWithToken()
                ->post(route('customer.payment-submit'), [
                    '_token' => 'test-token', 'booking' => $this->ref(self::BOOKING), 'method' => 'gcash',
                    'receipt' => $this->receiptPng(), 'ref' => $refNo, 'receiver_name' => 'RA***N V.',
                    'receiver_number' => '+63 918 334 5566', 'amount_centavos' => '900000',
                    'receipt_datetime' => 'Oct 28, 2026 10:15 AM', 'confidence' => '91', 'flags' => '[]',
                    'text_excerpt' => 'GCash Sent via GCash',
                ])
                ->assertOk()
                ->assertExactJson([
                    'ok' => true, 'verdict' => 'accepted', 'flags' => [], 'status' => 'under_review',
                    'message' => 'Receipt received and checked. Staff will confirm it shortly.',
                    'attemptsUsed' => 1, 'attemptsAllowed' => 5, 'limit' => false,
                ]);

            $receipt = DB::table('gcash_receipts')->where('booking_id', self::BOOKING)->first();
            $this->assertSame('accepted', $receipt->verdict);
            $this->assertSame($refNo, $receipt->reference_number);
            $this->assertNotNull($receipt->payment_id);
            $this->assertSame('under_review', DB::table('bookings')->where('id', self::BOOKING)->value('payment_status'));
            // stored in the Laravel storage copy of the private folder, never the real one
            $this->assertFileExists(storage_path('app/venusep-private/' . $receipt->file_path));
        } finally {
            $this->undoGcashUploads();
        }
    }

    /* A GCash upload with these OCR claims (the matching account and amount by default). */
    private function postGcash(array $claims = []): TestResponse
    {
        return $this->asCustomerWithToken()->post(route('customer.payment-submit'), $claims + [
            '_token' => 'test-token', 'booking' => $this->ref(self::BOOKING), 'method' => 'gcash',
            'receipt' => $this->receiptPng(),
            'ref' => '9' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'receiver_name' => 'RA***N V.', 'receiver_number' => '+63 918 334 5566', 'amount_centavos' => '900000',
            'receipt_datetime' => 'Oct 28, 2026 10:15 AM', 'confidence' => '91', 'flags' => '[]',
        ]);
    }

    private function undoGcashUploads(): void
    {
        foreach (DB::table('gcash_receipts')->where('booking_id', self::BOOKING)->get() as $r) {
            @unlink(storage_path('app/venusep-private/' . $r->file_path));
        }
        DB::table('gcash_receipts')->where('booking_id', self::BOOKING)->delete();
        DB::table('payments')->where('booking_id', self::BOOKING)->where('payment_record_status', 'under_review')->delete();
        DB::table('bookings')->where('id', self::BOOKING)->update(['payment_status' => 'overdue', 'payment_method' => 'gcash']);
    }

    /* The browser sends whatever date text its OCR matched; PHP cannot parse every
       shape the browser can (or an OCR typo like "Febuary"). That must be stored as
       "no date", not as strtotime(false) = 1 Jan 1970. */
    public function test_an_unparseable_receipt_date_is_stored_as_no_date(): void
    {
        try {
            $this->postGcash(['receipt_datetime' => 'Febuary 28, 2026 4:32 PM'])->assertOk();
            $this->assertNull(DB::table('gcash_receipts')->where('booking_id', self::BOOKING)->value('receipt_datetime'));
        } finally {
            $this->undoGcashUploads();
        }
    }
}
