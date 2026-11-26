<?php

namespace Tests\Unit\Failover;

use App\Failover\Group;
use PHPUnit\Framework\TestCase;

/* start-venusep.bat's decisions (App\Failover\Group::plan), with the situations of the
   two-laptop test 2026-11-24/25: new addresses every hotspot, a main locked after a restart,
   an old main coming back, two mains. */
class GroupPlanTest extends TestCase
{
    private function r(string $ip, string $name, string $role, string $pos, ?string $of = null): array
    {
        return ['ip' => $ip, 'name' => $name, 'role' => $role, 'pos' => $pos, 'of' => $of, 'reachable' => true];
    }

    public function test_new_addresses_the_locked_main_is_unlocked_and_the_copy_follows_its_new_address(): void
    {
        $plan = Group::plan([
            $this->r('10.96.84.115', 'ARON-PC', 'locked', '0-1-114'),
            $this->r('10.96.84.12', 'RAF-PC', 'copy', '0-1-114', '10.82.16.115'),   // still copying from yesterday's address
        ], ['ARON-PC', 'RAF-PC']);
        $this->assertSame(['10.96.84.115', true, ['10.96.84.12'], []], [$plan['main'], $plan['unlock'], $plan['repoint'], $plan['join']]);
        $this->assertNull($plan['note']);
    }

    public function test_a_laptop_it_knew_is_missing_so_the_main_stays_locked(): void
    {
        $plan = Group::plan([$this->r('10.96.84.115', 'ARON-PC', 'locked', '0-1-114')], ['ARON-PC', 'RAF-PC']);
        $this->assertSame('10.96.84.115', $plan['main']);
        $this->assertFalse($plan['unlock'], 'RAF-PC might be a main somewhere: never two mains');
        $this->assertStringContainsString('Waiting for RAF-PC', $plan['note']);

        $old = Group::plan([$this->r('10.96.84.115', 'ARON-PC', 'locked', '0-1-114')], [], 2);
        $this->assertFalse($old['unlock'], 'a note from before names: as many laptops as it listed');
    }

    public function test_the_laptop_with_older_data_never_becomes_the_main(): void
    {
        /* the old main came back after an emergency switch: the copy that took over is newer */
        $plan = Group::plan([
            $this->r('10.96.84.115', 'ARON-PC', 'locked', '0-1-114'),
            $this->r('10.96.84.12', 'RAF-PC', 'main', '0-2-120'),
        ], ['ARON-PC', 'RAF-PC']);
        $this->assertSame(['10.96.84.12', false, ['10.96.84.115']], [$plan['main'], $plan['unlock'], $plan['join']]);
    }

    public function test_two_mains_change_nothing(): void
    {
        $plan = Group::plan([
            $this->r('10.96.84.115', 'ARON-PC', 'main', '0-1-114'),
            $this->r('10.96.84.12', 'RAF-PC', 'main', '0-2-115'),
        ], ['ARON-PC', 'RAF-PC']);
        $this->assertNull($plan['main']);
        $this->assertStringContainsString('Two laptops take saves', $plan['note']);
    }

    public function test_when_only_a_copy_is_on_nobody_is_unlocked(): void
    {
        $plan = Group::plan([$this->r('10.96.84.12', 'RAF-PC', 'copy', '0-1-114', '10.82.16.115')], ['ARON-PC', 'RAF-PC']);
        $this->assertNull($plan['main']);
        $this->assertStringContainsString('No main on this hotspot', $plan['note']);
    }
}
