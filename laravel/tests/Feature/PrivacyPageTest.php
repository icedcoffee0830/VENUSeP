<?php

namespace Tests\Feature;

use Tests\TestCase;

/* /privacy — the privacy notice. Public, and linked from nowhere until Aron
   says so (routes/web.php lists where it goes then; drop the last test then). */
class PrivacyPageTest extends TestCase
{
    public function test_a_guest_can_open_it(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('<title>VENUSeP | Privacy notice</title>', false)
            ->assertSee('Draft for the VENUSeP project. This notice has not yet been reviewed by the USeP University Data Privacy Office.')
            ->assertSee('Atty. Edward Caesar J. Bedro')
            ->assertSee('+6382 227 8192')
            ->assertSee('https://usep.edu.ph/data-privacy-manual-and-forms/', false);
    }

    public function test_no_page_links_to_it_yet(): void
    {
        foreach (['customer.faq', 'customer.venues', 'customer.customer-register', 'customer.customer-login'] as $page) {
            $html = $this->get(route($page))->assertOk()->getContent();
            $this->assertStringNotContainsString(route('privacy'), $html, "$page links to the privacy page");
            $this->assertStringNotContainsString('href="/privacy', $html, "$page links to the privacy page");
        }
    }
}
