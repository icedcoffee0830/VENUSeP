<?php

namespace Tests\Feature\CustomerAccount;

use Tests\TestCase;

/* customer/pay — one booking's payment step. */
class PayTest extends TestCase
{
    use BookingRefs;

    public function test_guest_is_sent_to_the_customer_sign_in(): void
    {
        $this->get(route('customer.pay', ['booking' => 'VB-2026-020']))->assertRedirect(route('customer.customer-login'));
    }

    public function test_someone_elses_booking_is_a_plain_404(): void
    {
        $this->actingAsCustomer('customer@gmail.com')
            ->get(route('customer.pay', ['booking' => $this->ref($this->firstBookingOf(2))]))
            ->assertNotFound()->assertSeeText('Not found.')
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /* Overdue: no payment until staff extend the deadline (Aron 2026-11-05) — the office's
       contact line instead, here and on Booking History. */
    public function test_an_overdue_booking_is_closed_and_points_to_the_office(): void
    {
        legacy('offices');
        $id = $this->firstBookingOf(1, ['payment_status' => 'overdue']);
        $contact = office_contact_line(office_of_booking($id));
        $this->actingAsCustomer('customer@gmail.com')
            ->get(route('customer.pay', ['booking' => $this->ref($id)]))
            ->assertOk()
            ->assertSee('Payment overdue. Please contact the office.')
            ->assertSee($contact)
            ->assertSee('Was due')
            ->assertSee('<a class="pay-back" href="' . route('customer.booking-history') . '">', false)
            ->assertSee('Back to My bookings')
            ->assertSee('<a class="pay-back" href="' . route('customer.venues') . '">', false)
            ->assertDontSee('id="payApp"', false)->assertDontSee('function evaluateReceipt', false);
        $this->get(route('customer.booking-history'))->assertOk()
            ->assertSee('Payment overdue. Please contact the office.')->assertSee($contact)
            ->assertDontSee('href="' . route('customer.pay', ['booking' => $this->ref($id)]) . '"', false);
    }

    /* Once staff extend it, it is back to await_gcash with a new deadline: Pay now again. */
    public function test_an_extended_booking_opens_the_payment_with_the_shared_checker(): void
    {
        $id = $this->firstBookingOf(1, ['payment_status' => 'overdue']);
        $was = \Illuminate\Support\Facades\DB::table('bookings')->where('id', $id)->first(['payment_status', 'current_deadline_at']);
        \Illuminate\Support\Facades\DB::table('bookings')->where('id', $id)->update(['payment_status' => 'await_gcash', 'current_deadline_at' => now()->addDays(2)]);
        try {
            $this->actingAsCustomer('customer@gmail.com')
                ->get(route('customer.pay', ['booking' => $this->ref($id), 'sent' => 'manual_review']))
                ->assertOk()
                ->assertSee('<title>VENUSeP | Pay ' . $this->ref($id) . '</title>', false)
                ->assertSee('Pay for your booking')
                ->assertSee('Receipt received — a coordinator will check it')
                ->assertSee('function evaluateReceipt', false)         // legacy gcash-checker.php
                ->assertSee('"ref":"' . $this->ref($id) . '"', false)
                ->assertSee(json_encode(route('customer.payment-submit')), false);
            $this->get(route('customer.booking-history'))->assertOk()
                ->assertSee('href="' . route('customer.pay', ['booking' => $this->ref($id)]) . '"', false);
        } finally {
            \Illuminate\Support\Facades\DB::table('bookings')->where('id', $id)->update((array) $was);
        }
    }

    public function test_a_paid_booking_says_so_and_offers_no_upload(): void
    {
        $id = $this->firstBookingOf(1, ['payment_status' => 'confirmed']);
        $this->actingAsCustomer('customer@gmail.com')
            ->get(route('customer.pay', ['booking' => $this->ref($id)]))
            ->assertOk()->assertSee('Your payment is confirmed. Nothing more to do.')
            ->assertDontSee('id="payApp"', false)->assertDontSee('function evaluateReceipt', false);
    }
}
