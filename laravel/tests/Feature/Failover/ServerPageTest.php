<?php

namespace Tests\Feature\Failover;

use App\Failover\ServerFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* The Server page (failover): the super admin only; Test reads a server's report; the actions
   that change servers ask for the password AND a fresh authenticator code. No replication runs
   on this machine, so the test database answers as a plain main. */
class ServerPageTest extends TestCase
{
    use MakesAccounts { tearDown as removeAccounts; }

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/venusep-server-' . bin2hex(random_bytes(4)) . '.json';
        $this->serverFile($this->file);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->file . '*') as $f) {
            @unlink($f);
        }
        $this->serverFile('no-server-file-in-tests');
        DB::table('users')->where('email', 'admin@gmail.com')->update(['totp_last_step' => null, 'totp_failed_attempts' => 0, 'totp_locked_until' => null]);
        $this->removeAccounts();
    }

    private function serverFile(string $path): void
    {
        putenv('VENUSEP_SERVER_FILE=' . $path);
        $_ENV['VENUSEP_SERVER_FILE'] = $_SERVER['VENUSEP_SERVER_FILE'] = $path;
    }

    private function act(array $data)
    {
        return $this->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')->withHeader('Accept', 'application/json')
            ->post(route('admin.server-action'), $data);
    }

    public function test_only_the_super_admin_gets_the_page_and_its_actions(): void
    {
        $this->get(route('admin.server'))->assertRedirect();
        $this->act(['action' => 'test', 'ip' => '127.0.0.1'])->assertStatus(401);

        $this->actingAsStaff()->get(route('admin.server'))->assertRedirect();   // as Backup & Restore: staff:super
        $this->actingAsStaff()->act(['action' => 'test', 'ip' => '127.0.0.1'])->assertStatus(403);
        $this->actingAsAdmin('francishubero@gmail.com')->act(['action' => 'find'])->assertStatus(403);

        $this->flushSession();
        $this->actingAsAdmin()->get(route('admin.server'))->assertOk()
            ->assertSee('Database servers')->assertSee('Find servers on this hotspot')->assertSee('Saving without a copy')
            ->assertSee('data-ip="127.0.0.1"', false)                     // the main is always a row
            ->assertSee(route('admin.server'), false);                     // the sidebar link
    }

    public function test_test_reports_this_machines_database_as_the_main_and_never_offers_to_switch_to_it(): void
    {
        $r = $this->actingAsAdmin()->act(['action' => 'test', 'ip' => '127.0.0.1'])->assertOk()->json('report');
        $this->assertTrue($r['reachable']);
        $this->assertSame(['main', true, false], [$r['role'], $r['current'], $r['safe']]);
        $this->assertContains([true, 'The main: it takes every save'], $r['checks']);

        $gone = $this->actingAsAdmin()->act(['action' => 'test', 'ip' => '10.255.255.1'])->assertOk()->json('report');
        $this->assertFalse($gone['reachable']);
        $this->assertStringContainsString('Can’t reach it', $gone['checks'][0][1]);

        $this->actingAsAdmin()->act(['action' => 'test', 'ip' => '127.0.0.1;dbname=x'])->assertStatus(422);
    }

    public function test_the_laptops_list_is_kept_in_the_server_file(): void
    {
        $this->actingAsAdmin()->act(['action' => 'add', 'ip' => '127.0.0.1'])->assertStatus(422);   // other laptops can't reach that
        $this->actingAsAdmin()->act(['action' => 'add', 'ip' => '192.168.43.12'])->assertOk();
        $this->actingAsAdmin()->act(['action' => 'add', 'ip' => '192.168.43.13'])->assertOk();
        $this->assertSame(['192.168.43.12', '192.168.43.13'], ServerFile::laptops());
        $this->assertSame(2, ServerFile::read()['version']);

        $this->actingAsAdmin()->act(['action' => 'remove', 'ip' => '192.168.43.12'])->assertOk();
        $this->assertSame(['192.168.43.13'], ServerFile::laptops());
        $this->assertSame([], glob($this->file . '.tmp-*'), 'no half-written file left behind');
    }

    public function test_switching_asks_for_the_password_and_a_fresh_code_and_refuses_an_unsafe_target(): void
    {
        $this->knowPasswordOf('admin@gmail.com');
        $this->actingAsAdmin();
        $secret = (string) DB::table('users')->where('email', 'admin@gmail.com')->value('totp_secret');

        $this->act(['action' => 'switch', 'ip' => '127.0.0.1'])->assertStatus(400);
        $this->act(['action' => 'switch', 'ip' => '127.0.0.1', 'password' => 'nope', 'code' => '123456'])
            ->assertStatus(401)->assertJson(['field' => 'password']);
        $this->assertSame(1, (int) DB::table('users')->where('email', 'admin@gmail.com')->value('reauth_failed_attempts'), 'a wrong password counts');
        $this->act(['action' => 'switch', 'ip' => '127.0.0.1', 'password' => self::PASSWORD, 'code' => '000000'])
            ->assertStatus(401)->assertJson(['field' => 'code']);

        /* both right, but the target is the working main: nothing is switched */
        $res = $this->act(['action' => 'switch', 'ip' => '127.0.0.1', 'password' => self::PASSWORD, 'code' => $this->totpNow($secret)])
            ->assertStatus(409)->json();
        $this->assertFalse($res['ok']);
        $this->assertSame('none', $res['mode']);
        $this->assertNull(ServerFile::read(), 'the address was not saved');
    }
}
