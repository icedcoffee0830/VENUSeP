<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* One upload limit for every stored document (Aron, 2026-10-30): 7 MB, refused
   with one clear message. doc_validate() in legacy documents.php is the single
   gate — the ID (booking-submit, see BookingSubmitTest), the GCash receipt
   (payment-submit) and refund documents (refund-submit) all go through it. */
class UploadLimitTest extends TestCase
{
    use BookingRefs;

    private const MESSAGE = 'This file is over 7 MB. Choose a smaller photo or a screenshot.';

    private function fileOf(string $name, int $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, str_repeat('x', $bytes));
    }

    public function test_doc_validate_takes_exactly_7mb_and_refuses_one_byte_more(): void
    {
        legacy('documents');
        $ok = $this->fileOf('id.pdf', 7 * 1024 * 1024);
        $err = '';
        $this->assertSame('pdf', doc_validate($ok->getPathname(), 'id.pdf', $err));

        $big = $this->fileOf('id.pdf', 7 * 1024 * 1024 + 1);
        $this->assertNull(doc_validate($big->getPathname(), 'id.pdf', $err));
        $this->assertSame(self::MESSAGE, $err);
    }

    public function test_a_gcash_receipt_over_7mb_is_refused_and_not_counted(): void
    {
        $before = DB::table('gcash_receipts')->count();
        $this->actingAsCustomer('customer@gmail.com')->withSession(['_token' => 't'])
            ->post(route('customer.payment-submit'), [
                '_token' => 't', 'booking' => $this->ref(20), 'method' => 'gcash',
                'receipt' => $this->fileOf('receipt.png', 7 * 1024 * 1024 + 1),
                'ref' => '9123456789012', 'amount_centavos' => '900000',
            ])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => self::MESSAGE]);
        $this->assertSame($before, DB::table('gcash_receipts')->count());
    }

    public function test_a_refund_document_over_7mb_files_nothing(): void
    {
        $this->makeBooking36Refundable();
        try {
            $before = DB::table('refunds')->count();
            $this->actingAsCustomer('rafael.lim@gmail.com')->withSession(['_token' => 't'])
                ->post(route('customer.refund-submit'), [
                    '_token' => 't', 'booking' => $this->ref(36), 'action' => 'file',
                    'reason_category' => 'schedule_conflict', 'reason' => 'Our event moved to a different month entirely.',
                    'refund_to' => '0917 123 4567', 'or_pending' => '1',
                    'support' => [$this->fileOf('gcash.pdf', 7 * 1024 * 1024 + 1)],
                ])
                ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => '"gcash.pdf": ' . self::MESSAGE . ' Nothing was filed.']);
            $this->assertSame($before, DB::table('refunds')->count());
        } finally {
            $this->restoreBooking36();
        }
    }

    public function test_the_pay_page_states_the_7mb_limit(): void
    {
        $this->actingAsCustomer('customer@gmail.com')->get(route('customer.pay', ['booking' => $this->ref(20)]))
            ->assertOk()->assertSee('PNG or JPG, up to 7 MB', false);
    }

    public function test_the_refund_page_states_the_7mb_limit(): void
    {
        $this->makeBooking36Refundable();
        try {
            $this->actingAsCustomer('rafael.lim@gmail.com')->get(route('customer.refund-request', ['booking' => $this->ref(36)]))
                ->assertOk()->assertSee('Photos or PDFs, up to 7 MB each.', false);
        } finally {
            $this->restoreBooking36();
        }
    }
}
