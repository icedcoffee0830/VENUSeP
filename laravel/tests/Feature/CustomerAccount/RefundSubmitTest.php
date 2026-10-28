<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer/refund-submit — the JSON endpoint refund-request and booking-history post to. */
class RefundSubmitTest extends TestCase
{
    use BookingRefs;

    private function asRafael(): static
    {
        return $this->actingAsCustomer('rafael.lim@gmail.com')->withSession(['_token' => 't']);
    }

    private function filing(array $over = []): array
    {
        return $over + [
            '_token' => 't', 'booking' => $this->ref(36), 'action' => 'file',
            'reason_category' => 'schedule_conflict', 'reason' => 'Our event moved to a different month entirely.',
            'refund_to' => '0917 123 4567', 'or_pending' => '1',
        ];
    }

    public function test_get_guest_and_stale_token_get_the_old_json(): void
    {
        $this->get(route('customer.refund-submit'))->assertStatus(405)->assertHeader('Allow', 'POST')
            ->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
        $this->post(route('customer.refund-submit'), ['booking' => 'VB-2026-036', 'action' => 'withdraw'])
            ->assertStatus(401)->assertExactJson(['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        $this->asRafael()->post(route('customer.refund-submit'), ['_token' => 'old', 'booking' => 'VB-2026-036', 'action' => 'withdraw'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
    }

    public function test_validation_failures(): void
    {
        $this->asRafael()->post(route('customer.refund-submit'), $this->filing(['action' => 'cancel']))
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Invalid request.']);
        $this->post(route('customer.refund-submit'), $this->filing(['reason' => '   ']))
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Tell us why you are requesting a refund.']);
        // the event is past: the same rule the history page asks
        $this->post(route('customer.refund-submit'), $this->filing())
            ->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'This booking cannot be refunded because the event has already taken place.']);
        $this->post(route('customer.refund-submit'), $this->filing(['action' => 'withdraw']))
            ->assertStatus(409)->assertExactJson(['ok' => false, 'message' => 'There is no open refund request to withdraw.']);
    }

    public function test_file_then_withdraw(): void
    {
        $this->makeBooking36Refundable();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        try {
            $res = $this->asRafael()->post(route('customer.refund-submit'), $this->filing([
                'support' => [UploadedFile::fake()->createWithContent('system-receipt.png', $png)],
            ]))->assertOk()->assertJson([
                'ok' => true, 'status' => 'requested', 'documents' => ['stored' => 1, 'failed' => 0],
                'message' => 'Refund request filed. Your booking stays live until it is decided.',
            ]);
            $refund = DB::table('refunds')->where('id', $res->json('refundId'))->first();
            $this->assertSame('requested', $refund->refund_status);
            $this->assertSame('schedule_conflict', $refund->reason_category);
            $this->assertSame(1, (int) $refund->official_receipt_pending);
            $this->assertSame('refund_requested', DB::table('bookings')->where('id', 36)->value('payment_status'));

            // a second filing while one is open
            $this->post(route('customer.refund-submit'), $this->filing())
                ->assertStatus(409)->assertJson(['message' => 'You already have an open refund request for this booking.']);

            $this->post(route('customer.refund-submit'), ['_token' => 't', 'booking' => $this->ref(36), 'action' => 'withdraw'])
                ->assertOk()->assertExactJson(['ok' => true, 'status' => 'withdrawn', 'message' => 'Request withdrawn. Your booking is unchanged.']);
            $this->assertSame('withdrawn', DB::table('refunds')->where('id', $refund->id)->value('refund_status'));
            $this->assertSame('confirmed', DB::table('bookings')->where('id', 36)->value('payment_status'));
        } finally {
            foreach (DB::table('booking_documents')->where('booking_id', 36)->where('document_type', 'refund_support')->pluck('file_path') as $p) {
                @unlink(storage_path('app/venusep-private/' . $p));
            }
            $this->restoreBooking36();
        }
    }
}
