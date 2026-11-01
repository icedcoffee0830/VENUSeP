<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Bug 2 (HANDOFF §6): a disabled customer is TOLD the account is disabled —
   (a) after typing the right password, (b) on the next request after being
   disabled while signed in. A wrong password keeps the one generic message,
   so the page still does not reveal which emails exist. */
class DisabledCustomerTest extends TestCase
{
    use MakesAccounts;

    private const NOTICE = 'This account has been disabled';

    private function url(): string
    {
        return route('customer.customer-login');
    }

    public function test_correct_password_on_a_disabled_account_shows_the_disabled_notice(): void
    {
        $c = $this->makeAccount('customer', ['active' => 0]);
        $this->post($this->url(), ['email' => $c->email, 'password' => self::PASSWORD])
            ->assertOk()->assertSee(self::NOTICE)->assertSee('venusepAlert(', false)
            ->assertDontSee('Invalid email or password.')
            ->assertSessionMissing('user_id');
    }

    public function test_wrong_password_on_a_disabled_account_keeps_the_generic_message(): void
    {
        $c = $this->makeAccount('customer', ['active' => 0]);
        $this->post($this->url(), ['email' => $c->email, 'password' => 'wrong-one'])
            ->assertOk()->assertSee('Invalid email or password.')->assertDontSee(self::NOTICE);
    }

    public function test_a_customer_disabled_while_signed_in_lands_on_the_login_page_with_the_notice(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer')->get(route('customer.booking-history'))->assertOk();

        DB::table('users')->where('id', $c->id)->update(['is_active' => 0]);   // an admin disables them

        $this->get(route('customer.booking-history'))->assertRedirect($this->url());
        $this->get($this->url())->assertOk()->assertSee(self::NOTICE)->assertSessionMissing('user_id');
        $this->get($this->url())->assertOk()->assertDontSee(self::NOTICE);   // once
    }

    public function test_a_signed_out_customer_gets_no_disabled_notice(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer')->get(route('customer.logout'));
        $this->get($this->url())->assertOk()->assertDontSee(self::NOTICE);
    }
}
