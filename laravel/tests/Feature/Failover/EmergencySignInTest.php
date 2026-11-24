<?php

namespace Tests\Feature\Failover;

use App\Failover\SuperAdminCopy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* The emergency way in (failover plan §3): the locked copy of the super admin's sign-in is
   encrypted and kept fresh by the 1-minute task; while the database is down it opens the
   Server panel only, with the password AND an authenticator code that works once. */
class EmergencySignInTest extends TestCase
{
    use MakesAccounts { tearDown as removeAccounts; }

    private string $copy;
    private string $host;
    private int $port;
    private ?string $hash = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copy = sys_get_temp_dir() . '/venusep-super-' . bin2hex(random_bytes(4)) . '.lock';
        $this->env('VENUSEP_SUPER_ADMIN_COPY', $this->copy);
        $this->host = config('database.connections.mysql.host');
        $this->port = (int) config('database.connections.mysql.port');
        Cache::forget('failover.totp_step');
    }

    protected function tearDown(): void
    {
        config(['database.connections.mysql.host' => $this->host, 'database.connections.mysql.port' => $this->port]);
        DB::purge('mysql');
        @unlink($this->copy);
        $this->env('VENUSEP_SUPER_ADMIN_COPY', '');
        $this->removeAccounts();
    }

    private function env(string $k, string $v): void
    {
        putenv($k . '=' . $v);
        $_ENV[$k] = $_SERVER[$k] = $v;
    }

    private function databaseGone(): void
    {
        config(['database.connections.mysql.port' => 1]);
        DB::purge('mysql');
    }

    /* the super admin's password is PASSWORD, and an authenticator is on */
    private function superAdmin(): array
    {
        $this->knowPasswordOf('admin@gmail.com');
        DB::update("UPDATE users SET totp_secret = 'JBSWY3DPEHPK3PXP', totp_enabled_at = NOW() WHERE email = 'admin@gmail.com' AND totp_secret IS NULL");
        return ['admin@gmail.com', (string) DB::table('users')->where('email', 'admin@gmail.com')->value('totp_secret')];
    }

    public function test_the_task_keeps_an_encrypted_copy_and_removes_it_without_an_authenticator(): void
    {
        [$email] = $this->superAdmin();
        $this->artisan('venusep:failover-tick')->expectsOutput('emergency sign-in: refreshed')->assertSuccessful();
        $raw = (string) file_get_contents($this->copy);
        $this->assertStringNotContainsString($email, $raw);
        $this->assertStringNotContainsString('$argon2', $raw);
        $this->assertSame('unchanged', SuperAdminCopy::refresh());

        file_put_contents($this->copy, 'x' . $raw);   // tampered: not readable, so no sign-in
        $this->assertFalse(SuperAdminCopy::exists());
    }

    public function test_while_the_database_works_the_emergency_page_sends_you_to_the_normal_one(): void
    {
        $this->get(route('failover.emergency'))->assertRedirect(route('admin.server'));
    }

    public function test_with_the_database_down_the_super_admin_signs_in_once_per_code_and_gets_only_the_panel(): void
    {
        [$email, $secret] = $this->superAdmin();
        SuperAdminCopy::refresh();
        $this->databaseGone();

        $this->get(route('failover.emergency'), ['Accept' => 'text/html'])->assertStatus(503)
            ->assertSee('Can’t reach the database server')->assertSee('Code from your authenticator app');

        $this->post(route('failover.emergency'), ['email' => $email, 'password' => 'nope', 'code' => '000000'])
            ->assertSee('Wrong email or password.');
        $this->assertNull(session('failover_emergency'));

        $code = $this->totpNow($secret);
        $this->post(route('failover.emergency'), ['email' => strtoupper($email), 'password' => self::PASSWORD, 'code' => $code])
            ->assertRedirect(route('failover.emergency'));
        $this->assertSame($email, session('failover_emergency')['email']);

        $this->get(route('failover.emergency'), ['Accept' => 'text/html'])->assertStatus(503)
            ->assertSee('Emergency server panel')->assertSee('Database servers')->assertSee('server-emergency\/action', false);   // in the page's JSON

        /* the panel works without the database: Test another address */
        $this->withHeader('X-CSRF-TOKEN', session()->token())->withHeader('Accept', 'application/json')
            ->post(route('failover.emergency-action'), ['action' => 'test', 'ip' => '10.255.255.1'])
            ->assertOk()->assertJsonPath('report.reachable', false);

        /* a change needs the password and a NEW code: the one used to sign in is spent */
        $this->withHeader('X-CSRF-TOKEN', session()->token())->withHeader('Accept', 'application/json')
            ->post(route('failover.emergency-action'), ['action' => 'switch', 'ip' => '10.255.255.1', 'password' => self::PASSWORD, 'code' => $code])
            ->assertStatus(401)->assertJsonPath('message', 'That code was already used. Wait for the next code in your app, then try again.');

        /* nothing else opens: every other page is the outage page */
        $this->get(route('admin.dashboard'), ['Accept' => 'text/html'])->assertStatus(503)->assertSee('The system is switching servers');
    }
}
