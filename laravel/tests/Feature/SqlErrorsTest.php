<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/* Cleanup, 2026-11-01: a failed query is findable. sql_failed() always logs; with APP_DEBUG on
   the real error reaches the screen; with it off nothing but the friendly reply does. The
   breaks are real: a column renamed on the test database, put back afterwards. */
class SqlErrorsTest extends TestCase
{
    private ?array $renamed = null;

    protected function tearDown(): void
    {
        if ($this->renamed) {
            [$table, $column, $definition] = $this->renamed;
            DB::statement("ALTER TABLE {$table} CHANGE {$column}_broken {$column} {$definition}");
        }
        parent::tearDown();
    }

    private function breakColumn(string $table, string $column): void
    {
        $this->assertStringContainsString('test', (string) DB::connection()->getDatabaseName(), 'only ever on the test database');
        /* CHANGE, not RENAME COLUMN: XAMPP's MariaDB is too old for that */
        $create = DB::selectOne("SHOW CREATE TABLE {$table}")->{'Create Table'};
        $this->assertSame(1, preg_match('/^\s*`' . $column . '` (.*?),?$/m', $create, $m));
        DB::statement("ALTER TABLE {$table} CHANGE {$column} {$column}_broken {$m[1]}");
        $this->renamed = [$table, $column, $m[1]];
    }

    private function refusal(string $sqlstate, ?int $errno = null): PDOException
    {
        $e = new class('A refusal') extends PDOException {
            public function state(string $s): static { $this->code = $s; return $this; }
        };
        $e->errorInfo = [$sqlstate, $errno, 'A refusal'];
        return $e->state($sqlstate);
    }

    /* ---- a customer's real page ---- */

    public function test_debug_on_a_broken_query_shows_its_column_on_the_customer_page(): void
    {
        config(['app.debug' => true]);
        $this->breakColumn('reservation_statuses', 'customer_label');
        $this->actingAsCustomer('msantos@usep.edu.ph')->get(route('customer.transaction-history'))
            ->assertStatus(500)->assertSee('customer_label');
    }

    public function test_debug_off_the_same_page_still_opens_and_names_no_sql_but_the_log_does(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        $this->breakColumn('reservation_statuses', 'customer_label');
        $res = $this->actingAsCustomer('msantos@usep.edu.ph')->get(route('customer.transaction-history'));
        $res->assertOk()->assertDontSee('customer_label')->assertDontSee('SQLSTATE')->assertDontSee('Unknown column');
        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'customer_label')
            && str_contains($msg, 'SQL failed at ') && preg_match('/\.php:\d+: /', $msg) === 1);
    }

    /* ---- a JSON endpoint: fetch() sends no text/html, and must always get JSON ---- */

    public function test_debug_on_a_json_endpoint_answers_json_with_the_real_message(): void
    {
        config(['app.debug' => true]);
        $this->breakColumn('customers', 'phone');
        $this->actingAsStaff()->withHeaders(['Accept' => '*/*'])->get(route('admin.customer-search', ['q' => 'Maria Santos']))
            ->assertStatus(500)->assertHeader('Content-Type', 'application/json')
            ->assertJson(['ok' => false, 'error' => 'server'])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'phone') && str_contains($m, 'Unknown column'));
    }

    public function test_debug_off_a_json_endpoint_keeps_its_friendly_reply_and_leaks_nothing(): void
    {
        config(['app.debug' => false]);
        $this->breakColumn('customers', 'phone');
        $res = $this->actingAsStaff()->withHeaders(['Accept' => '*/*'])->get(route('admin.customer-search', ['q' => 'Maria Santos']));
        $res->assertStatus(500)->assertExactJson(['ok' => false, 'results' => [], 'message' => 'Search failed.']);
    }

    /* ---- what must not be rethrown ---- */

    public function test_refusals_are_never_rethrown_and_optional_work_is_only_logged(): void
    {
        legacy('db');
        config(['app.debug' => true]);
        Log::spy();
        sql_failed($this->refusal('45000'));                 // a procedure's SIGNAL
        sql_failed($this->refusal('40001', 1213));           // a deadlock
        sql_failed($this->refusal('23000', 1062));           // a duplicate key
        Log::shouldHaveReceived('error')->times(3);

        sql_failed(new RuntimeException('The mail queue failed'), optional: true);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'The mail queue failed'));
    }

    public function test_any_other_failure_is_rethrown_with_debug_on_and_only_logged_with_it_off(): void
    {
        legacy('db');
        $fk = $this->refusal('23000', 1452);    // same SQLSTATE as a duplicate, but a real fault
        config(['app.debug' => false]);
        Log::spy();
        sql_failed($fk);
        Log::shouldHaveReceived('error')->once();

        config(['app.debug' => true]);
        $this->expectException(PDOException::class);
        sql_failed($fk);
    }

    /* ---- the transaction nobody finished ---- */

    private function forgetfulRoute(bool $commit = false): void
    {
        Route::get('/zz-transaction', function () use ($commit) {
            $pdo = DB::connection()->getPdo();
            $pdo->beginTransaction();
            $commit ? $pdo->commit() : null;
            return response('reached the end');
        });
    }

    public function test_debug_on_a_forgotten_commit_is_shown_and_rolled_back(): void
    {
        config(['app.debug' => true]);
        $this->forgetfulRoute();
        $this->get('/zz-transaction')->assertStatus(500)->assertSee('Unfinished transaction at the end of GET zz-transaction');
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->withHeaders(['Accept' => '*/*'])->get('/zz-transaction')->assertStatus(500)
            ->assertJsonPath('message', 'Unfinished transaction at the end of GET zz-transaction');
    }

    public function test_debug_on_an_error_that_left_a_transaction_open_still_shows_itself(): void
    {
        config(['app.debug' => true]);
        Route::get('/zz-transaction', function () {
            DB::connection()->getPdo()->beginTransaction();
            throw new RuntimeException('The real cause');
        });
        $res = $this->get('/zz-transaction')->assertStatus(500)->assertSee('The real cause');
        $this->assertSame('The real cause', $res->exception->getMessage());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_debug_off_a_forgotten_commit_is_logged_and_the_page_is_unchanged(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        $this->forgetfulRoute();
        try {
            $this->get('/zz-transaction')->assertOk()->assertSee('reached the end');
            Log::shouldHaveReceived('error')->with('Unfinished transaction at the end of GET zz-transaction')->once();
        } finally {
            DB::connection()->getPdo()->rollBack();
        }
    }

    public function test_a_finished_transaction_is_not_reported(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $this->forgetfulRoute(commit: true);
        $this->get('/zz-transaction')->assertOk();
        Log::shouldNotHaveReceived('error');
    }
}
