<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/* Bug 1 (HANDOFF §6): in counter mode (staff session + ?counter=1) every link in
   the customer top bar that leads to a customer page must keep ?counter=1, or
   one click drops staff out of counter mode onto the customer sign-in page. */
class CounterNavTest extends TestCase
{
    /* Every href inside the customer nav <header>. */
    private function navLinks(string $html): array
    {
        $this->assertSame(1, preg_match('~<header class="cn-nav[^"]*" id="cnNav">(.*?)</header>~s', $html, $m), 'customer nav not found');
        preg_match_all('~<a [^>]*href="([^"]*)"~', $m[1], $links);

        return array_map('html_entity_decode', $links[1]);
    }

    public function test_every_customer_link_in_the_nav_keeps_counter_mode(): void
    {
        $html = $this->actingAsStaff()->get(route('customer.faq') . '?counter=1')->assertOk()->getContent();

        $links = $this->navLinks($html);
        $this->assertNotEmpty($links);
        $customerBase = url('/customer/');
        foreach ($links as $href) {
            if (!str_starts_with($href, $customerBase)) {
                continue;   // in-page anchors, and "Leave counter mode" (the admin dashboard)
            }
            $this->assertStringContainsString('counter=1', $href, "nav link $href drops counter mode");
        }
        /* the logo, specifically (it was the hard-coded one) */
        $this->assertMatchesRegularExpression('~class="cn-logo" href="[^"]*/customer/venues\?counter=1"~', $html);
    }

    public function test_the_logo_has_no_counter_flag_for_a_customer_or_a_guest(): void
    {
        $html = $this->get(route('customer.faq') . '?counter=1')->assertOk()->getContent();   // a guest asking for counter mode gets nothing
        $this->assertMatchesRegularExpression('~class="cn-logo" href="[^"]*/customer/venues"~', $html);
        $this->assertStringNotContainsString('counter=1', implode(' ', $this->navLinks($html)));
    }
}
