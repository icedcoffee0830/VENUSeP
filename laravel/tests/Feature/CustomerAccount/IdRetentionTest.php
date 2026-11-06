<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\TestCase;

/* Aron, 2026-11-01: an ID is encrypted when stored, opened only through the permission
   check (and logged), and removed once its booking is finished. */
class IdRetentionTest extends TestCase
{
    use SeedsBookings;

    private const PDF = "%PDF-1.4 a test identity document";

    private function storeId(int $bookingId): int
    {
        legacy('bookings', 'documents');
        $file = UploadedFile::fake()->createWithContent('my-id.pdf', self::PDF);
        $err = '';
        $doc = doc_store($bookingId, 'customer_id', $file->getPathname(), 'my-id.pdf', null, $err);
        $this->assertNotNull($doc, $err);
        return $doc;
    }

    private function diskPath(int $doc): string
    {
        return doc_path(DB::table('booking_documents')->where('id', $doc)->value('file_path'));
    }

    public function test_an_id_is_encrypted_on_disk_and_opened_for_staff_with_a_log(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $doc = $this->storeId($id);
        try {
            $this->assertSame(1, (int) DB::table('booking_documents')->where('id', $doc)->value('encrypted'));
            $this->assertStringNotContainsString('%PDF', (string) file_get_contents($this->diskPath($doc)));

            $res = $this->actingAsStaff()->get(route('root.document-view', ['doc' => $doc]))->assertOk();
            $this->assertSame(self::PDF, $res->getContent());
            $this->assertSame(1, DB::table('document_access_log')->where('document_id', $doc)->count());
        } finally {
            @unlink($this->diskPath($doc));
        }
    }

    public function test_a_finished_booking_loses_its_id_and_the_page_says_so(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'paid_cash', 'cash', false, false);
        $doc = $this->storeId($id);
        $path = $this->diskPath($doc);
        DB::table('venue_booking_details')->where('booking_id', $id)->update(['start_date' => now()->subDays(3)->toDateString(), 'end_date' => now()->subDays(2)->toDateString()]);
        DB::table('venue_booking_slots')->where('booking_id', $id)->update(['slot_date' => now()->subDays(2)->toDateString()]);

        $html = $this->actingAsStaff()->get(route('admin.booking-request', ['id' => $ref]))->assertOk()->getContent();
        $this->assertStringContainsString('ID removed on', $html);
        $this->assertSame('completed', $this->booking($id)->reservation_status);
        $this->assertFalse(is_file($path));
        $this->assertNotNull(DB::table('booking_documents')->where('id', $doc)->value('removed_at'));
        $this->get(route('root.document-view', ['doc' => $doc]))->assertNotFound();
    }

    public function test_an_open_booking_keeps_its_id(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $doc = $this->storeId($id);
        try {
            doc_remove_finished_ids();
            $this->assertNull(DB::table('booking_documents')->where('id', $doc)->value('removed_at'));
            $this->assertTrue(is_file($this->diskPath($doc)));
        } finally {
            @unlink($this->diskPath($doc));
        }
    }

    public function test_backups_leave_ids_out(): void
    {
        legacy('auth', 'backup');
        $from = sys_get_temp_dir() . '/venusep-bk-' . uniqid();
        $to = $from . '-copy';
        mkdir($from . '/bookings/1', 0775, true);
        file_put_contents($from . '/bookings/1/customer_id_abc.pdf', 'id');
        file_put_contents($from . '/bookings/1/refund_support_abc.pdf', 'keep');
        backup_copytree($from, $to);
        $this->assertFileDoesNotExist($to . '/bookings/1/customer_id_abc.pdf');
        $this->assertFileExists($to . '/bookings/1/refund_support_abc.pdf');
    }
}
