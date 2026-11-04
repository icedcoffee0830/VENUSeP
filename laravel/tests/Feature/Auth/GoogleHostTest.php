<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/* The Google sign-in safety net (DB-DECISIONS 23, 28): google-start on another host
   or scheme first moves to the configured redirect_uri's host. A fake settings file
   of the test's own (venusep.google_config_test), never the real google-config.php;
   nothing here calls Google. */
class GoogleHostTest extends TestCase
{
    private string $cfgFile = '';

    private function configure(string $redirectUri): void
    {
        $this->cfgFile = tempnam(sys_get_temp_dir(), 'gcfg') . '.php';
        file_put_contents($this->cfgFile, '<?php return ' . var_export(
            ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret', 'redirect_uri' => $redirectUri], true) . ';');
        config(['venusep.google_config_test' => $this->cfgFile]);
    }

    protected function tearDown(): void
    {
        if ($this->cfgFile !== '') {
            @unlink($this->cfgFile);
        }
        parent::tearDown();
    }

    public function test_another_host_moves_to_the_configured_one_keeping_remember(): void
    {
        $this->configure('https://venusep.example.test/customer/google-callback');
        $r = $this->get('http://127.0.0.1:8100/customer/google-start?remember=1')
            ->assertRedirect('https://venusep.example.test/customer/google-start?remember=1')
            ->assertSessionMissing('google_flow');   // the trip starts on the right host, not here
        $this->assertStringNotContainsString('test-client-secret', $r->headers->get('Location'));

        $this->get('http://127.0.0.1:8100/customer/google-start')->assertRedirect('https://venusep.example.test/customer/google-start');
    }

    public function test_the_same_host_but_another_scheme_moves_too(): void
    {
        $this->configure('https://venusep.example.test/customer/google-callback');
        $this->get('http://venusep.example.test/customer/google-start')->assertRedirect('https://venusep.example.test/customer/google-start');
    }

    public function test_a_port_in_the_redirect_uri_is_kept(): void
    {
        $this->configure('http://localhost:8000/customer/google-callback');
        $this->get('http://127.0.0.1:8000/customer/google-start?remember=1')->assertRedirect('http://localhost:8000/customer/google-start?remember=1');
    }

    public function test_on_the_configured_host_the_trip_to_google_starts(): void
    {
        $this->configure('https://venusep.example.test:443/customer/google-callback');   // a default port written out changes nothing
        $r = $this->get('https://venusep.example.test/customer/google-start?remember=1');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $r->headers->get('Location'));
        $this->assertStringNotContainsString('test-client-secret', $r->headers->get('Location'));
        $r->assertSessionHas('google_flow');
        $this->assertTrue(session('google_flow')['remember']);
    }
}
