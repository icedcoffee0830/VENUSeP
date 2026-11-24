<?php

namespace Tests\Feature\Failover;

use App\Failover\Health;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/* "Live copy stopped" (App\Failover\Health): the 1-minute task's answer, shown to the super
   admin only, on the Server page and as a count on its sidebar link. A single laptop shows nothing. */
class HealthBannerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/venusep-server-' . bin2hex(random_bytes(4)) . '.json';
        $this->serverFile($this->file);
        Cache::forget('failover.health');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        $this->serverFile('no-server-file-in-tests');
        parent::tearDown();
    }

    private function serverFile(string $path): void
    {
        putenv('VENUSEP_SERVER_FILE=' . $path);
        $_ENV['VENUSEP_SERVER_FILE'] = $_SERVER['VENUSEP_SERVER_FILE'] = $path;
    }

    private function group(): void
    {
        file_put_contents($this->file, json_encode(['version' => 1, 'laptops' => ['192.168.43.10', '192.168.43.11']]));
    }

    public function test_one_laptop_shows_nothing(): void
    {
        $this->assertFalse(Health::last());
        $this->actingAsAdmin()->get(route('admin.server'))->assertOk()->assertDontSee('Live copy stopped.')->assertDontSee('No health check');
    }

    public function test_the_super_admin_sees_a_stopped_copy_and_nobody_else_does(): void
    {
        $this->group();
        Cache::put('failover.health', ['problems' => ['The live copy on 192.168.43.11 stopped.'], 'at' => time()], 180);

        $this->actingAsAdmin()->get(route('admin.server'))->assertOk()
            ->assertSee('Live copy stopped.')->assertSee('The live copy on 192.168.43.11 stopped.');
        $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->assertSee('data-live="sb-Server"', false);

        $this->flushSession();
        $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.dashboard'))->assertOk()->assertDontSee('sb-Server', false);
        $this->flushSession();
        $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk()->assertDontSee('sb-Server', false);
    }

    public function test_no_answer_for_three_minutes_says_the_task_may_not_be_running(): void
    {
        $this->group();
        $this->assertNull(Health::last());
        $this->actingAsAdmin()->get(route('admin.server'))->assertOk()->assertSee('No health check for 3 minutes.');
    }
}
