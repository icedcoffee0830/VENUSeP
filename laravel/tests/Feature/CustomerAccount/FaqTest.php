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

    /* Counter mode (staff + ?counter=1): the page's own links (back link, help
       button, footer) keep ?counter=1, and the customer-only ones (bookings,
       calendar, transactions) are not offered — they end at the customer sign-in. */
    public function test_counter_mode_keeps_counter_links_and_hides_customer_only_ones(): void
    {
        $html = $this->actingAsStaff()->get(route('customer.faq') . '?counter=1')->assertOk()->getContent();
        $page = substr($html, strpos($html, '<main>'));
        $page = preg_replace('~<section class="fq-section".*?</section>~s', '', $page);   // the FAQ answers themselves
        preg_match_all('~<a [^>]*href="([^"]*)"~', $page, $m);
        $customer = array_filter(array_map('html_entity_decode', $m[1]), fn ($h) => str_starts_with($h, url('/customer/')));
        $this->assertCount(3, $customer);   // back link, Browse venues, Hostel beds
        foreach ($customer as $href) {
            $this->assertStringContainsString('counter=1', $href, "FAQ link $href drops counter mode");
        }
        $this->assertStringNotContainsString('Open my bookings', $page);
        $this->assertStringNotContainsString('Transaction history', $page);
        $this->assertStringNotContainsString('Availability calendar', $page);
    }

    public function test_outside_counter_mode_the_customer_links_stay(): void
    {
        $this->actingAsCustomer('customer@gmail.com')->get(route('customer.faq'))
            ->assertOk()
            ->assertSee('Open my bookings')
            ->assertSee('href="' . route('customer.calendar') . '"', false)
            ->assertSee('href="' . route('customer.transaction-history') . '"', false)
            ->assertSee('href="' . route('customer.venues') . '#venue-listings"', false);
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
