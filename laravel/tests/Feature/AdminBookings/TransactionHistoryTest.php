<?php

namespace Tests\Feature\AdminBookings;

use App\Support\Status;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/transaction-history — every customer's ledger + the "View" modal. */
class TransactionHistoryTest extends TestCase
{
    use SeedsBookings;

    private int $lastBooking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastBooking = (int) DB::table('bookings')->max('id');
    }

    protected function tearDown(): void
    {
        DB::table('bookings')->where('id', '>', $this->lastBooking)->delete();
        parent::tearDown();
    }

    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.transaction-history'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_is_sent_to_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.transaction-history'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_sees_the_ledger_and_the_modal_with_new_urls(): void
    {
        $this->actingAsStaff()->get(route('admin.transaction-history'))->assertOk()
            ->assertSee('Transaction History')
            ->assertSee('transactionRowsJson: [{', false)
            ->assertSee('receiptUrl: ' . json_encode(route('admin.receipt')), false)
            ->assertSee('window.openBookingDetails', false)
            ->assertSee(json_encode(route('root.booking-details') . '?booking='), false)
            // on an admin page the modal's "Transaction Receipt" is the staff receipt page
            ->assertSee('bdmReceiptUrl: ' . json_encode(route('admin.receipt')), false)
            ->assertSee('href="${bookingDetailsModal.bdmReceiptUrl}?booking=${encodeURIComponent(d.reference)}">Transaction Receipt', false)
            ->assertSee(asset('assets/js/table-page-size.js'), false);
    }

    public function test_admin_sees_the_ledger(): void
    {
        $this->actingAsAdmin()->get(route('admin.transaction-history'))->assertOk()->assertSee('Back to Dashboard');
    }

    /* The ledger's rows, read back out of the page, and the options of one filter. */
    private function rows($page): array
    {
        preg_match('/transactionRowsJson: (\[.*\]),/', $page->getContent(), $m);
        return json_decode($m[1], true);
    }

    private function filterOptions($page, string $select): array
    {
        preg_match('/<select id="' . $select . '">(.*?)<\/select>/s', $page->getContent(), $m);
        preg_match_all('/<option value="([^"]+)"/', $m[1], $o);
        return array_map('html_entity_decode', $o[1]);
    }

    /* Staff read staff words on every status (the search and CSV read the same row fields). */
    public function test_the_rows_carry_the_staff_words_and_a_write_off_reads_written_off(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'completed', 'written_off');
        [, $ref2] = $this->seedVenueBooking('r1', 'pending', 'confirmed');
        $rows = array_column($this->rows($this->actingAsAdmin()->get(route('admin.transaction-history'))), null, 'bookingId');
        $this->assertSame(['Written off', 'Completed'], [$rows[$ref]['paymentStatus'], $rows[$ref]['bookingStatus']]);
        $this->assertSame(['Payment confirmed', 'Reservation pending review'], [$rows[$ref2]['paymentStatus'], $rows[$ref2]['bookingStatus']]);
    }

    public function test_the_filters_offer_exactly_the_words_the_rows_carry(): void
    {
        $this->seedVenueBooking('r1', 'completed', 'written_off');
        $page = $this->actingAsAdmin()->get(route('admin.transaction-history'));
        $rows = $this->rows($page);
        $this->assertContains('Written off', $this->filterOptions($page, 'payment-status-filter'));
        $this->assertEqualsCanonicalizing(array_unique(array_column($rows, 'paymentStatus')), $this->filterOptions($page, 'payment-status-filter'));
        $this->assertEqualsCanonicalizing(array_unique(array_column($rows, 'bookingStatus')), $this->filterOptions($page, 'booking-status-filter'));
    }

    /* A status keeps its colour in staff words; a write-off is the grey finished chip. */
    public function test_every_staff_word_has_the_chip_of_its_customer_word(): void
    {
        foreach (['payment_statuses', 'reservation_statuses'] as $table) {
            foreach (DB::select("SELECT staff_label, customer_label FROM {$table}") as $s) {
                $this->assertSame(Status::chipClass($s->customer_label), Status::chipClass($s->staff_label), $s->staff_label);
            }
        }
        $this->assertSame(Status::chipClass('Paid'), Status::chipClass('Paid at the front desk'));
        $this->assertSame('vz-chip vz-chip-grey', Status::chipClass('Written off'));
    }
}
