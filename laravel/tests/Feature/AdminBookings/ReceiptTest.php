<?php

namespace Tests\Feature\AdminBookings;

use Tests\TestCase;

/* admin/receipt — the staff view of a booking's System Receipt. */
class ReceiptTest extends TestCase
{
    use SeedsBookings;

    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.receipt', ['booking' => 'VB-2026-190']))->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_is_sent_to_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.receipt', ['booking' => 'VB-2026-190']))->assertRedirect(route('admin.admin-login'));
    }

    public function test_no_receipt_or_bad_reference_is_a_plain_404(): void
    {
        [, $ref] = $this->seedVenueBooking('r1');
        $this->actingAsStaff()->get(route('admin.receipt', ['booking' => $ref]))
            ->assertNotFound()->assertSee('Not found.')->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->get(route('admin.receipt', ['booking' => 'nonsense']))->assertNotFound();
    }

    public function test_staff_sees_any_venues_receipt(): void
    {
        // a venue-2 booking, paid (confirmed by the admin), opened by venue-1 staff
        [, $ref] = $this->seedVenueBooking('r5', 'approved', 'await_cash', 'cash');
        $this->actingAsAdmin()->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => 'confirm_payment'])->assertOk();
        $this->flushSession();
        $this->actingAsStaff()->get(route('admin.receipt', ['booking' => $ref]))->assertOk()
            ->assertSee('System Receipt')->assertSee($ref)->assertSee('Amount paid')
            ->assertSee(route('root.receipt-pdf', ['booking' => $ref]), false)
            ->assertSee('class="receipt-page"', false);
    }
}
