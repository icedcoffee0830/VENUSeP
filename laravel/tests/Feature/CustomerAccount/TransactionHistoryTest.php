<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer/transaction-history — the customer's own ledger (Tabulator, rows inlined as JSON). */
class TransactionHistoryTest extends TestCase
{
    use BookingRefs;

    public function test_guest_and_staff_are_sent_to_the_customer_sign_in(): void
    {
        $this->get(route('customer.transaction-history'))->assertRedirect(route('customer.customer-login'));
        $this->actingAsStaff()->get(route('customer.transaction-history'))->assertRedirect(route('customer.customer-login'));
    }

    public function test_customer_gets_their_own_rows_only(): void
    {
        $this->actingAsCustomer('msantos@usep.edu.ph')->get(route('customer.transaction-history'))
            ->assertOk()
            ->assertSee('Transaction History')
            ->assertSee('"bookingId":"' . $this->ref($this->firstBookingOf(3)) . '"', false)
            ->assertDontSee('"bookingId":"' . $this->ref($this->firstBookingOf(2)) . '"', false)
            ->assertSee(json_encode(route('customer.receipt')), false);
    }

    /* The status filters match a row's label exactly, so they must offer the labels
       rows actually carry (payment_statuses / reservation_statuses customer_label),
       not mockup words like "Unpaid" that never match a row. */
    public function test_the_status_filters_offer_the_real_customer_labels(): void
    {
        $this->actingAsCustomer('msantos@usep.edu.ph')->get(route('customer.transaction-history'))
            ->assertOk()
            ->assertDontSee('<option value="Unpaid">', false)
            ->assertSee('<option value="Under review">Under review</option>', false)
            ->assertSee('<option value="Refund - action needed">Refund - action needed</option>', false)
            ->assertSee('<option value="Expired">Expired</option>', false)
            ->assertSee('<option value="Action needed">Action needed</option>', false);
    }

    /* A write-off reads "Closed by the office" to the customer, in the row and the filter. */
    public function test_a_written_off_booking_keeps_the_customer_word(): void
    {
        $this->assertSame('Closed by the office', DB::table('payment_statuses')->where('code', 'written_off')->value('customer_label'));
        $booking = DB::table('bookings')->where('id', $this->firstBookingOf(3))->first();
        DB::table('bookings')->where('id', $booking->id)->update(['payment_status' => 'written_off']);
        try {
            $this->actingAsCustomer('msantos@usep.edu.ph')->get(route('customer.transaction-history'))->assertOk()
                ->assertSee('"paymentStatus":"Closed by the office"', false)
                ->assertSee('<option value="Closed by the office">Closed by the office</option>', false)
                ->assertDontSee('"paymentStatus":"Written off"', false);
        } finally {
            DB::table('bookings')->where('id', $booking->id)->update(['payment_status' => $booking->payment_status]);
        }
    }

    public function test_a_customer_with_no_bookings_gets_an_empty_ledger(): void
    {
        $this->actingAsCustomer('nikkaarena@gmail.com')->get(route('customer.transaction-history'))
            ->assertOk()->assertSee('const rows = [];', false);
    }
}
