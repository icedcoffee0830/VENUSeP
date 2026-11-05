<?php

namespace Tests\Feature;

use Tests\TestCase;

/* /terms — the Terms and Conditions: public, linked from the two sign-up checkboxes.
   The Google checkbox's link is checked in Auth/CustomerLoginTest (it needs a sign-up session). */
class TermsPageTest extends TestCase
{
    public function test_a_guest_can_open_it_and_it_names_no_privacy_page(): void
    {
        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('<title>VENUSeP | Terms and Conditions</title>', false)
            ->assertSee('Draft for the VENUSeP project. These terms have not yet been reviewed by the USeP offices.')
            ->assertDontSee(route('privacy'), false)->assertDontSee('Privacy');
    }

    public function test_the_receipts_line_uses_the_one_name(): void
    {
        $this->get(route('terms'))
            ->assertSee('You will find it in My bookings. It is not an Official Receipt.')->assertDontSee('System Receipt');
    }

    public function test_overdue_says_the_office_extends_the_deadline_and_online_payment_comes_back(): void
    {
        $this->get(route('terms'))
            ->assertSee('It cannot be paid online until the office extends the deadline. Once they do, you can pay online again.');
    }

    public function test_the_numbers_come_from_the_settings(): void
    {
        config(['venusep.max_days_ahead' => 90, 'venusep.max_unpaid' => 2]);
        $this->get(route('terms'))->assertSee('up to 90 days ahead')->assertSee('up to 2 unpaid upcoming bookings');
    }

    public function test_the_sign_up_checkbox_links_it_in_a_new_tab(): void
    {
        $this->get(route('customer.customer-register'))->assertOk()
            ->assertSee('I agree to the <a class="auth-link" href="' . route('terms') . '" target="_blank" rel="noopener">Terms and Conditions</a>', false);
    }
}
