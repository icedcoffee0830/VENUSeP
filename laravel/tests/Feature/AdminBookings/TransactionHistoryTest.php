<?php

namespace Tests\Feature\AdminBookings;

use Tests\TestCase;

/* admin/transaction-history — every customer's ledger + the "View" modal. */
class TransactionHistoryTest extends TestCase
{
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
            ->assertSee('const rows = [{', false)
            ->assertSee(route('admin.receipt') . '?booking=', false)
            ->assertSee('window.openBookingDetails', false)
            ->assertSee(json_encode(route('root.booking-details') . '?booking='), false)
            // on an admin page the modal's "System Receipt" is the staff receipt page
            ->assertSee('href="' . route('admin.receipt') . '?booking=${encodeURIComponent(d.reference)}">System Receipt', false)
            ->assertSee(asset('assets/js/table-page-size.js'), false);
    }

    public function test_admin_sees_the_ledger(): void
    {
        $this->actingAsAdmin()->get(route('admin.transaction-history'))->assertOk()->assertSee('Back to Dashboard');
    }
}
