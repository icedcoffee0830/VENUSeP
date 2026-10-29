<?php

namespace Tests\Feature\CustomerAccount;

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
            ->assertSee('My Transactions')
            ->assertSee('"bookingId":"' . $this->ref($this->firstBookingOf(3)) . '"', false)
            ->assertDontSee('"bookingId":"' . $this->ref($this->firstBookingOf(2)) . '"', false)
            ->assertSee(json_encode(route('customer.receipt')), false);
    }

    public function test_a_customer_with_no_bookings_gets_an_empty_ledger(): void
    {
        $this->actingAsCustomer('nikkaarena@gmail.com')->get(route('customer.transaction-history'))
            ->assertOk()->assertSee('const rows = [];', false);
    }
}
