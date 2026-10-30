<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\SignInThrottle;
use Tests\TestCase;

/* Wrong-password limit on both sign-in pages (SignInThrottle): 5 wrong = a lock,
   each lock longer (10s, 30s, ...), the right password is refused while locked,
   a successful sign-in starts over. An email with no account locks the same
   way, so the lock reveals nothing about which emails exist. */
class SignInLimitTest extends TestCase
{
    use MakesAccounts;

    private const LOCKED = 'Too many wrong passwords. Try again in';

    private function customerTry(string $email, string $password)
    {
        return $this->post(route('customer.customer-login'), ['email' => $email, 'password' => $password]);
    }

    private function staffTry(string $email, string $password)
    {
        return $this->post(route('admin.admin-login'), ['email' => $email, 'password' => $password, 'department' => 1]);
    }

    public function test_customer_five_wrong_passwords_lock_then_the_ladder_continues(): void
    {
        $c = $this->makeAccount('customer');
        for ($i = 1; $i <= 4; $i++) {
            $this->customerTry($c->email, 'wrong-password')->assertSee('Invalid email or password.');
        }
        $this->customerTry($c->email, 'wrong-password')->assertSee(self::LOCKED . ' 10 seconds.');
        $this->customerTry($c->email, self::PASSWORD)->assertSee(self::LOCKED)->assertSessionMissing('user_id');

        $this->travel(11)->seconds();
        for ($i = 1; $i <= 4; $i++) {
            $this->customerTry($c->email, 'wrong-password')->assertSee('Invalid email or password.');
        }
        $this->customerTry($c->email, 'wrong-password')->assertSee(self::LOCKED . ' 30 seconds.');

        $this->travel(31)->seconds();
        $this->customerTry($c->email, self::PASSWORD)->assertRedirect()->assertSessionHas('user_id', $c->id);
    }

    public function test_an_unknown_email_locks_exactly_like_a_real_one(): void
    {
        $nobody = 'lvtest-nobody-' . bin2hex(random_bytes(4)) . '@test.local';
        for ($i = 1; $i <= 4; $i++) {
            $this->customerTry($nobody, 'wrong-password')->assertSee('Invalid email or password.');
        }
        $this->customerTry($nobody, 'wrong-password')->assertSee(self::LOCKED . ' 10 seconds.');
    }

    public function test_a_successful_sign_in_starts_the_count_over(): void
    {
        $c = $this->makeAccount('customer');
        for ($i = 1; $i <= 4; $i++) {
            $this->customerTry($c->email, 'wrong-password');
        }
        $this->customerTry($c->email, self::PASSWORD)->assertRedirect();
        $this->flushSession();
        for ($i = 1; $i <= 4; $i++) {
            $this->customerTry($c->email, 'wrong-password')->assertSee('Invalid email or password.');
        }
    }

    public function test_staff_sign_in_page_has_the_same_limit(): void
    {
        $staff = $this->makeAccount('staff');
        for ($i = 1; $i <= 4; $i++) {
            $this->staffTry($staff->email, 'wrong-password')->assertSee('Invalid email or password.');
        }
        $this->staffTry($staff->email, 'wrong-password')->assertSee(self::LOCKED . ' 10 seconds.');
        $this->staffTry($staff->email, self::PASSWORD)->assertSee(self::LOCKED)->assertSessionMissing('user_id');

        $this->travel(11)->seconds();
        $this->staffTry($staff->email, self::PASSWORD)->assertRedirect(route('admin.dashboard'));
    }

    public function test_message_wording_for_minutes(): void
    {
        $this->assertSame(self::LOCKED . ' 1 minute.', SignInThrottle::message(60));
        $this->assertSame(self::LOCKED . ' 15 minutes.', SignInThrottle::message(900));
        $this->assertSame(self::LOCKED . ' 1 second.', SignInThrottle::message(1));
    }
}
