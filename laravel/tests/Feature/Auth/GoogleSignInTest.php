<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/* customer/google-start.php + customer/google-callback.php + google-auth.php.
   No google-config.php in the Laravel copy, so the pages take the old "not set
   up" path; the state / nonce / claim checks are driven directly, offline —
   nothing here ever calls Google. */
class GoogleSignInTest extends TestCase
{
    use MakesAccounts;

    public function test_google_is_not_configured_here(): void
    {
        legacy('google-auth');
        $this->assertFalse(google_enabled());
    }

    public function test_start_without_config_goes_back_to_login_with_the_notice(): void
    {
        $this->get(route('customer.google-start'))->assertRedirect(route('customer.customer-login'))
            ->assertSessionHas('login_notice', 'Sign-in with Google is not set up on this server yet. Log in with your email and password.');
        $this->get(route('customer.customer-login'))->assertSee('Sign-in with Google is not set up on this server yet.');
    }

    public function test_start_for_a_signed_in_customer(): void
    {
        $this->actingAsCustomer()->get(route('customer.google-start'))->assertRedirect(route('customer.venues'));
        $this->get(route('customer.google-start', ['purpose' => 'link']))
            ->assertRedirect(route('customer.customer-profile') . '#accountSecurityTitle')
            ->assertSessionHas('profile_notice', 'Google sign-in is not set up on this server yet.');
        $this->flushSession();
        $this->get(route('customer.google-start', ['purpose' => 'reauth']))->assertRedirect(route('customer.customer-login'));
    }

    public function test_callback_without_config_says_so(): void
    {
        $this->get(route('customer.google-callback', ['state' => 'x', 'code' => 'y']))
            ->assertRedirect(route('customer.customer-login'))
            ->assertSessionHas('login_notice', 'Sign-in with Google is not set up on this server yet. Log in with your email and password.');
        $r = $this->actingAsCustomer()->get(route('customer.google-callback'))
            ->assertRedirect(route('customer.customer-profile') . '#accountSecurityTitle');
        $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
    }

    public function test_state_is_one_time_tied_to_the_session_and_expires(): void
    {
        Route::middleware('web')->get('/_t/google-flow', function () {
            legacy('google-auth');
            $url = google_begin('signin', 0, true);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $flow = session('google_flow');
            $out = [
                'pkce' => $q['code_challenge'] === google_b64url(hash('sha256', $flow['verifier'], true)) && $q['code_challenge_method'] === 'S256',
                'remember' => $flow['remember'],
                'wrong_state' => google_take_flow('not-the-state'),
                'consumed_by_a_wrong_try' => session()->has('google_flow'),
            ];
            google_begin('link', 7, true);
            $flow = session('google_flow');
            $out['link_never_remembers'] = $flow['remember'] === false && $flow['purpose'] === 'link' && $flow['user_id'] === 7;
            $out['right_state'] = google_take_flow($flow['state']) !== null;
            $out['second_use'] = google_take_flow($flow['state']);
            google_begin();
            session(['google_flow' => ['at' => time() - GOOGLE_FLOW_TTL - 1] + session('google_flow')]);
            $out['expired'] = google_take_flow(session('google_flow')['state']);
            google_begin('evil');
            $out['unknown_purpose'] = session('google_flow')['purpose'];
            return response()->json($out);
        });
        $this->get('/_t/google-flow')->assertExactJson([
            'pkce' => true, 'remember' => true, 'wrong_state' => null, 'consumed_by_a_wrong_try' => false,
            'link_never_remembers' => true, 'right_state' => true, 'second_use' => null, 'expired' => null,
            'unknown_purpose' => 'signin',
        ]);
    }

    public function test_the_id_token_claims_checks(): void
    {
        legacy('google-auth');
        $now = time();
        $cid = 'client.apps.googleusercontent.com';
        $good = ['iss' => 'https://accounts.google.com', 'aud' => $cid, 'exp' => $now + 3600, 'iat' => $now,
                 'nonce' => 'n0nce', 'sub' => '1234567890', 'email' => 'a@b.test', 'email_verified' => true];
        $this->assertNull(google_check_claims($good, $cid, 'n0nce', $now));
        $this->assertSame('wrong issuer', google_check_claims(['iss' => 'https://evil.example'] + $good, $cid, 'n0nce', $now));
        $this->assertSame('token was not issued to this client', google_check_claims(['aud' => 'other'] + $good, $cid, 'n0nce', $now));
        $this->assertSame('token was not issued to this client', google_check_claims(['aud' => [$cid, 'x']] + $good, $cid, 'n0nce', $now));
        $this->assertNull(google_check_claims(['aud' => [$cid, 'x'], 'azp' => $cid] + $good, $cid, 'n0nce', $now));
        $this->assertSame('token expired', google_check_claims(['exp' => $now - 600] + $good, $cid, 'n0nce', $now));
        $this->assertSame('token issued in the future', google_check_claims(['iat' => $now + 600] + $good, $cid, 'n0nce', $now));
        $this->assertSame('nonce mismatch', google_check_claims(['nonce' => 'other'] + $good, $cid, 'n0nce', $now));
        $this->assertSame('no usable email', google_check_claims(['email' => 'nope'] + $good, $cid, 'n0nce', $now));
        $this->assertSame('email not verified by Google', google_check_claims(['email_verified' => false] + $good, $cid, 'n0nce', $now));
    }

    public function test_resolve_never_links_by_email_and_refuses_staff(): void
    {
        legacy('google-auth');
        $pdo = venusep_db();
        $c = $this->makeAccount('customer');
        $staff = $this->makeAccount('staff', ['google_sub' => 'sub-staff-' . $c->id]);
        $linked = $this->makeAccount('customer', ['google_sub' => 'sub-cust-' . $c->id]);
        $this->assertSame(['action' => 'taken'], google_resolve($pdo, 'new-sub-' . $c->id, $c->email));
        $this->assertSame(['action' => 'refused'], google_resolve($pdo, 'sub-staff-' . $c->id, 'x@y.test'));
        $this->assertSame(['action' => 'new'], google_resolve($pdo, 'new-sub-' . $c->id, 'nobody-' . $c->id . '@test.local'));
        $this->assertSame('signin', google_resolve($pdo, 'sub-cust-' . $c->id, 'whatever@test.local')['action']);
        $this->assertSame('That Google account is already connected to another VENUSeP account.', google_link($pdo, $c->id, 'sub-cust-' . $c->id));
        $this->assertNull(google_link($pdo, $c->id, 'fresh-sub-' . $c->id));
        $this->assertSame('fresh-sub-' . $c->id, DB::table('users')->where('id', $c->id)->value('google_sub'));
    }
}
