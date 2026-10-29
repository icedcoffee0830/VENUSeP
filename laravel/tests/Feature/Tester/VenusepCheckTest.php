<?php

namespace Tests\Feature\Tester;

use App\Console\VenusepCheck\BrowserPass;
use App\Http\Controllers\PendingController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/* php artisan venusep:check — the auto-tester. Each test registers a few pages
   named root.zzcheck-* and checks only those (--only), in-process (the test's
   routes do not exist in a worker process). */
class VenusepCheckTest extends TestCase
{
    private array $reportsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportsBefore = glob(storage_path('app/venusep-check/*')) ?: [];

        Route::middleware('web')->group(function () {
            Route::get('/_vc/ok', fn () => '<html><body><a href="' . route('customer.index') . '">home</a>'
                . '<a href="' . route('customer.index') . '#x">anchor</a><a href="https://example.com/x">elsewhere</a>'
                . '<img src="' . asset('favicon.ico') . '"><form method="post"><input type="hidden" name="_token" value="t"></form>'
                . '<script>console.warn("Warning: this is page JS, not PHP")</script></body></html>')->name('root.zzcheck-ok');
            Route::get('/_vc/pending', PendingController::class)->name('root.zzcheck-pending');
            Route::get('/_vc/throws', fn () => throw new \RuntimeException('boom from a page'))->name('root.zzcheck-throws');
            Route::get('/_vc/broken', fn () => '<html><body><img src="/assets/img/no-such-image.png">'
                . '<a href="booking-request.php?id=1">old link</a></body></html>')->name('root.zzcheck-broken');
            Route::get('/_vc/php-error', fn () => '<p><b>Warning</b>:  Undefined variable $room in page.php on line 3</p>')
                ->name('root.zzcheck-php-error');
            Route::get('/_vc/no-csrf', fn () => '<form id="saveForm" method="POST" action="' . route('customer.index') . '"></form>')
                ->name('root.zzcheck-no-csrf');
        });
        Route::middleware(['web', 'staff'])->get('/_vc/guarded', fn () => 'staff only')->name('root.zzcheck-guarded');
        Route::getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        foreach (array_diff(glob(storage_path('app/venusep-check/*')) ?: [], $this->reportsBefore) as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** the JSON report the last run wrote */
    private function lastReport(): array
    {
        $new = array_values(array_filter(array_diff(glob(storage_path('app/venusep-check/*.json')) ?: [], $this->reportsBefore)));
        $this->assertNotEmpty($new, 'no JSON report was written');
        sort($new);

        return json_decode(file_get_contents(end($new)), true);
    }

    private function row(array $report, string $route, string $role): array
    {
        foreach ($report['results'] as $r) {
            if ($r['route'] === $route && $r['role'] === $role) {
                return $r;
            }
        }
        $this->fail("no result for {$route} as {$role}");
    }

    public function test_a_clean_page_is_ok_and_a_placeholder_is_not_ported(): void
    {
        $this->artisan('venusep:check', ['--only' => 'root.zzcheck-ok', '--in-process' => true])->assertExitCode(0);
        $r = $this->row($this->lastReport(), 'root.zzcheck-ok', 'customer');
        $this->assertSame('ok', $r['result'], json_encode($r['problems']));
        $this->assertSame(200, $r['status']);

        $this->artisan('venusep:check', ['--only' => 'root.zzcheck-pending', '--role' => 'guest', '--in-process' => true])
            ->expectsOutputToContain('1 not ported')->assertExitCode(0);
        $this->assertSame('not_ported', $this->row($this->lastReport(), 'root.zzcheck-pending', 'guest')['result']);
    }

    public function test_a_page_that_throws_fails_the_run(): void
    {
        $this->artisan('venusep:check', ['--only' => 'root.zzcheck-throws', '--role' => 'staff', '--in-process' => true])
            ->expectsOutputToContain('1 failing')->assertExitCode(1);
        $r = $this->row($this->lastReport(), 'root.zzcheck-throws', 'staff');
        $this->assertSame('fail', $r['result']);
        $this->assertSame(500, $r['status']);
        $this->assertStringContainsString('RuntimeException: boom from a page', $r['problems'][0][1]);
    }

    public function test_one_broken_page_does_not_stop_the_others(): void
    {
        $this->artisan('venusep:check', ['--only' => 'root.zzcheck-', '--role' => 'customer', '--in-process' => true])->assertExitCode(1);
        $report = $this->lastReport();
        $this->assertSame('fail', $this->row($report, 'root.zzcheck-throws', 'customer')['result']);
        $this->assertSame('ok', $this->row($report, 'root.zzcheck-ok', 'customer')['result']);
        $this->assertSame(1, $report['exit_code']);
    }

    public function test_a_guarded_page_redirects_the_guest(): void
    {
        $this->artisan('venusep:check', ['--only' => 'root.zzcheck-guarded', '--in-process' => true])->assertExitCode(0);
        $report = $this->lastReport();
        $guest = $this->row($report, 'root.zzcheck-guarded', 'guest');
        $this->assertSame(302, $guest['status']);
        $this->assertSame('/admin/admin-login', $guest['redirect']);
        $this->assertSame('/admin/admin-login', $this->row($report, 'root.zzcheck-guarded', 'customer')['redirect']);
        $this->assertSame(200, $this->row($report, 'root.zzcheck-guarded', 'staff')['status']);
        $this->assertSame(200, $this->row($report, 'root.zzcheck-guarded', 'admin')['status']);   // signed in with 2FA passed
    }

    public function test_broken_links_and_missing_assets_are_flagged(): void
    {
        $this->artisan('venusep:check', ['--only' => 'root.zzcheck-broken', '--role' => 'guest', '--in-process' => true])->assertExitCode(1);
        $r = $this->row($this->lastReport(), 'root.zzcheck-broken', 'guest');
        $texts = implode("\n", array_column($r['problems'], 1));
        $this->assertSame('fail', $r['result']);
        $this->assertStringContainsString('missing asset: <img src="/assets/img/no-such-image.png">', $texts);
        $this->assertStringContainsString('broken link: <a href="booking-request.php?id=1">', $texts);
    }

    public function test_php_error_text_fails_and_a_post_form_without_csrf_warns(): void
    {
        $this->artisan('venusep:check', ['--only' => 'root.zzcheck-php-error', '--role' => 'guest', '--in-process' => true])->assertExitCode(1);
        $r = $this->row($this->lastReport(), 'root.zzcheck-php-error', 'guest');
        $this->assertSame('fail', $r['result']);
        $this->assertStringContainsString('PHP error text', implode("\n", array_column($r['problems'], 1)));

        $this->artisan('venusep:check', ['--only' => 'root.zzcheck-no-csrf', '--role' => 'guest', '--in-process' => true])->assertExitCode(0);
        $r = $this->row($this->lastReport(), 'root.zzcheck-no-csrf', 'guest');
        $this->assertSame('warn', $r['result']);
        $this->assertSame([['warn', 'POST form without a CSRF token (@csrf): saveForm']], $r['problems']);
    }

    public function test_it_refuses_the_real_database(): void
    {
        $this->artisan('venusep:check', ['--db' => 'venusep'])
            ->expectsOutputToContain("Refusing to run against the database 'venusep'")->assertExitCode(2);
        $this->artisan('venusep:check', ['--db' => ' VENUSeP '])->assertExitCode(2);
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->artisan('venusep:check', ['--role' => 'janitor'])->assertExitCode(2);
    }

    /* the default mode: one worker process per role, over real routes */
    public function test_worker_processes_check_the_real_routes(): void
    {
        $this->artisan('venusep:check', ['--only' => 'customer.index', '--role' => 'guest'])
            ->expectsOutputToContain('1 ok')->assertExitCode(0);
        $r = $this->row($this->lastReport(), 'customer.index', 'guest');
        $this->assertSame(302, $r['status']);
        $this->assertSame('/customer/venues', $r['redirect']);
    }

    public function test_query_strings_are_filled_from_the_database(): void
    {
        $this->artisan('venusep:check', ['--only' => 'admin.booking-request', '--role' => 'admin', '--in-process' => true]);
        $r = $this->row($this->lastReport(), 'admin.booking-request', 'admin');
        $this->assertMatchesRegularExpression('#^/admin/booking-request\?id=VB-\d{4}-\d{3,}$#', $r['url']);

        $this->artisan('venusep:check', ['--only' => 'customer.room-reservation', '--role' => 'staff', '--in-process' => true]);
        $r = $this->row($this->lastReport(), 'customer.room-reservation', 'staff');
        $this->assertStringContainsString('counter=1', $r['url']);   // staff open the booking pages at the counter
    }

    public function test_browser_findings_join_the_page_row_as_warnings(): void
    {
        $rows = [['route' => 'admin.dashboard', 'role' => 'staff', 'result' => 'ok', 'problems' => []],
            ['route' => 'admin.dashboard', 'role' => 'admin', 'result' => 'ok', 'problems' => []]];
        $merged = BrowserPass::merge($rows, [
            ['route' => 'admin.dashboard', 'role' => 'staff', 'problem' => true, 'pageErrors' => ['x is not defined'],
                'consoleErrors' => [], 'failedRequests' => ['http://127.0.0.1:8000/assets/a.js (HTTP 404)']],
            ['role' => 'staff', 'note' => 'blocked other sites: cdn.jsdelivr.net'],
        ]);
        $this->assertSame('warn', $merged[0]['result']);
        $this->assertSame([['warn', 'browser JS error: x is not defined'],
            ['warn', 'browser failed request: http://127.0.0.1:8000/assets/a.js (HTTP 404)']], $merged[0]['problems']);
        $this->assertSame('ok', $merged[1]['result']);
    }
}
