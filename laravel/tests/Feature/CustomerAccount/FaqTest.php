<?php

namespace Tests\Feature\CustomerAccount;

use Tests\TestCase;

/* customer/faq — public; two frames (standalone / ?in=app account shell). */
class FaqTest extends TestCase
{
    public function test_a_guest_gets_the_standalone_page_with_live_values(): void
    {
        $this->get(route('customer.faq'))
            ->assertOk()
            ->assertSee('<title>VENUSeP | FAQ</title>', false)
            ->assertSee('Frequently asked questions')
            ->assertSee('Who gets the 20% off?')                       // {discount} filled from pricing.php
            ->assertSee('class="fq-footer"', false)
            ->assertSee('Back to venue booking')
            ->assertSee(route('customer.customer-login'), false)        // the browse-mode nav, signed out
            ->assertSee('refund policy')                                // refunds are OFF in the seed
            ->assertDontSee('class="sidebar', false);
    }

    public function test_in_app_keeps_the_account_shell(): void
    {
        $this->actingAsCustomer('customer@gmail.com')->get(route('customer.faq', ['in' => 'app']))
            ->assertOk()
            ->assertSee('class="app-main"', false)
            ->assertSee(route('customer.booking-history'), false)
            ->assertSee('Browse Venues')                                // the sidebar
            ->assertDontSee('class="fq-footer"', false)
            ->assertDontSee('Back to venue booking');
    }
}
