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

    public function test_an_overdue_booking_opens_the_payment_with_the_shared_checker(): void
    {
        $id = $this->firstBookingOf(1, ['payment_status' => 'overdue']);
        $this->actingAsCustomer('customer@gmail.com')
            ->get(route('customer.pay', ['booking' => $this->ref($id), 'sent' => 'manual_review']))
            ->assertOk()
            ->assertSee('<title>VENUSeP | Pay ' . $this->ref($id) . '</title>', false)
            ->assertSee('Pay for your booking')
            ->assertSee('This payment is overdue')
            ->assertSee('Receipt received — a coordinator will check it')
            ->assertSee('function evaluateReceipt', false)         // legacy gcash-checker.php
            ->assertSee('"ref":"' . $this->ref($id) . '"', false)
            ->assertSee(json_encode(route('customer.payment-submit')), false);
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
