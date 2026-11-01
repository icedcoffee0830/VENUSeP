<?php

namespace Tests\Feature\Flows;

use App\Console\VenusepFlows\FlowsDb;
use Tests\TestCase;

/* The PHP side of the flow tester (php artisan venusep:flows): it must never
   touch a database outside venusep_laravel_flows* / venusep_laravel_test*, and
   its db() helper must stay read-only. */
class FlowsHarnessTest extends TestCase
{
    public function test_only_flows_and_test_databases_are_allowed(): void
    {
        $this->assertTrue(FlowsDb::allowed('venusep_laravel_flows'));
        $this->assertTrue(FlowsDb::allowed('venusep_laravel_test_flows'));
        foreach (['venusep', 'VENUSEP', 'venusep_laravel', 'venusep_laravel_dev', '', 'venusep_laravel_flows; DROP DATABASE venusep', 'other_venusep_laravel_flows'] as $db) {
            $this->assertFalse(FlowsDb::allowed($db), "allowed '{$db}'");
        }
    }

    public function test_the_command_refuses_the_real_and_the_dev_database(): void
    {
        $this->artisan('venusep:flows', ['--db' => 'venusep'])->assertExitCode(2);
        $this->artisan('venusep:flows', ['--db' => 'venusep_laravel'])->assertExitCode(2);
    }

    public function test_the_tool_refuses_a_non_flows_database(): void
    {
        $this->artisan('venusep:flows-tool', ['action' => 'totp', '--db' => 'venusep', '--email' => 'admin@gmail.com'])
            ->expectsOutputToContain('Refusing the database')
            ->assertExitCode(1);
        $this->artisan('venusep:flows-tool', ['action' => 'reset', '--db' => 'venusep_laravel'])
            ->expectsOutputToContain('Refusing the database')
            ->assertExitCode(1);
    }

    public function test_db_helper_accepts_only_a_single_select(): void
    {
        $this->assertSame('SELECT 1', FlowsDb::assertSelect('  SELECT 1;  '));
        $this->assertSame('WITH x AS (SELECT 1 AS a) SELECT a FROM x', FlowsDb::assertSelect('WITH x AS (SELECT 1 AS a) SELECT a FROM x'));
        foreach ([
            'DELETE FROM users',
            'UPDATE users SET is_active = 0',
            'DROP TABLE users',
            'SELECT 1; DELETE FROM users',
            '/* SELECT */ DELETE FROM users',
            "SELECT * FROM users INTO OUTFILE 'x'",
            'SELECT * FROM users FOR UPDATE',
            'CALL sp_expire_due_bookings()',
        ] as $sql) {
            try {
                FlowsDb::assertSelect($sql);
                $this->fail("accepted: {$sql}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('db()', $e->getMessage());
            }
        }
    }

    public function test_select_runs_read_only_on_the_test_database(): void
    {
        $db = (string) config('database.connections.' . config('database.default') . '.database');
        $this->assertTrue(FlowsDb::allowed($db), "the test database '{$db}' should be a venusep_laravel_test* copy");
        $this->assertSame([['x' => 2]], FlowsDb::select($db, 'SELECT 1 + ? AS x', [1]));

        /* refused before it reads anything */
        $this->artisan('venusep:flows-tool', ['action' => 'select', '--db' => 'venusep_laravel'])
            ->expectsOutputToContain('Refusing the database')
            ->assertExitCode(1);
    }
}
