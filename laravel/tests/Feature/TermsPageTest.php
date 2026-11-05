<?php

namespace Tests\Feature;

use Tests\TestCase;

/* /terms — the Terms and Conditions. Public, and linked from nowhere until Aron
   says so (routes/web.php lists where it goes then; drop the last test then). */
class TermsPageTest extends TestCase
{
    public function test_a_guest_can_open_it_and_it_links_the_privacy_notice(): void
    {
        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('<title>VENUSeP | Terms and Conditions</title>', false)
            ->assertSee('Draft for the VENUSeP project. These terms have not yet been reviewed by the USeP offices.')
            ->assertSee('href="' . route('privacy') . '"', false);
    }

    public function test_the_numbers_come_from_the_settings(): void
    {
        config(['venusep.max_days_ahead' => 90, 'venusep.max_unpaid' => 2]);
        $this->get(route('terms'))->assertSee('up to 90 days ahead')->assertSee('up to 2 unpaid upcoming bookings');
    }

    public function test_no_page_links_to_it_yet(): void
    {
        foreach (['customer.faq', 'customer.venues', 'customer.customer-register', 'customer.customer-login', 'privacy'] as $page) {
            $html = $this->get(route($page))->assertOk()->getContent();
            $this->assertStringNotContainsString(route('terms'), $html, "$page links to the terms page");
            $this->assertStringNotContainsString('href="/terms', $html, "$page links to the terms page");
        }
    }
}
